<?php

namespace App\Services;

use App\Exceptions\FreeCreditLimitException;
use App\Models\CreditLedgerEntry;
use App\Models\Workspace;

class CreditService
{
    public function grantPromotional(Workspace $workspace, int $cents = 50000, int $expiresInDays = 90): CreditLedgerEntry
    {
        return $workspace->creditLedger()->create([
            'amount_cents' => $cents,
            'type'         => 'promotional_grant',
            'description'  => 'Layout.ai $' . number_format($cents / 100, 0) . ' promotional ad credit',
            'expires_at'   => now()->addDays($expiresInDays),
        ]);
    }

    /**
     * Can this workspace spend $cents of free credit right now without breaking
     * the monthly cap? Premium (uncapped) workspaces can always spend.
     */
    public function canSpend(Workspace $workspace, int $cents): bool
    {
        $remaining = $workspace->freeCreditRemainingThisMonthCents();
        return $remaining === null || abs($cents) <= $remaining;
    }

    /**
     * Spend free credit. Non-premium workspaces are capped at the monthly free
     * limit (config billing.free_monthly_cap_cents); exceeding it throws
     * FreeCreditLimitException so callers can prompt an upgrade.
     */
    public function debit(Workspace $workspace, int $cents, string $description, ?int $campaignId = null): CreditLedgerEntry
    {
        $cents = abs($cents);

        if (! $this->canSpend($workspace, $cents)) {
            throw new FreeCreditLimitException(
                (int) $workspace->monthlyFreeCapCents(),
                $workspace->freeCreditSpentThisMonthCents(),
                $cents,
            );
        }

        return $workspace->creditLedger()->create([
            'amount_cents' => -$cents,
            'type'         => 'spend',
            'description'  => $description,
            'campaign_id'  => $campaignId,
        ]);
    }
}
