<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report secret
    |--------------------------------------------------------------------------
    |
    | The secret shown on the Uptimistic monitor. Requests are signed with it;
    | without a secret of at least 32 characters the report answers nobody.
    |
    */

    'secret' => env('UPTIMISTIC_REPORT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Path
    |--------------------------------------------------------------------------
    |
    | Where the report answers. The monitor's URL is the app's address
    | followed by this path.
    |
    */

    'path' => env('UPTIMISTIC_REPORT_PATH', 'uptimistic/report'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Runs before the signature is checked. The route stays out of the web
    | group, so it starts no session and sets no cookies.
    |
    */

    'middleware' => ['throttle:10,1'],

    /*
    |--------------------------------------------------------------------------
    | Tolerance
    |--------------------------------------------------------------------------
    |
    | How many seconds a signed request stays valid; the server clocks may be
    | this far apart. Each signature is accepted once.
    |
    */

    'tolerance' => 300,

    /*
    |--------------------------------------------------------------------------
    | npm packages
    |--------------------------------------------------------------------------
    |
    | Every package in package.json's "dependencies" is reported. From
    | "devDependencies" only these, which end up in the built front end;
    | build tools are left out so their advisories do not count against the
    | app.
    |
    */

    'npm' => [
        'dev_dependencies' => [
            '@angular/core',
            '@inertiajs/react',
            '@inertiajs/svelte',
            '@inertiajs/vue3',
            'alpinejs',
            'bootstrap',
            'jquery',
            'next',
            'nuxt',
            'react',
            'react-dom',
            'svelte',
            'tailwindcss',
            'vue',
        ],
    ],

];
