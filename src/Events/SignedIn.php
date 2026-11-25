<?php

namespace Webid\Laravel\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A visitor signed in with webid. $claims is what webid gave the site about them (name, email, phone, address…, as
 * webid allows the business); listen to keep more of it on the user.
 */
class SignedIn
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(public Authenticatable $user, public array $claims) {}
}
