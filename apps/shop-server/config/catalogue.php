<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Starter Catalogue
    |--------------------------------------------------------------------------
    |
    | The JSON seed, the only master for catalogue data (R8). In the repository
    | it is docs/catalogue/starter-catalogue-v1.1.json; the containers mount it
    | read-only at /catalogue, and the shop image carries a copy.
    |
    */

    'seed' => env('CATALOGUE_SEED', base_path('../../docs/catalogue/starter-catalogue-v1.1.json')),

    /** The seed's "No brand": it prints nothing (C3). */
    'no_brand_seed_id' => 'brand.no-brand',

];
