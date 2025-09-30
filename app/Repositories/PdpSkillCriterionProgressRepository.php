<?php

namespace App\Repositories;

use App\Models\PdpSkillCriterionProgress;
use Illuminate\Support\Collection as SupportCollection;

class PdpSkillCriterionProgressRepository
{
    public function listBySkillAndIndexWithUser(int $skillId, int $index)
    {
        return PdpSkillCriterionProgress::query()
            ->where('pdp_skill_id', $skillId)
            ->where('criterion_index', $index)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();
    }

    public function approvedEntriesBySkillAndIndex(int $skillId, int $index)
    {
        return PdpSkillCriterionProgress::query()
            ->where('pdp_skill_id', $skillId)
            ->where('criterion_index', $index)
            ->where('approved', true)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();
    }

    public function create(int $skillId, int $index, int $userId, string $note): PdpSkillCriterionProgress
    {
        return PdpSkillCriterionProgress::create([
            'pdp_skill_id' => $skillId,
            'criterion_index' => $index,
            'user_id' => $userId,
            'note' => $note,
            'approved' => false,
        ]);
    }

    public function delete(PdpSkillCriterionProgress $entry): void
    {
        $entry->delete();
    }

    public function approve(PdpSkillCriterionProgress $entry): PdpSkillCriterionProgress
    {
        $entry->approved = true;
        $entry->save();
        return $entry->fresh()->load('user:id,name,email');
    }

    public function setCuratorComment(PdpSkillCriterionProgress $entry, ?string $comment): PdpSkillCriterionProgress
    {
        $entry->curator_comment = ($comment !== null && $comment !== '') ? $comment : null;
        $entry->save();
        return $entry->fresh()->load('user:id,name,email');
    }

    public function updateNote(PdpSkillCriterionProgress $entry, string $note): PdpSkillCriterionProgress
    {
        $entry->note = $note;
        $entry->save();
        return $entry->fresh()->load('user:id,name,email');
    }

    public function distinctApprovedCountBySkill(int $skillId): int
    {
        return (int) PdpSkillCriterionProgress::query()
            ->where('pdp_skill_id', $skillId)
            ->where('approved', true)
            ->distinct()
            ->get(['criterion_index'])
            ->count();
    }

    public function approvedDistinctCountByPdp(int $pdpId): int
    {
        return (int) PdpSkillCriterionProgress::query()
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->where('pdp_skills.pdp_id', $pdpId)
            ->where('pdp_skill_criterion_progress.approved', true)
            ->distinct()
            ->get(['pdp_skill_criterion_progress.pdp_skill_id', 'pdp_skill_criterion_progress.criterion_index'])
            ->count();
    }

    public function approvedByPdpBetweenDates(int $pdpId, string $startInclusive, string $endExclusive)
    {
        return PdpSkillCriterionProgress::query()
            ->join('pdp_skills', 'pdp_skills.id', '=', 'pdp_skill_criterion_progress.pdp_skill_id')
            ->where('pdp_skills.pdp_id', $pdpId)
            ->where('pdp_skill_criterion_progress.approved', true)
            ->whereBetween('pdp_skill_criterion_progress.updated_at', [
                $startInclusive,
                $endExclusive,
            ])
            ->selectRaw('date(pdp_skill_criterion_progress.updated_at) as d, count(*) as c')
            ->groupBy('d')
            ->orderBy('d')
            ->get();
    }

    public function pendingApprovalsForCurator(int $curatorUserId)
    {
        return PdpSkillCriterionProgress::query()
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
            ->orderBy('pdp_skill_criterion_progress.created_at')
            ->limit(100)
            ->get();
    }
}
