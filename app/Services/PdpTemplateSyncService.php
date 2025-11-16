<?php

namespace App\Services;

use App\Models\Pdp;
use App\Models\PdpSkill;
use App\Models\PdpTemplate;

class PdpTemplateSyncService
{
    /**
     * Parse criteria JSON into a normalized array of items:
     * [ ['text'=>string, 'done'=>bool, 'comment'=>?string], ... ]
     */
    private function parseCriteria(?string $json): array
    {
        if (!$json) return [];
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $it) {
            if (!is_array($it)) continue;
            $text = trim((string)($it['text'] ?? ''));
            if ($text === '') continue;
            $out[] = [
                'text' => $text,
                'done' => (bool)($it['done'] ?? false),
                'comment' => isset($it['comment']) && $it['comment'] !== '' ? (string)$it['comment'] : null,
            ];
        }
        return $out;
    }

    /**
     * Merge template criteria with existing criteria, preserving per-item progress
     * (done + comment) by matching items via trimmed text. Order follows template.
     */
    private function mergeCriteriaPreserveProgress(?string $existingJson, ?string $templateJson): string
    {
        $existing = $this->parseCriteria($existingJson);
        $template = $this->parseCriteria($templateJson);

        // Build map from text to progress state for existing items
        $progressByText = [];
        foreach ($existing as $it) {
            $key = mb_strtolower(trim($it['text']));
            if ($key === '') continue;
            $progressByText[$key] = [
                'done' => (bool)$it['done'],
                'comment' => $it['comment'] ?? null,
            ];
        }

        $merged = [];
        foreach ($template as $it) {
            $key = mb_strtolower(trim($it['text']));
            $state = $progressByText[$key] ?? ['done' => false, 'comment' => null];
            $merged[] = [
                'text' => $it['text'],
                'done' => (bool)$state['done'],
                'comment' => $state['comment'] ?? null,
            ];
        }

        return json_encode($merged, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Synchronize all non-finalized PDPs based on the given template:
     * - Add new skills that appear in the template but are missing in PDP
     * - Remove skills that were removed from the template, but only if they are not manually overridden
     * - Do not touch skills with is_manual_override = true
     * - Do not touch finalized PDPs (status = Done)
     */
    public function sync(PdpTemplate $template): void
    {
        $data = (array) $template->data;
        $skills = (array) ($data['skills'] ?? []);

        // Build a map of template skills by stable key
        $templateMap = [];
        $templateOrder = [];
        $templateKeys = [];
        foreach ($skills as $idx => $s) {
            $key = (string)($s['key'] ?? ('idx-' . $idx));
            $templateMap[$key] = $s;
            $templateOrder[$key] = $s['order_column'] ?? $idx;
            $templateKeys[] = $key;
        }

        // Process all PDPs linked to this template OR containing at least one skill from this template by key
        // Skip finalized PDPs later per-item
        $pdps = Pdp::query()
            ->where('template_id', $template->id)
            ->orWhereHas('skills', function ($q) use ($templateKeys) {
                if (!empty($templateKeys)) {
                    $q->whereIn('template_skill_key', $templateKeys);
                } else {
                    // If template has no skills, there is nothing to sync for unrelated PDPs
                    $q->whereRaw('1 = 0');
                }
            })
            ->get();
        foreach ($pdps as $pdp) {
            if ($pdp->isFinalized()) continue;

            $existing = $pdp->skills()->get();

            // Index existing by template_skill_key
            $byKey = [];
            foreach ($existing as $sk) {
                if ($sk->template_skill_key) {
                    $byKey[$sk->template_skill_key] = $sk;
                }
            }

            // Update existing skills from template if they are not manually overridden
            foreach ($templateMap as $key => $s) {
                if (!isset($byKey[$key])) continue;
                $sk = $byKey[$key];
                if ($sk->is_manual_override) continue;

                $updates = [];
                $newSkill = (string)($s['skill'] ?? (string)$sk->skill);
                if ($sk->skill !== $newSkill) { $updates['skill'] = $newSkill; }

                $newDescription = $s['description'] ?? null;
                if ($sk->description !== $newDescription) { $updates['description'] = $newDescription; }

                // Merge criteria preserving existing progress (done/comment) by text
                $mergedCriteria = $this->mergeCriteriaPreserveProgress($sk->criteria, $s['criteria'] ?? null);
                if ((string)$sk->criteria !== $mergedCriteria) { $updates['criteria'] = $mergedCriteria; }

                $newPriority = (string)($s['priority'] ?? 'Medium');
                if ($sk->priority !== $newPriority) { $updates['priority'] = $newPriority; }

                // Do not reset user's progress-related timeline/status on sync
                // Keep ETA and Status as-is to avoid progress reset

                $newOrder = $templateOrder[$key] ?? $sk->order_column;
                if ($sk->order_column !== $newOrder) { $updates['order_column'] = $newOrder; }

                if (!empty($updates)) {
                    $sk->fill($updates);
                    $sk->save();
                }
            }

            // Add new skills (missing keys)
            foreach ($templateMap as $key => $s) {
                if (!isset($byKey[$key])) {
                    PdpSkill::create([
                        'pdp_id' => $pdp->id,
                        'skill' => (string)$s['skill'],
                        'description' => $s['description'] ?? null,
                        'criteria' => $s['criteria'] ?? null,
                        'priority' => (string)($s['priority'] ?? 'Medium'),
                        'eta' => $s['eta'] ?? null,
                        'status' => (string)($s['status'] ?? 'Planned'),
                        'order_column' => $templateOrder[$key] ?? ($pdp->skills()->max('order_column') + 1),
                        'template_skill_key' => $key,
                        'is_manual_override' => false,
                    ]);
                }
            }

            // Remove skills that were deleted from template (only non-overridden)
            // IMPORTANT: To avoid deleting skills originating from other templates in mixed PDPs,
            // we only perform deletions for PDPs that are explicitly linked to this template via template_id.
            if ((int)$pdp->template_id === (int)$template->id) {
                foreach ($existing as $sk) {
                    $k = $sk->template_skill_key;
                    if ($k && !isset($templateMap[$k]) && !$sk->is_manual_override) {
                        $sk->delete();
                    }
                }
            }
        }
    }
}
