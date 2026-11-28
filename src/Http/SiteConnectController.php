<?php

namespace Webid\Laravel\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Throwable;
use Webid\Laravel\SiteConnect;

/**
 * Connecting the site in one click (Webid\Laravel\SiteConnect): answering webid with the state, and coming back.
 */
class SiteConnectController extends Controller
{
    public function __construct(private SiteConnect $connect) {}

    /**
     * webid's server asks whether this site started connecting with this state: the state, or 404.
     */
    public function answer(string $state): Response
    {
        abort_unless($this->connect->started($state), 404);

        return response($state, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Back from webid with the code: the key is kept and the person goes back to the page they started on.
     */
    public function connected(Request $request): RedirectResponse
    {
        $after = $request->session()->get(SiteConnect::SESSION.'.after', '/');
        try {
            $after = $this->connect->finish($request->session(), $request->query('code'), $request->query('state'), $request->query('error'));
        } catch (Throwable $error) {
            return redirect()->to($after)->with(SiteConnect::RESULT, $error->getMessage());
        }

        return redirect()->to($after)->with(SiteConnect::RESULT, 'connected');
    }
}
