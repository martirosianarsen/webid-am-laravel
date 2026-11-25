<?php

return [
    /*
     * The site's connection key, shown once in webid when sign-in is turned on (or a new key is made). It holds
     * webid's address, the site's client_id and its secret.
     */
    'key' => env('WEBID_KEY'),

    /*
     * webid's API (the places list). Defaults to webid's address with "api." in front (https://api.webid.am).
     */
    'api_url' => env('WEBID_API_URL'),

    /*
     * Where the package listens. The callback must be one of the site's redirect addresses in webid; by default webid
     * registers the site's own address at /webid/callback.
     */
    'routes' => [
        'prefix' => 'webid',
        'middleware' => ['web'],
    ],

    /*
     * The site's users: the model, and the column that keeps each user's webid id (see the published migration).
     * A user who signs in for the first time is linked to an existing user with the same confirmed e-mail address
     * (link_by_email), or created.
     */
    'users' => [
        'model' => env('WEBID_USER_MODEL', 'App\\Models\\User'),
        'column' => 'webid_sub',
        'link_by_email' => true,
        'remember' => true,
    ],

    /*
     * Where users go after signing in (unless they were on their way to a page) and after signing out.
     */
    'home' => '/',
];
