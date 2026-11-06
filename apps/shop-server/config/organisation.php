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

];
