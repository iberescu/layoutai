<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = ['name', 'slug', 'owner_id', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function brandProfiles(): HasMany
    {
        return $this->hasMany(BrandProfile::class);
    }

    public function creditLedger(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    public function creditBalanceCents(): int
    {
        return (int) $this->creditLedger()->sum('amount_cents');
    }
}
