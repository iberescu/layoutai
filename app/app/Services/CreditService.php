<?php

namespace App\Services;

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

    public function debit(Workspace $workspace, int $cents, string $description, ?int $campaignId = null): CreditLedgerEntry
    {
        return $workspace->creditLedger()->create([
            'amount_cents' => -abs($cents),
            'type'         => 'spend',
            'description'  => $description,
            'campaign_id'  => $campaignId,
        ]);
    }
}
