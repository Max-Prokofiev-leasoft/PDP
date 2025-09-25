<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdpSkill extends Model
{
    use HasFactory;

    protected $fillable = [
        'pdp_id',
        'skill',
        'description',
        'criteria',
        'priority',
        'eta',
        'status',
        'order_column',
    ];

    public function pdp(): BelongsTo
    {
        return $this->belongsTo(Pdp::class);
    }
}
