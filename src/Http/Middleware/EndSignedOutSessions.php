<?php

namespace Webid\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Webid\Laravel\Webid;

/**
 * Ends a session whose person was signed out from their webid account since it began (see Webid::signedOutSince):
 * the visitor is signed out here on their next request, on whichever device the session lives.
 */
class EndSignedOutSessions
{
    public function __construct(private Webid $webid) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $session = $request->session()->get(Webid::SESSION_SIGN_IN);
            if (is_array($session) && $this->webid->signedOutSince((string) $session['sub'], (float) $session['at'])) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $next($request);
    }
}
