<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Branch Key
    |--------------------------------------------------------------------------
    |
    | Where the branch's signing key lives (spec section 4) [S19]. It never
    | leaves the server. Tests point it elsewhere.
    |
    */

    'key_dir' => env('BRANCH_KEY_DIR', storage_path('app/keys')),

    /*
    |--------------------------------------------------------------------------
    | Certificate Authority
    |--------------------------------------------------------------------------
    |
    | Where the shop's local certificate authority lives (spec section 4)
    | [F24]. It signs the server's HTTPS certificate; every counter trusts it
    | once. A copy is kept beside the backups, so a restore elsewhere keeps it
    | (D69). Tests point it elsewhere.
    |
    */

    'ca_dir' => env('SHOP_CA_DIR', storage_path('app/ca')),

];
