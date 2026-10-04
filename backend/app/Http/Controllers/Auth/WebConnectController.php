<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Social\SocialConnect;
use App\Services\Social\SocialConnectException;
use App\Services\Social\SocialProviderManager;
use Illuminate\Http\Request;

/**
 * Server-side social connect for the API host's setup page. Same provider flow
 * as the SPA's popup, but the provider returns to /connect/{platform}/callback
 * here, so the user never leaves api.viewsmax.com mid agent connection. Each
 * provider app must allow that callback URL.
 */
class WebConnectController extends Controller
{
    public function __construct(protected SocialProviderManager $providers, protected SocialConnect $connect) {}

    public function start(Request $request, string $platform)
    {
        if (! $this->providers->supports($platform) || ! $this->providers->isConfigured($platform)) {
            return redirect()->route('register.setup')->with('error', ucfirst($platform).' is not available right now.');
        }

        if (! $this->providers->for($platform)->usesOAuth()) {
            return redirect()->route('register.setup')->with('error', ucfirst($platform).' connects with a handle and app password below.');
        }

        $built = $this->connect->authorizationUrl(
            $request->user(),
            $platform,
            route('connect.callback', $platform),
            $request->boolean('follow_us'),
        );

        return redirect()->away($built['authorization_url']);
    }

    public function callback(Request $request, string $platform)
    {
        if ($request->filled('error')) {
            return redirect()->route('register.setup')
                ->with('error', ucfirst($platform).' did not grant access: '.$request->input('error_description', $request->input('error')));
        }

        if (! $request->filled('code') || ! $this->providers->supports($platform)) {
            return redirect()->route('register.setup')->with('error', 'That connection could not be completed. Please try again.');
        }

        try {
            $accounts = $this->connect->complete($request->user(), $platform, $request->input('code'), $request->input('state'), route('connect.callback', $platform));
        } catch (SocialConnectException $e) {
            return redirect()->route('register.setup')->with('error', $e->getMessage());
        }

        return redirect()->route('register.setup')
            ->with('status', $accounts->count().' '.ucfirst($platform).' account'.($accounts->count() === 1 ? '' : 's').' connected.');
    }

    public function credentials(Request $request, string $platform)
    {
        if (! $this->providers->supports($platform) || $this->providers->for($platform)->usesOAuth()) {
            return redirect()->route('register.setup')->with('error', 'That platform connects with a Connect button.');
        }

        try {
            $this->connect->connectWithCredentials(
                $request->user(),
                $platform,
                $request->except(['_token', 'follow_us']),
                $request->boolean('follow_us'),
            );
        } catch (SocialConnectException $e) {
            return redirect()->route('register.setup')->with('error', $e->getMessage())->withInput($request->except('app_password', 'password'));
        }

        return redirect()->route('register.setup')->with('status', ucfirst($platform).' account connected.');
    }
}
