<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Free credit
    |--------------------------------------------------------------------------
    |
    | Non-premium workspaces may spend at most this much FREE (promotional)
    | credit per calendar month. Premium workspaces are uncapped. Set to 0 or
    | null to disable the cap entirely.
    |
    */

    'free_monthly_cap_cents' => (int) env('FREE_MONTHLY_CAP_CENTS', 5000), // $50

];
