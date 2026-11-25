<?php

namespace Webid\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The site's user for a webid sign-in: the one already linked to this webid id, else the one with the same confirmed
 * e-mail address (linked from now on), else a new one. The name is kept up to date from webid.
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
        $sub = (string) $claims['sub'];
        $email = ($claims['email']['verified'] ?? false) ? ($claims['email']['address'] ?? null) : null;

        $user = $model::query()->where($column, $sub)->first();
        if ($user === null && $email !== null && config('webid.users.link_by_email')) {
            $user = $model::query()->where('email', $email)->whereNull($column)->first();
        }
        if ($user === null) {
            // A new user takes the address only while no other user has it (an unconfirmed address may be someone
            // else's). Sites whose users must have an e-mail address create them with createUsing().
            $address = $claims['email']['address'] ?? null;
            $free = is_string($address) && ! $model::query()->where('email', $address)->exists();
            $user = self::$create !== null ? (self::$create)($claims) : (new $model)->forceFill([
                'name' => $claims['name'] ?? ($free ? $address : null) ?? 'webid',
                'email' => $free ? $address : null,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        $user->forceFill([$column => $sub, ...(isset($claims['name']) ? ['name' => $claims['name']] : [])])->save();

        return $user;
    }
}
