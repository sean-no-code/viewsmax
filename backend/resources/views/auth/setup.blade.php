@extends('oauth.layout')

@section('title', 'Connect your channels — ViewsMax')
@section('width', '36rem')

@section('content')
    <div class="card">
        <h1 class="card-title">Connect your channels</h1>
        <p class="card-description">
            <span class="setup-step done"><b>✓</b> Verify email</span>
            <span class="setup-step current"><b>2</b> Connect channels</span>
            <span class="setup-step"><b>3</b> {{ $hasAgent ? 'Grant '.($client ?? 'your AI agent').' access' : 'Open ViewsMax' }}</span>
        </p>

        @if (session('error'))
            <div class="error">{{ session('error') }}</div>
        @endif
        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        <div class="platforms">
            @forelse ($platforms as $p)
                <div class="platform">
                    <div class="platform-head">
                        <div class="platform-id">
                            @include('partials.brand-icon', ['platform' => $p['platform'], 'size' => 18])
                            <div>
                                <div class="platform-name">{{ $p['label'] }}</div>
                                @if ($p['accounts']->isEmpty())
                                    <div class="platform-sub">Not connected</div>
                                @endif
                            </div>
                        </div>
                        @if ($p['uses_oauth'])
                            <form method="get" action="{{ route('connect.start', $p['platform']) }}" class="platform-connect">
                                @if ($p['follow_us'])
                                    <label class="checkbox-row" style="margin: 0;">
                                        <input type="checkbox" name="follow_us" value="1" checked>
                                        <span class="muted">Follow us (&#64;{{ $p['follow_us'] }})</span>
                                    </label>
                                @endif
                                <button type="submit" class="btn btn-primary btn-sm">{{ $p['accounts']->isEmpty() ? 'Connect' : 'Add account' }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($p['accounts']->isNotEmpty())
                        <div class="platform-accounts">
                            @foreach ($p['accounts'] as $account)
                                <div class="platform-account">
                                    @if ($account->avatar_url)
                                        <img src="{{ $account->avatar_url }}" alt="" class="platform-avatar">
                                    @else
                                        <span class="platform-avatar platform-avatar-blank"></span>
                                    @endif
                                    <span class="platform-account-name">{{ $account->name ?: ($account->username ? '@'.$account->username : 'Account '.$account->id) }}</span>
                                    <span class="platform-connected">✓ Connected</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if (! $p['uses_oauth'])
                        <form method="post" action="{{ route('connect.credentials', $p['platform']) }}" class="platform-credentials">
                            @csrf
                            <input type="text" name="identifier" value="{{ old('identifier') }}" placeholder="Handle, e.g. you.bsky.social" required>
                            <input type="password" name="password" placeholder="App password" required autocomplete="off">
                            <div class="platform-connect">
                                @if ($p['follow_us'])
                                    <label class="checkbox-row" style="margin: 0;">
                                        <input type="checkbox" name="follow_us" value="1" checked>
                                        <span class="muted">Follow us (&#64;{{ $p['follow_us'] }})</span>
                                    </label>
                                @endif
                                <button type="submit" class="btn btn-primary btn-sm">Connect</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="muted">No platforms are available to connect right now. You can add channels later from ViewsMax.</p>
            @endforelse
        </div>

        <a href="{{ route('register.continue') }}" class="btn btn-primary" style="margin-top: 20px;">
            @if ($hasAgent)
                {{ $connectedCount > 0 ? 'Continue to grant access' : 'Skip for now, grant access' }}
            @else
                {{ $connectedCount > 0 ? 'Continue to ViewsMax' : 'Skip for now, open ViewsMax' }}
            @endif
        </a>
        @if ($hasAgent)
            <div class="first-ask">
                <div class="first-ask-label">First thing to ask {{ $client ?? 'your AI agent' }}</div>
                <p class="first-ask-prompt">“Show me this month's top outlier videos in my niche and break down why the best one worked.”</p>
                <p class="muted">Outlier research works straight away, even before you connect a channel.</p>
            </div>
        @endif
        <p class="card-footer">You can add more channels later from ViewsMax{{ $client ? ' or by asking '.$client : '' }}.</p>
    </div>

    <style>
        .setup-step { display: inline-flex; align-items: center; gap: 6px; margin: 0 8px; color: var(--muted-foreground); }
        .setup-step b { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 999px; background: var(--border); color: var(--foreground); font-size: 12px; }
        .setup-step.current { color: var(--foreground); font-weight: 600; }
        .setup-step.current b { background: var(--vm-red); color: #fff; }
        .setup-step.done b { background: #16A34A; color: #fff; }
        .platforms { display: flex; flex-direction: column; gap: 10px; }
        .first-ask { margin-top: 20px; padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px; }
        .first-ask-label { font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted-foreground); }
        .first-ask-prompt { margin: 6px 0 4px; font-weight: 600; }
        .platform { border: 1px solid var(--border); border-radius: calc(var(--radius)); padding: 12px; }
        .platform-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .platform-id { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .brand-tile { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 999px; flex-shrink: 0; }
        .platform-name { font-weight: 600; font-size: 15px; }
        .platform-sub { font-size: 13px; color: var(--muted-foreground); white-space: nowrap; }
        .platform-connect { display: flex; align-items: center; gap: 12px; margin: 0; flex-shrink: 0; }
        .platform-connect .checkbox-row span { white-space: nowrap; font-size: 13px; }
        .platform-accounts { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
        .platform-account { display: flex; align-items: center; gap: 8px; background: hsl(220 14% 96%); border-radius: 6px; padding: 6px 10px; font-size: 14px; }
        .platform-avatar { width: 28px; height: 28px; border-radius: 999px; object-fit: cover; }
        .platform-avatar-blank { display: inline-block; background: var(--border); }
        .platform-account-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .platform-connected { font-size: 12.5px; font-weight: 600; color: #16A34A; white-space: nowrap; }
        .platform-credentials { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
        .platform-credentials input { margin: 0; }
        .btn-sm { width: auto; height: 34px; padding: 6px 14px; font-weight: 600; }
    </style>
@endsection
