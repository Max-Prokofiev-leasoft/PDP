<?php

namespace App\Http\Controllers;

use App\Models\Pdp;
use App\Models\PdpSkill;
use App\Models\PdpSkillCriterionProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdpSkillController extends Controller
{
    protected function authorizePdp(Request $request, Pdp $pdp): void
    {
        abort_unless($pdp->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }

    public function index(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);
        return response()->json($pdp->skills()->get());
    }

    public function store(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);
        $data = $request->validate([
            'skill' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'criteria' => ['nullable','string'],
            'priority' => ['required','in:Low,Medium,High'],
            'eta' => ['nullable','string','max:255'],
            'status' => ['required','in:Planned,In Progress,Done,Blocked'],
            'order_column' => ['nullable','integer','min:0'],
        ]);
        $skill = $pdp->skills()->create($data + [
            'order_column' => $data['order_column'] ?? ($pdp->skills()->max('order_column') + 1),
        ]);
        return response()->json($skill, Response::HTTP_CREATED);
    }

    public function update(Request $request, Pdp $pdp, PdpSkill $skill)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'skill' => ['sometimes','required','string','max:255'],
            'description' => ['nullable','string'],
            'criteria' => ['nullable','string'],
            'priority' => ['sometimes','required','in:Low,Medium,High'],
            'eta' => ['nullable','string','max:255'],
            'status' => ['sometimes','required','in:Planned,In Progress,Done,Blocked'],
            'order_column' => ['nullable','integer','min:0'],
        ]);
        $skill->update($data);
        return response()->json($skill);
    }

    public function updateCriterionComment(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        $payload = $request->validate([
            'comment' => ['nullable','string'],
        ]);

        $raw = (string)($skill->criteria ?? '');
        $items = [];
        // Try parse JSON new format
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                foreach ($parsed as $it) {
                    if (is_string($it)) {
                        $items[] = ['text' => trim($it)];
                    } elseif (is_array($it)) {
                        $text = isset($it['text']) ? trim((string)$it['text']) : '';
                        if ($text !== '') {
                            $comment = isset($it['comment']) && trim((string)$it['comment']) !== '' ? (string)$it['comment'] : null;
                            $items[] = ['text' => $text, 'comment' => $comment];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // legacy format or invalid JSON -> fallback below
        }
        if (empty($items)) {
            // Legacy: split by new lines / commas / semicolons
            $parts = array_filter(array_map('trim', preg_split('/[\n,;]+/', $raw) ?: []));
            foreach ($parts as $t) {
                $items[] = ['text' => $t];
            }
        }

        if ($index < 0 || $index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items[$index]['comment'] = isset($payload['comment']) && $payload['comment'] !== '' ? $payload['comment'] : null;

        $skill->criteria = json_encode($items, JSON_UNESCAPED_UNICODE);
        $skill->save();

        return response()->json($skill->refresh());
    }

    public function destroy(Request $request, Pdp $pdp, PdpSkill $skill)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);
        $skill->delete();
        return response()->noContent();
    }

    // Progress: list entries for a criterion
    public function listProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        if ($index < 0) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        if ($index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entries = PdpSkillCriterionProgress::query()
            ->where('pdp_skill_id', $skill->id)
            ->where('criterion_index', $index)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'criterion' => $items[$index] ?? null,
            'entries' => $entries,
        ]);
    }

    // Progress: add entry
    public function addProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'note' => ['required','string'],
        ]);

        if ($index < 0) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        if ($index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entry = PdpSkillCriterionProgress::create([
            'pdp_skill_id' => $skill->id,
            'criterion_index' => $index,
            'user_id' => $request->user()->id,
            'note' => $data['note'],
            'approved' => false,
        ]);

        return response()->json($entry->load('user:id,name,email'), Response::HTTP_CREATED);
    }

    public function deleteProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        // Validate index exists in current criteria set
        if ($index < 0) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        if ($index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Ensure the progress entry belongs to this skill and criterion
        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            return response()->json(['message' => 'Entry does not belong to the specified criterion'], Response::HTTP_FORBIDDEN);
        }

        $entry->delete();
        return response()->noContent();
    }

    public function approveProgress(Request $request, Pdp $pdp, PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry)
    {
        $this->authorizePdp($request, $pdp);
        abort_unless($skill->pdp_id === $pdp->id, Response::HTTP_FORBIDDEN);

        // Validate criterion index exists
        if ($index < 0) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        if ($index >= count($items)) {
            return response()->json(['message' => 'Invalid criterion index'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Ensure the entry belongs to this skill and criterion
        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            return response()->json(['message' => 'Entry does not belong to the specified criterion'], Response::HTTP_FORBIDDEN);
        }

        $entry->approved = true;
        $entry->save();

        return response()->json($entry->fresh()->load('user:id,name,email'));
    }

    // Annex: document-like data with only approved progress entries
    public function annex(Request $request, Pdp $pdp)
    {
        $this->authorizePdp($request, $pdp);

        $skills = $pdp->skills()->orderBy('order_column')->orderBy('id')->get();
        $out = [];
        foreach ($skills as $s) {
            $items = $this->parseCriteriaItems((string)($s->criteria ?? ''));
            $criteria = [];
            foreach ($items as $i => $item) {
                $entries = PdpSkillCriterionProgress::query()
                    ->where('pdp_skill_id', $s->id)
                    ->where('criterion_index', $i)
                    ->where('approved', true)
                    ->with('user:id,name,email')
                    ->orderBy('created_at')
                    ->get();
                $criteria[] = [
                    'index' => $i,
                    'text' => $item['text'] ?? '',
                    'comment' => $item['comment'] ?? null,
                    'entries' => $entries,
                ];
            }
            $out[] = [
                'id' => $s->id,
                'skill' => $s->skill,
                'description' => $s->description,
                'criteria' => $criteria,
                'priority' => $s->priority,
                'eta' => $s->eta,
                'status' => $s->status,
            ];
        }

        return response()->json([
            'pdp' => $pdp->only(['id','title','description','priority','eta','status']),
            'skills' => $out,
        ]);
    }

    // Helper to parse criteria consistently
    protected function parseCriteriaItems(string $raw): array
    {
        $items = [];
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                foreach ($parsed as $it) {
                    if (is_string($it)) {
                        $items[] = ['text' => trim($it)];
                    } elseif (is_array($it)) {
                        $text = isset($it['text']) ? trim((string)$it['text']) : '';
                        if ($text !== '') {
                            $comment = isset($it['comment']) && trim((string)$it['comment']) !== '' ? (string)$it['comment'] : null;
                            $items[] = ['text' => $text, 'comment' => $comment];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        if (empty($items)) {
            $parts = array_filter(array_map('trim', preg_split('/[\n,;]+/', $raw) ?: []));
            foreach ($parts as $t) {
                $items[] = ['text' => $t];
            }
        }
        return $items;
    }
}
