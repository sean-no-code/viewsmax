@extends('oauth.layout')

@section('title', 'Set up your account — ViewsMax')
@section('width', '36rem')

@section('content')
    <div class="card">
        <h1 class="card-title">Set up your account</h1>
        <p class="card-description">
            <span class="setup-step"><b>1</b> Connect your channels</span>
            <span class="setup-step"><b>2</b> Grant {{ $client ?? 'your AI agent' }} access</span>
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
                        <span class="platform-name">{{ $p['label'] }}</span>
                        @if ($p['uses_oauth'])
                            <form method="get" action="{{ route('connect.start', $p['platform']) }}" class="platform-connect">
                                @if ($p['follow_us'])
                                    <label class="checkbox-row" style="margin: 0;">
                                        <input type="checkbox" name="follow_us" value="1" checked>
                                        <span class="muted">Follow us (&#64;{{ $p['follow_us'] }})</span>
                                    </label>
                                @endif
                                <button type="submit" class="btn btn-outline btn-sm">{{ $p['accounts']->isEmpty() ? 'Connect' : 'Add another' }}</button>
                            </form>
                        @endif
                    </div>
                    @foreach ($p['accounts'] as $account)
                        <div class="platform-account">✓ {{ $account->username ?? $account->name ?? $account->platform_account_id }}</div>
                    @endforeach
                    @if (! $p['uses_oauth'])
                        <form method="post" action="{{ route('connect.credentials', $p['platform']) }}" class="platform-credentials">
                            @csrf
                            <input type="text" name="identifier" value="{{ old('identifier') }}" placeholder="Handle, e.g. you.bsky.social" required>
                            <input type="password" name="password" placeholder="App password" required autocomplete="off">
                            @if ($p['follow_us'])
                                <label class="checkbox-row" style="margin: 0;">
                                    <input type="checkbox" name="follow_us" value="1" checked>
                                    <span class="muted">Follow us (&#64;{{ $p['follow_us'] }})</span>
                                </label>
                            @endif
                            <button type="submit" class="btn btn-outline btn-sm">Connect</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="muted">No platforms are available to connect right now. You can add channels later from ViewsMax.</p>
            @endforelse
        </div>

        <a href="{{ route('register.continue') }}" class="btn btn-primary" style="margin-top: 20px;">
            {{ $connectedCount > 0 ? 'Continue to grant access' : 'Skip for now, grant access' }}
        </a>
        <p class="card-footer">You can add more channels later from ViewsMax{{ $client ? ' or by asking '.$client : '' }}.</p>
    </div>

    <style>
        .setup-step { display: inline-flex; align-items: center; gap: 6px; margin: 0 10px; }
        .setup-step b { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 999px; background: var(--vm-red); color: #fff; font-size: 12px; }
        .platforms { display: flex; flex-direction: column; gap: 10px; }
        .platform { border: 1px solid var(--border); border-radius: calc(var(--radius) - 2px); padding: 12px 14px; }
        .platform-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .platform-name { font-weight: 600; font-size: 15px; }
        .platform-connect { display: flex; align-items: center; gap: 12px; margin: 0; }
        .platform-account { font-size: 13.5px; color: var(--muted-foreground); margin-top: 8px; }
        .platform-credentials { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
        .platform-credentials input { margin: 0; }
        .btn-sm { width: auto; height: 34px; padding: 6px 14px; }
    </style>
@endsection
