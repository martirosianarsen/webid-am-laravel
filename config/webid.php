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
     * webid's chat (the widget script). Defaults to webid's address with "chat." in front (https://chat.webid.am).
     */
    'chat_url' => env('WEBID_CHAT_URL'),

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
     * A person who signs in for the first time becomes a new user. Someone who already has an account here links it
     * themselves while signed in to it: <x-webid::button connect /> (/webid/connect). An equal e-mail address never
     * links accounts, since the site's records cannot prove whose address it is.
     */
    'users' => [
        'model' => env('WEBID_USER_MODEL', 'App\\Models\\User'),
        'column' => 'webid_sub',
        'remember' => true,
    ],

    /*
     * Orders in the customer's chat (Webid\Laravel\Orders): the site's own order statuses, mapped onto webid's stages
     * (accepted, preparing, on_the_way, delivered, cancelled). A status left out is not sent; webid's own stage names
     * need no mapping. For example: 'pending' => 'accepted', 'processing' => 'preparing', 'shipped' => 'on_the_way',
     * 'completed' => 'delivered', 'refunded' => 'cancelled'.
     */
    'orders' => [
        'stages' => [],
    ],

    /*
     * Where users go after signing in (unless they were on their way to a page) and after signing out.
     */
    'home' => '/',
];
