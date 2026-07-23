@extends('oauth.layout')

@section('title', 'Sign in — ViewsMax')

@section('content')
    <div class="card">
        <h1 class="card-title">Welcome</h1>
        <p class="card-description">Sign in to your account to continue</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ url('/login') }}">
            @csrf
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="Enter your email" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Enter your password" required>

            <button type="submit" class="btn btn-primary">Sign In</button>
        </form>
    </div>
@endsection
