<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdpSkillCriterionProgress extends Model
{
    use HasFactory;

    protected $table = 'pdp_skill_criterion_progress';

    protected $fillable = [
        'pdp_skill_id',
        'criterion_index',
        'user_id',
        'note',
        'approved',
        'curator_comment',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(PdpSkill::class, 'pdp_skill_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
