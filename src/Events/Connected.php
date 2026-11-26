<?php

namespace Webid\Laravel\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A person signed in here linked this account with their webid account (/webid/connect). $claims is what webid gave
 * the site about them, as in SignedIn.
 */
class Connected
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(public Authenticatable $user, public array $claims) {}
}
