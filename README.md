# webid for Laravel

Sign in with webid on a Laravel site, from one connection key.

## Install

```bash
composer require webid-am/laravel
php artisan vendor:publish --tag=webid-migrations
php artisan migrate
```

Put the connection key that webid showed when you turned on "Sign in with webid" in `.env`:

```
WEBID_KEY=wk_…
```

Add the button anywhere:

```blade
<x-webid::button>Մուտք webid-ով</x-webid::button>
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
arrives in the `Webid\Laravel\Events\SignedIn` event (`$event->claims`: `email.address`, `phone.number`, `address`,
`locale`…), to keep more of it on your user.

If your users need more than a name, an e-mail and a password, create them yourself:

```php
Webid\Laravel\Users::createUsing(fn (array $claims) => User::create([...]));
```

## Places

With the "Places" service turned on for the site:

```php
$places = app(Webid\Laravel\Webid::class)->places(['parent' => 'AM-ER']);
$place = app(Webid\Laravel\Webid::class)->place('am-er-kentron');
```

## Settings

`php artisan vendor:publish --tag=webid-config` for `config/webid.php`: the user model and column, the route prefix,
where users go after signing in, and `WEBID_API_URL` if webid's API is not at `api.` + webid's address.
