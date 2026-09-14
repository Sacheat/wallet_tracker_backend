<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // register/login/logout/user live outside /api (routes/web.php, session-based
    // auth) but still need CORS since the frontend calls them cross-origin.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'register', 'login', 'logout', 'user'],

    'allowed_methods' => ['*'],

    // Sanctum's cookie-based SPA auth requires the browser to send credentials
    // (cookies), and browsers reject a wildcard origin ("*") on credentialed
    // requests — so this must be an explicit list, not '*'.
    'allowed_origins' => explode(',', env('FRONTEND_URLS', 'http://localhost:5173')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
