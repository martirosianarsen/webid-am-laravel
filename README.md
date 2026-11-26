# webid for Laravel

Sign in with webid on a Laravel site, from one connection key.

Sign in with webid is free. The other services (Chat, Places) are paid: each works while the business's subscription
to it is active, and is turned on for the site on [business.webid.am](https://business.webid.am) (Services tab).
Guides for every service, with examples in other languages, are on [developers.webid.am](https://developers.webid.am).

## Install

```bash
composer require webid-am/laravel
php artisan vendor:publish --tag=webid-migrations
php artisan migrate
```

Put the connection key in `.env`. It is shown once, when you turn on "Sign in with webid" on the Site tab of
[business.webid.am](https://business.webid.am):

```
WEBID_KEY=wk_…
```

Add the button anywhere. It carries webid's mark and speaks the page language (hy, ru, en); its look is built in, so
it needs no stylesheet (`theme="light"` for light backgrounds, `plain class="…"` to style it yourself):

```blade
<x-webid::button />
<x-webid::button return="/checkout" theme="light" />
```

With the Chat service on for the site (and the business subscribed to it), add the chat to the layout:

```blade
<x-webid::chat />                                {{-- a chat button for customers --}}
<x-webid::chat mode="agent" target="#inbox" />   {{-- the seller's inbox inside #inbox --}}
```

That is all. The package adds:

| Route | What it does |
|---|---|
| `GET /webid/login` | sends the visitor to webid (`?return=/checkout` to come back there) |
| `GET /webid/callback` | brings them back signed in (webid's default redirect address) |
| `GET /webid/token` | the visitor's webid token for the page, e.g. the chat widget (`data-token-url="/webid/token"`) |
| `POST /webid/logout` | signs out here and on webid |

## Users

At sign-in the package finds the site's user by `webid_sub`, else by the same confirmed e-mail address (and links
it), else creates one, and signs them in. The name is updated from webid each time. Everything webid gave the site
arrives in the `Webid\Laravel\Events\SignedIn` event as standard OpenID Connect claims (`$event->claims`: `email`,
`email_verified`, `phone_number`, `preferred_username` (the webid ID), `address`, `locale`…, plus webid's `adult`), to
keep more of it on your user.

If your users need more than a name, an e-mail and a password, create them yourself:

```php
Webid\Laravel\Users::createUsing(fn (array $claims) => User::create([...]));
```

## Places

With the Places service on for the site (and the business subscribed to it; otherwise webid answers 403):

```php
$places = app(Webid\Laravel\Webid::class)->places(['parent' => 'AM-ER']);
$place = app(Webid\Laravel\Webid::class)->place('am-er-kentron');
```

## Settings

`php artisan vendor:publish --tag=webid-config` for `config/webid.php`: the user model and column, the route prefix,
where users go after signing in, and `WEBID_API_URL` / `WEBID_CHAT_URL` if webid's API or chat are not at `api.` /
`chat.` + webid's address.
