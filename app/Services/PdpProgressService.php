<?php

namespace App\Services;

use App\Models\Pdp;

class PdpProgressService
{
    /**
     * Returns progress for a PDP based on closed skills.
     * A skill is considered closed when status === 'Done'.
     */
    public function forPdp(Pdp $pdp): array
    {
        $total = $pdp->skills()->count();
        $completed = $pdp->skills()->where('status', 'Done')->count();
        $percent = $total > 0 ? (int) floor(($completed / $total) * 100) : 0;

        return [
            'total' => (int) $total,
            'completed' => (int) $completed,
            'percent' => $percent,
        ];
    }
}
