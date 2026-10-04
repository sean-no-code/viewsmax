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
    /**
     * Session keys holding the agent's /oauth/authorize URL (and client name)
     * for the whole signup → connect → consent chain. Laravel's own
     * `url.intended` is a shared key that any guest redirect (e.g. a provider
     * callback arriving without the session cookie) can overwrite, so the
     * flow keeps its own copy from the first time it sees the authorize URL.
     */
    public const SESSION_AUTHORIZE_URL = 'agent_signup.authorize_url';

    public const SESSION_CLIENT = 'agent_signup.client';

    public function create(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Use the API register endpoint.',
                'api_register_url' => url('/api/register'),
            ], 401);
        }

        return view('auth.register', [
            'client' => $this->rememberAgent($request),
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

        $client = $this->rememberAgent($request);

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

        $client = $this->rememberAgent($request);

        return view('auth.setup', [
            'client' => $client,
            // No pending /oauth/authorize (e.g. signup started from the header
            // button): there's nothing to approve, so Continue goes to the app.
            'hasAgent' => $request->session()->has(self::SESSION_AUTHORIZE_URL),
            'platforms' => $platforms,
            'connectedCount' => $user->socialAccounts()->count(),
        ]);
    }

    /** Continue to the consent screen (or the app's sign-in when nothing was pending). */
    public function continue(Request $request)
    {
        $this->rememberAgent($request);
        $authorizeUrl = $request->session()->pull(self::SESSION_AUTHORIZE_URL);
        $request->session()->forget([self::SESSION_CLIENT, 'url.intended']);

        return redirect()->to($authorizeUrl ?: rtrim((string) config('app.frontend_url'), '/').'/auth');
    }

    /**
     * Copy the pending authorize URL + client name from `url.intended` into the
     * flow's own session keys (first sighting wins) and return the client name.
     */
    private function rememberAgent(Request $request): ?string
    {
        $session = $request->session();
        $intended = $session->get('url.intended');

        if (! $session->has(self::SESSION_AUTHORIZE_URL) && OAuthIntendedClient::isAuthorizeUrl($intended)) {
            $session->put(self::SESSION_AUTHORIZE_URL, $intended);
            $session->put(self::SESSION_CLIENT, OAuthIntendedClient::nameFrom($intended));
        }

        return $session->get(self::SESSION_CLIENT);
    }
}
