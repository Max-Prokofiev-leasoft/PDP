<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

class Pdp extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'priority',
        'eta',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(PdpSkill::class)->orderBy('order_column');
    }

    public function curators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pdp_curators', 'pdp_id', 'user_id')->withTimestamps();
    }

    public function isAccessibleBy(AuthenticatableContract $user): bool
    {
        if ($this->user_id === $user->getAuthIdentifier()) return true;
        return $this->curators()->where('user_id', $user->getAuthIdentifier())->exists();
    }
}
