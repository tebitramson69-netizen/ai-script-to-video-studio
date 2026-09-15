@extends('layouts.app')
@section('title', 'Sign in')

@section('content')
    <div class="card narrow">
        <h1>Sign in</h1>
        <p class="muted">
            This studio has no public sign-up. Create the owner account with
            <code>php artisan studio:create-owner</code>.
        </p>

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>

            <label class="check">
                <input type="checkbox" name="remember" value="1"> Remember me
            </label>

            <button type="submit" class="btn primary">Sign in</button>
        </form>
    </div>
@endsection
