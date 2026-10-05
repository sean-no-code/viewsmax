@extends('oauth.layout')

@section('title', 'Create your account — ViewsMax')

@section('content')
    <div class="card">
        <h1 class="card-title">Create your account</h1>
        <p class="card-description">
            @if ($client)
                Sign up, connect your channels, then approve {{ $client }}'s access.
            @else
                Sign up to start posting and tracking sales.
            @endif
        </p>

        @if ($errors->any())
            <div class="error">
                @foreach ($errors->all() as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        @endif

        <form method="post" action="{{ route('register') }}">
            @csrf
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="Enter your name" required autofocus>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="Enter your email" required>
            <p class="muted" style="font-size: 13px; margin: -8px 0 16px;">We'll email you a link to confirm your address, so use one you can open.</p>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Create a password (8+ characters)" required minlength="8" autocomplete="new-password">

            <label for="password_confirmation">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" placeholder="Confirm your password" required minlength="8" autocomplete="new-password">

            {{-- Hidden 0 so an unticked box still submits the key and old() reflects it. --}}
            <input type="hidden" name="marketing_consent" value="0">
            <label class="checkbox-row">
                <input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent', '1') === '1')>
                <span>Join the list. Grow faster every week.</span>
            </label>

            <button type="submit" class="btn btn-primary">Create account</button>
        </form>

        <p class="card-footer">
            Already have an account? <a href="{{ route('login') }}">Sign in</a>
        </p>
    </div>
@endsection
