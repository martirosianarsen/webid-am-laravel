<?php

namespace Webid\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The site's user for a webid sign-in: the one linked to this webid id, else a new one, with the e-mail address only
 * when webid says it is confirmed (email_verified). The name is kept up to date from webid.
 *
 * A user who already has an account here is never linked by an equal e-mail address: the site's own records cannot
 * prove that address belongs to them (an account may have been opened with someone else's address). They link it
 * themselves, signed in to that account: /webid/connect (see connect()).
 *
 * A site whose users need more than a name, an e-mail and a password can create them itself:
 * Users::createUsing(fn (array $claims) => User::create([...])).
 */
class Users
{
    private static ?Closure $create = null;

    /**
     * @param  Closure(array<string, mixed>): Authenticatable  $callback
     */
    public static function createUsing(?Closure $callback): void
    {
        self::$create = $callback;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function resolve(array $claims): Authenticatable
    {
        /** @var class-string<Model&Authenticatable> $model */
        $model = config('webid.users.model');
        $column = config('webid.users.column');

        $user = $model::query()->where($column, (string) $claims['sub'])->first();
        if ($user === null) {
            // A new user takes the address only when webid says it is confirmed (email_verified) and no other user has it.
            $address = ($claims['email_verified'] ?? false) === true ? ($claims['email'] ?? null) : null;
            $free = is_string($address) && ! $model::query()->where('email', $address)->exists();
            $user = self::$create !== null ? (self::$create)($claims) : (new $model)->forceFill([
                'name' => $claims['name'] ?? ($free ? $address : null) ?? 'webid',
                'email' => $free ? $address : null,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        return $this->link($user, $claims);
    }

    /**
     * Links the user who is signed in here to the webid account they have just signed in to on webid's own page: the
     * two together prove the same person holds both. Refused when that webid account belongs to another user here,
     * or this user is already linked to another webid account.
     *
     * @param  Model&Authenticatable  $user
     * @param  array<string, mixed>  $claims
     */
    public function connect(Authenticatable $user, array $claims): Authenticatable
    {
        $model = config('webid.users.model');
        $column = config('webid.users.column');
        $sub = (string) $claims['sub'];

        $current = $user->getAttribute($column);
        if ($current !== null && $current !== $sub) {
            throw new RuntimeException('This account is already connected to another webid account.');
        }
        if ($model::query()->where($column, $sub)->whereKeyNot($user->getKey())->exists()) {
            throw new RuntimeException('This webid account is already connected to another account here.');
        }

        return $this->link($user, $claims);
    }

    /**
     * @param  Model&Authenticatable  $user
     * @param  array<string, mixed>  $claims
     */
    private function link(Authenticatable $user, array $claims): Authenticatable
    {
        $user->forceFill([config('webid.users.column') => (string) $claims['sub'], ...(isset($claims['name']) ? ['name' => $claims['name']] : [])])->save();

        return $user;
    }
}
