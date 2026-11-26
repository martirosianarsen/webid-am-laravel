<?php

namespace Webid\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;
use Webid\Laravel\Events\SignedIn;
use Webid\Laravel\Users;
use Webid\Laravel\Webid;

/**
 * The site's webid routes: /webid/login sends the visitor to webid, /webid/callback brings them back signed in,
 * /webid/token gives the page (the chat widget) the visitor's token, /webid/logout signs them out, and
 * /webid/backchannel-logout lets webid sign a person out of the site from their webid account.
 */
class WebidController extends Controller
{
    public function __construct(private Webid $webid) {}

    public function login(Request $request): RedirectResponse
    {
        // Only a page of this site: a full address elsewhere (or "//host") would send the visitor away after signing in.
        $return = $request->string('return')->toString();
        if ($return !== '' && ($this->isOwnPath($return) || str_starts_with($return, $request->getSchemeAndHttpHost().'/'))) {
            $request->session()->put('url.intended', $return);
        }

        return redirect()->away($this->webid->authorizationUrl($request->session(), route('webid.callback')));
    }

    private function isOwnPath(string $return): bool
    {
        return str_starts_with($return, '/') && ! str_starts_with($return, '//') && ! str_contains($return, '\\');
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
        // Whose webid sign-in this session is, and since when: webid can end it from the person's account.
        $request->session()->put(Webid::SESSION_SIGN_IN, ['sub' => (string) $claims['sub'], 'at' => microtime(true)]);
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

    /**
     * webid tells the site that a person is signed out of it (from their webid account, without visiting the site):
     * their sessions here end on their next request, and "remember me" stops working for them.
     */
    public function backchannelLogout(Request $request): JsonResponse
    {
        try {
            $sub = $this->webid->signOut((string) $request->input('logout_token'));
        } catch (Throwable) {
            return response()->json(['error' => 'invalid_request'], 400);
        }
        $model = config('webid.users.model');
        $user = $model::query()->where(config('webid.users.column'), $sub)->first();
        if ($user !== null && $user->getRememberTokenName() !== '') {
            $user->setRememberToken(Str::random(60));
            $user->save();
        }

        return response()->json([])->header('Cache-Control', 'no-store');
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
