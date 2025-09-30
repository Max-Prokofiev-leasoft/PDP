<?php

namespace App\Services;

use App\Models\Pdp;
use App\Models\PdpSkill;
use App\Models\PdpSkillCriterionProgress;
use App\Repositories\PdpSkillCriterionProgressRepository;
use App\Repositories\PdpSkillRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PdpSkillService
{
    private PdpSkillRepository $skillRepo;
    private PdpSkillCriterionProgressRepository $progressRepo;

    public function __construct(
        PdpSkillRepository $skillRepo,
        PdpSkillCriterionProgressRepository $progressRepo
    ) {
        $this->skillRepo = $skillRepo;
        $this->progressRepo = $progressRepo;
    }

    // Public API

    public function getSkills(Pdp $pdp): Collection
    {
        return $this->skillRepo->getByPdp($pdp);
    }

    public function createSkill(Pdp $pdp, array $data): PdpSkill
    {
        return $this->skillRepo->createForPdp($pdp, $data);
    }

    public function updateSkill(PdpSkill $skill, array $data): PdpSkill
    {
        return $this->skillRepo->update($skill, $data);
    }

    public function deleteSkill(PdpSkill $skill): void
    {
        $this->skillRepo->delete($skill);
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

        $entries = $this->progressRepo->listBySkillAndIndexWithUser($skill->id, $index);

        return [
            'criterion' => $items[$index] ?? null,
            'entries' => $entries,
        ];
    }

    public function addProgress(PdpSkill $skill, int $index, int $userId, string $note): PdpSkillCriterionProgress
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        return $this->progressRepo->create($skill->id, $index, $userId, $note);
    }

    public function deleteProgress(PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry): void
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            abort(403, 'Entry does not belong to the specified criterion');
        }
        $this->progressRepo->delete($entry);
    }

    public function approveProgress(PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry): PdpSkillCriterionProgress
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            abort(403, 'Entry does not belong to the specified criterion');
        }
        return $this->progressRepo->approve($entry);
    }

    public function setProgressCuratorComment(PdpSkill $skill, int $index, PdpSkillCriterionProgress $entry, ?string $comment): PdpSkillCriterionProgress
    {
        $items = $this->parseCriteriaItems((string)($skill->criteria ?? ''));
        $this->assertIndex($index, $items);

        if ($entry->pdp_skill_id !== $skill->id || $entry->criterion_index !== $index) {
            abort(403, 'Entry does not belong to the specified criterion');
        }

        return $this->progressRepo->setCuratorComment($entry, $comment);
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

        return $this->progressRepo->updateNote($entry, $note);
    }

    public function buildAnnex(Pdp $pdp): array
    {
        $skills = $this->skillRepo->getSkillsOrderedForAnnex($pdp);
        $out = [];
        foreach ($skills as $s) {
            $items = $this->parseCriteriaItems((string)($s->criteria ?? ''));
            $criteria = [];
            foreach ($items as $i => $item) {
                $entries = $this->progressRepo->approvedEntriesBySkillAndIndex((int)$s->id, (int)$i);
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
        $rows = $this->progressRepo->pendingApprovalsForCurator($curatorUserId);

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
        $skills = $this->skillRepo->getSkillsForSummary($pdp);
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
                $skillApproved = $this->progressRepo->distinctApprovedCountBySkill((int)$s->id);
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

        $approvedDistinct = $this->progressRepo->approvedDistinctCountByPdp((int)$pdp->id);

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

        $rows = $this->progressRepo->approvedByPdpBetweenDates((int)$pdp->id, $start->format('Y-m-d 00:00:00'), $endExclusive->format('Y-m-d 00:00:00'));

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
