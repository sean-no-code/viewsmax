<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mcp\Tools\CreatePost;
use App\Models\User;
use App\Services\Registration;
use App\Services\Social\SocialProviderManager;
use App\Support\OAuthIntendedClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Browser signup on the API host, the companion to the /login bridge in
 * routes/web.php. AI agents (Claude, ChatGPT, ...) send brand-new users to
 * /oauth/authorize → /login → here. Signup logs straight into the 'web' guard
 * (no email-verification gate on this guard; the SPA's own login keeps it) and
 * continues to /register/setup, where the user connects channels and then
 * returns to the intended consent screen to grant the agent access.
 */
class WebRegisterController extends Controller
{
    public function create(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Use the API register endpoint.',
                'api_register_url' => url('/api/register'),
            ], 401);
        }

        return view('auth.register', [
            'client' => OAuthIntendedClient::nameFrom($request->session()->get('url.intended')),
        ]);
    }

    public function store(Request $request, Registration $registration)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'marketing_consent' => 'nullable|boolean',
        ]);

        // Read before anything consumes the intended URL: it names the agent.
        $client = OAuthIntendedClient::nameFrom($request->session()->get('url.intended'));

        $user = $registration->register([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'marketing_consent' => $request->boolean('marketing_consent'),
        ], User::SIGNUP_SOURCE_AGENT, $client);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('register.setup');
    }

    /**
     * "Set up your account": connect channels here on the API host, then
     * continue to the consent screen. Lists only platforms a user can post to.
     */
    public function setup(Request $request, SocialProviderManager $providers)
    {
        $user = $request->user();
        $postable = CreatePost::availablePlatforms();
        $accounts = $user->socialAccounts()->get()->groupBy('platform');

        $platforms = collect($providers->catalog())
            ->filter(fn (array $p) => in_array($p['platform'], $postable, true))
            ->map(fn (array $p) => $p + ['accounts' => $accounts->get($p['platform'], collect())])
            ->values();

        return view('auth.setup', [
            'client' => OAuthIntendedClient::nameFrom($request->session()->get('url.intended')),
            'hasIntended' => OAuthIntendedClient::isAuthorizeUrl($request->session()->get('url.intended')),
            'platforms' => $platforms,
            'connectedCount' => $user->socialAccounts()->count(),
        ]);
    }

    /** Continue to the consent screen (or the app when nothing was pending). */
    public function continue(Request $request)
    {
        return redirect()->intended(rtrim((string) config('app.frontend_url'), '/').'/auth');
    }
}
