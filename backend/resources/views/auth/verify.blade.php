@extends('oauth.layout')

@section('title', 'Check your email — ViewsMax')

@section('content')
    <div class="card">
        <h1 class="card-title">Check your email</h1>
        <p class="card-description">
            We sent a verification link to <b>{{ $email }}</b>.<br>
            Click it to continue{{ $client ? ' connecting '.$client : '' }}.
        </p>

        @if (session('error'))
            <div class="error">{{ session('error') }}</div>
        @endif
        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        <p class="muted" style="font-size: 14px; text-align: center; margin: 0 0 16px;">
            Didn't get it? Check your spam folder, or send it again.
        </p>
        <form method="post" action="{{ route('register.resend') }}">
            @csrf
            <button type="submit" class="btn btn-outline">Resend verification email</button>
        </form>

        <div class="card-footer">
            Wrong address?
            <form method="post" action="{{ route('web.logout') }}" style="display: inline; margin: 0;">
                @csrf
                <button type="submit" class="link-btn">Sign out</button>
            </form>
            and sign up again.
        </div>
    </div>
@endsection
