<?php

namespace Webid\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webid\Laravel\Events\SignedIn;
use Webid\Laravel\Users;
use Webid\Laravel\Webid;

/**
 * The site's webid routes: /webid/login sends the visitor to webid, /webid/callback brings them back signed in,
 * /webid/token gives the page (the chat widget) the visitor's token, and /webid/logout signs them out.
 */
class WebidController extends Controller
{
    public function __construct(private Webid $webid) {}

    public function login(Request $request): RedirectResponse
    {
        if ($request->filled('return')) {
            $request->session()->put('url.intended', $request->string('return')->toString());
        }

        return redirect()->away($this->webid->authorizationUrl($request->session(), route('webid.callback')));
    }

    public function callback(Request $request, Users $users): RedirectResponse
    {
        if (! $request->filled('code')) {
            // The visitor said no on webid's page, or webid could not sign them in.
            return redirect(config('webid.home'))->with('webid_error', (string) $request->query('error_description', $request->query('error', 'cancelled')));
        }
        try {
            ['claims' => $claims] = $this->webid->finish($request->session(), (string) $request->query('code'), (string) $request->query('state'), route('webid.callback'));
        } catch (Throwable $exception) {
            report($exception);

            return redirect(config('webid.home'))->with('webid_error', $exception->getMessage());
        }
        $user = $users->resolve($claims);
        Auth::login($user, (bool) config('webid.users.remember'));
        $request->session()->regenerate();
        event(new SignedIn($user, $claims));

        return redirect()->intended(config('webid.home'));
    }

    public function token(Request $request): JsonResponse
    {
        $token = $request->user() ? $this->webid->accessToken($request->session()) : null;

        return $token === null
            ? response()->json(['message' => 'Not signed in with webid.'], 401)
            : response()->json(['access_token' => $token]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $idToken = $request->session()->get(Webid::SESSION_TOKENS)['id_token'] ?? null;
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $home = url(config('webid.home'));

        return redirect()->away($this->webid->logoutUrl($idToken, $home) ?? $home);
    }
}
