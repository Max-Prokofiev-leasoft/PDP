<?php

namespace App\Services;

use App\Models\Pdp;
use App\Models\PdpSkill;
use App\Models\PdpSkillCriterionProgress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PdpSkillService
{
    // Public API

    public function getSkills(Pdp $pdp): Collection
    {
        return $pdp->skills()->get();
    }

    public function createSkill(Pdp $pdp, array $data): PdpSkill
    {
        $order = $data['order_column'] ?? ($pdp->skills()->max('order_column') + 1);
        return $pdp->skills()->create($data + ['order_column' => $order]);
    }

    public function updateSkill(PdpSkill $skill, array $data): PdpSkill
    {
        $skill->update($data);
        return $skill;
    }

    public function deleteSkill(PdpSkill $skill): void
    {
        $skill->delete();
    }

    public function updateCriterionComment(PdpSkill $skill, int $index, ?string $comment): PdpSkill
    {
        $items = $this->parseCriteriaItemsWithDone((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);
        $items[$index]['comment'] = ($comment !== null && $comment !== '') ? $comment : null;
        $skill->criteria = json_encode($items, JSON_UNESCAPED_UNICODE);
        $skill->save();
        return $skill->refresh();
    }

    public function updateCriterionDone(PdpSkill $skill, int $index, bool $done): PdpSkill
    {
        $items = $this->parseCriteriaItemsWithDone((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);
        $items[$index]['done'] = (bool)$done;

        // Auto-update status based on all criteria completion (only if there is at least one criterion)
        $allDone = !empty($items);
        if ($allDone) {
            foreach ($items as $it) {
                if (empty($it['done'])) { $allDone = false; break; }
            }
            $skill->status = $allDone ? 'Done' : 'In Progress';
        }

        $skill->criteria = json_encode($items, JSON_UNESCAPED_UNICODE);
        $skill->save();
        return $skill->refresh();
    }

    public function listProgress(PdpSkill $skill, int $index): array
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        $entries = PdpSkillCriterionProgress::query()
            ->where('pdp_skill_id', $skill->id)
            ->where('criterion_index', $index)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();

        return [
            'criterion' => $items[$index] ?? null,
            'entries' => $entries,
        ];
    }

    public function addProgress(PdpSkill $skill, int $index, int $userId, string $note): PdpSkillCriterionProgress
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        return PdpSkillCriterionProgress::create([
            'pdp_skill_id' => $skill->id,
            'criterion_index' => $index,
            'user_id' => $userId,
            'note' => $note,
            'approved' => false,
        ]);
    }

    public function deleteProgress(PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry): void
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            abort(403, 'Entry does not belong to the specified criterion');
        }
        $entry->delete();
    }

    public function approveProgress(PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry): PdpSkillCriterionProgress
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            abort(403, 'Entry does not belong to the specified criterion');
        }
        $entry->approved = true;
        $entry->save();
        return $entry->fresh()->load('user:id,name,email');
    }

    public function setProgressCuratorComment(PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry, ?string $comment): PdpSkillCriterionProgress
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            abort(403, 'Entry does not belong to the specified criterion');
        }

        $entry->curator_comment = ($comment !== null && $comment !== '') ? $comment : null;
        $entry->save();
        return $entry->fresh()->load('user:id,name,email');
    }

    public function updateProgressNote(PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry, string $note): PdpSkillCriterionProgress
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            abort(403, 'Entry does not belong to the specified criterion');
        }
        if ($entry->approved) {
            abort(422, 'Approved entry cannot be edited');
        }

        $entry->note = $note;
        $entry->save();
        return $entry->fresh()->load('user:id,name,email');
    }

    public function buildAnnex(Pdp $pdp): array
    {
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

        return [
            'pdp' => $pdp->only(['id','title','description','priority','eta','status']),
            'skills' => $out,
        ];
    }

    public function pendingApprovals(int $curatorUserId): array
    {
        $query = PdpSkillCriterionProgress::query()
            ->where('approved', false)
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->join('pdps', 'pdps.id', '=', 'pdp_skills.pdp_id')
            ->join('pdp_curators', function ($j) use ($curatorUserId) {
                $j->on('pdp_curators.pdp_id', '=', 'pdps.id')
                  ->where('pdp_curators.user_id', '=', $curatorUserId);
            })
            ->leftJoin('users', 'users.id', '=', 'pdps.user_id')
            ->select([
                'pdp_skill_criterion_progress.id as id',
                'pdp_skill_criterion_progress.pdp_skill_id as skill_id',
                'pdp_skill_criterion_progress.criterion_index as criterion_index',
                'pdp_skill_criterion_progress.user_id as author_id',
                'pdp_skill_criterion_progress.note as note',
                'pdp_skill_criterion_progress.created_at as created_at',
                'pdp_skills.pdp_id as pdp_id',
                'pdp_skills.skill as skill',
                'pdp_skills.criteria as criteria',
                'pdps.title as pdp_title',
                'users.id as owner_id',
                'users.name as owner_name',
                'users.email as owner_email',
            ])
            ->orderBy('pdp_skill_criterion_progress.created_at');

        $rows = $query->limit(100)->get();

        $out = [];
        foreach ($rows as $row) {
            $criterionText = '';
            $items = $this->parseCriteriaItems((string)($row->criteria ?? ''));
            $idx = (int)$row->criterion_index;
            if ($idx >= 0 && $idx < count($items)) {
                $criterionText = (string)($items[$idx]['text'] ?? '');
            }

            $out[] = [
                'id' => (int)$row->id,
                'pdp' => [
                    'id' => (int)$row->pdp_id,
                    'title' => (string)$row->pdp_title,
                ],
                'skill' => [
                    'id' => (int)$row->skill_id,
                    'name' => (string)$row->skill,
                ],
                'criterion' => [
                    'index' => $idx,
                    'text' => $criterionText,
                ],
                'note' => (string)$row->note,
                'created_at' => (string)$row->created_at,
                'owner' => [
                    'id' => (int)($row->owner_id ?? 0),
                    'name' => $row->owner_name,
                    'email' => $row->owner_email,
                ],
            ];
        }

        return $out;
    }

    public function buildSummary(Pdp $pdp): array
    {
        $skills = $pdp->skills()->get(['id','skill','criteria']);
        $totalCriteria = 0;
        $doneAware = false;
        $doneCount = 0;
        $skillsOut = [];
        $skillCriteriaCounts = [];
        foreach ($skills as $s) {
            $items = $this->parseCriteriaItems((string)($s->criteria ?? ''));
            $skillTotal = count($items);
            $totalCriteria += $skillTotal;
            $skillCriteriaCounts[(int)$s->id] = (int)$skillTotal;

            $skillDoneAware = false;
            $skillDoneCount = 0;
            foreach ($items as $it) {
                if (array_key_exists('done', $it)) {
                    $doneAware = true;
                    $skillDoneAware = true;
                    if (!empty($it['done'])) { $skillDoneCount++; }
                }
            }

            if ($skillDoneAware) {
                $skillApproved = $skillDoneCount;
            } else {
                $skillApproved = (int)PdpSkillCriterionProgress::query()
                    ->where('pdp_skill_id', $s->id)
                    ->where('approved', true)
                    ->distinct()
                    ->get(['criterion_index'])
                    ->count();
            }

            if ($skillDoneAware) { $doneCount += $skillDoneCount; }

            $skillsOut[] = [
                'id' => (int)$s->id,
                'skill' => (string)$s->skill,
                'totalCriteria' => (int)$skillTotal,
                'approvedCount' => (int)$skillApproved,
                'pendingCount' => max(0, (int)$skillTotal - (int)$skillApproved),
            ];
        }

        $approvedDistinct = PdpSkillCriterionProgress::query()
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->where('pdp_skills.pdp_id', $pdp->id)
            ->where('pdp_skill_criterion_progress.approved', true)
            ->distinct()
            ->get(['pdp_skill_criterion_progress.pdp_skill_id', 'pdp_skill_criterion_progress.criterion_index'])
            ->count();

        if ($doneAware) {
            $approvedCount = (int)$doneCount;
        } else {
            $approvedCount = (int)$approvedDistinct;
        }
        $pendingCount = max(0, (int)$totalCriteria - $approvedCount);

        $tz = new \DateTimeZone(config('app.timezone', 'UTC'));
        $today = new \DateTime('now', $tz);
        $today->setTime(0, 0, 0);
        $start = (clone $today)->modify('-29 days');
        $endExclusive = (clone $today)->modify('+1 day');

        $approvedRows = PdpSkillCriterionProgress::query()
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->where('pdp_skills.pdp_id', $pdp->id)
            ->where('pdp_skill_criterion_progress.approved', true)
            ->whereBetween('pdp_skill_criterion_progress.updated_at', [
                $start->format('Y-m-d 00:00:00'),
                $endExclusive->format('Y-m-d 00:00:00'),
            ])
            ->get(['pdp_skill_criterion_progress.created_at','pdp_skill_criterion_progress.updated_at']);

        $durations = [];
        foreach ($approvedRows as $row) {
            try {
                $created = new \DateTime((string)$row->created_at, $tz);
                $updated = new \DateTime((string)$row->updated_at, $tz);
                $hours = max(0, ($updated->getTimestamp() - $created->getTimestamp()) / 3600);
                $durations[] = $hours;
            } catch (\Throwable $e) {
                // skip invalid rows
            }
        }

        $avgApproveHours = null;
        $medianApproveHours = null;
        if (!empty($durations)) {
            $sum = array_sum($durations);
            $avg = $sum / count($durations);

            sort($durations);
            $n = count($durations);
            if ($n % 2 === 1) {
                $median = $durations[intdiv($n, 2)];
            } else {
                $median = ($durations[$n / 2 - 1] + $durations[$n / 2]) / 2;
            }

            $avgApproveHours = round((float)$avg, 1);
            $medianApproveHours = round((float)$median, 1);
        }

        $rows = PdpSkillCriterionProgress::query()
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->where('pdp_skills.pdp_id', $pdp->id)
            ->where('pdp_skill_criterion_progress.approved', true)
            ->whereBetween('pdp_skill_criterion_progress.updated_at', [
                $start->format('Y-m-d 00:00:00'),
                $endExclusive->format('Y-m-d 00:00:00'),
            ])
            ->selectRaw('date(pdp_skill_criterion_progress.updated_at) as d, count(*) as c')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r->d] = (int)$r->c;
        }

        $wins = [];
        $cursor = clone $start;
        for ($i = 0; $i < 30; $i++) {
            $key = $cursor->format('Y-m-d');
            $wins[] = ['date' => $key, 'count' => $map[$key] ?? 0];
            $cursor->modify('+1 day');
        }


        return [
            'totalCriteria' => (int)$totalCriteria,
            'approvedCount' => $approvedCount,
            'pendingCount' => $pendingCount,
            'avgApproveHours' => $avgApproveHours,
            'medianApproveHours' => $medianApproveHours,
            'wins' => $wins,
            'skills' => $skillsOut,
        ];
    }

    // Parsing helpers
    public function parseCriteriaItems(string $raw): array
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
                            $done = isset($it['done']) ? (bool)$it['done'] : null;
                            $row = ['text' => $text, 'comment' => $comment];
                            if ($done !== null) { $row['done'] = $done; }
                            $items[] = $row;
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

    private function parseCriteriaItemsWithDone(string $raw): array
    {
        $items = [];
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                foreach ($parsed as $it) {
                    if (is_string($it)) {
                        $items[] = ['text' => trim($it), 'done' => false];
                    } elseif (is_array($it)) {
                        $text = isset($it['text']) ? trim((string)$it['text']) : '';
                        if ($text !== '') {
                            $comment = isset($it['comment']) && trim((string)$it['comment']) !== '' ? (string)$it['comment'] : null;
                            $done = isset($it['done']) ? (bool)$it['done'] : false;
                            $items[] = ['text' => $text, 'comment' => $comment, 'done' => $done];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // legacy format or invalid JSON
        }
        if (empty($items)) {
            $parts = array_filter(array_map('trim', preg_split('/[\n,;]+/', $raw) ?: []));
            foreach ($parts as $t) {
                $items[] = ['text' => $t, 'done' => false];
            }
        }
        return $items;
    }

    private function assertIndex(int $index, array $items): void
    {
        if ($index < 0 || $index >= count($items)) {
            abort(422, 'Invalid criterion index');
        }
    }
}
