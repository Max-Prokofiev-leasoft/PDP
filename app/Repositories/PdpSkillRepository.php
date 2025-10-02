<?php

namespace App\Repositories;

use App\Models\Pdp;
use App\Models\PdpSkill;
use Illuminate\Database\Eloquent\Collection;

class PdpSkillRepository
{
    public function getByPdp(Pdp $pdp): Collection
    {
        return $pdp->skills()->get();
    }

    public function getSkillsOrderedForAnnex(Pdp $pdp): Collection
    {
        return $pdp->skills()->orderBy('order_column')->orderBy('id')->get();
    }

    public function getSkillsForSummary(Pdp $pdp): Collection
    {
        return $pdp->skills()->get(['id', 'skill', 'criteria']);
    }

    public function createForPdp(Pdp $pdp, array $data): PdpSkill
    {
        $order = $data['order_column'] ?? ($pdp->skills()->max('order_column') + 1);
        return $pdp->skills()->create($data + ['order_column' => $order]);
    }

    public function update(PdpSkill $skill, array $data): PdpSkill
    {
        $skill->update($data);
        return $skill;
    }

    public function delete(PdpSkill $skill): void
    {
        $skill->delete();
    }
}
