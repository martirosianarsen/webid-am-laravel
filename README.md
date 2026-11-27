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
<x-webid::chat mode="agent" target="#inbox" />   {{-- the staff inbox inside #inbox --}}
```

That is all. The package adds:

| Route | What it does |
|---|---|
| `GET /webid/login` | sends the visitor to webid (`?return=/checkout` to come back there) |
| `GET /webid/callback` | brings them back signed in (webid's default redirect address) |
| `GET /webid/connect` | links the account a person is signed in to here (with your own password) with their webid account |
| `GET /webid/token` | the token for the chat widget on the page (`data-token-url="/webid/token"`): a short-lived chat token that opens the chat and nothing else; the visitor's access token stays on the server |
| `POST /webid/logout` | signs out here and on webid |
| `POST /webid/backchannel-logout` | webid's own call: signs a person out here when they press “Sign out of every site” in their webid account, or disconnect the site |

## Users

At sign-in the package finds the site's user by `webid_sub`, else creates one, and signs them in. The name is updated
from webid each time.

Someone who already has an account on your site (with your own password) links it to webid while signed in to it:

```blade
<x-webid::button connect return="/account" />    {{-- "Connect webid", for signed-in users --}}
```

They confirm on webid's own page that the webid account is theirs; the link is kept only for the account that started
it, and an account (or a webid account) already linked elsewhere is never taken over. The `Webid\Laravel\Events\Connected`
event follows, with the same claims as `SignedIn`.

An equal e-mail address never links accounts: your records cannot prove whose address an account was opened with, so
linking by it would let someone who opened an account with another person's address read that person's data once they
sign in with webid.

Everything webid gave the site
arrives in the `Webid\Laravel\Events\SignedIn` event as standard OpenID Connect claims (`$event->claims`: `email`,
`email_verified`, `phone_number`, `preferred_username` (the webid ID), `address`, `locale`…, plus webid's `adult`), to
keep more of it on your user.

If your users need more than a name, an e-mail and a password, create them yourself:

```php
Webid\Laravel\Users::createUsing(fn (array $claims) => User::create([...]));
```

## Orders in the chat

With the chat on, a customer signed in with webid sees their orders in the chat with you: the order number and where
it stands (accepted, preparing, on the way, delivered or cancelled), pinned above the messages and updated in place.
Never what was ordered, the price or the address. Map your own statuses onto those stages in `config/webid.php`:

```php
'orders' => [
    'stages' => ['pending' => 'accepted', 'processing' => 'preparing', 'shipped' => 'on_the_way', 'completed' => 'delivered', 'refunded' => 'cancelled'],
],
```

Add the order while its customer is on your site (at checkout), then move it from anywhere:

```php
$orders = app(Webid\Laravel\Orders::class);
$orders->add($request->session(), $order->number, $order->status);   // the customer's webid token is in their session
$orders->move($order->number, $order->status);                        // from an admin page, a job, a webhook
```

`add()` works only for a customer signed in with webid (otherwise it returns false and sends nothing): webid takes an
order only with that person's own token for your site. When a customer signs in with webid later, or connects their
account, add their open orders from a listener of the `SignedIn` / `Connected` events. `move()` goes on your queue
and is retried while webid cannot be reached; neither method ever throws, so your shop works the same without webid.

A customer may ask webid to confirm orders before they show (in their account's Security settings). Their order then
waits until they confirm it on webid; one they turn down is not shown, and its later stages are let be. Nothing changes
on your side.

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
