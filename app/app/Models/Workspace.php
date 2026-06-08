<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = ['name', 'slug', 'owner_id', 'settings', 'is_premium', 'premium_until'];

    protected $casts = [
        'settings'      => 'array',
        'is_premium'    => 'boolean',
        'premium_until' => 'datetime',
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

    /** Premium workspaces are exempt from the monthly free-credit cap. */
    public function isPremium(): bool
    {
        if (! $this->is_premium) {
            return false;
        }
        // An optional expiry lets premium lapse back to the free cap.
        return $this->premium_until === null || $this->premium_until->isFuture();
    }

    /** The monthly free-credit cap in cents, or null if uncapped (premium / disabled). */
    public function monthlyFreeCapCents(): ?int
    {
        $cap = (int) config('billing.free_monthly_cap_cents', 0);
        if ($this->isPremium() || $cap <= 0) {
            return null;
        }
        return $cap;
    }

    /**
     * Free (promotional) credit spent in the current calendar month, in cents
     * (positive). "Spend" ledger entries are negative; we sum their magnitude.
     */
    public function freeCreditSpentThisMonthCents(): int
    {
        $spent = (int) $this->creditLedger()
            ->where('type', 'spend')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount_cents');

        return abs($spent);
    }

    /** Free credit still spendable this month, or null if uncapped. */
    public function freeCreditRemainingThisMonthCents(): ?int
    {
        $cap = $this->monthlyFreeCapCents();
        if ($cap === null) {
            return null;
        }
        return max(0, $cap - $this->freeCreditSpentThisMonthCents());
    }
}
