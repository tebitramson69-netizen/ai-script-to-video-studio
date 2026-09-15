<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Laravel injects the CSRF token into every non-GET form via @csrf; this
         meta tag is here for any future fetch() calls. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Script to Video Studio')</title>
    <link rel="stylesheet" href="{{ asset('css/studio.css') }}">
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ route('projects.index') }}">Script&nbsp;&rarr;&nbsp;Video Studio</a>
    @auth
        <nav>
            <a href="{{ route('projects.create') }}">New project</a>
            <form method="POST" action="{{ route('logout') }}" class="inline">
                @csrf
                <button type="submit" class="link">Sign out</button>
            </form>
        </nav>
    @endauth
</header>

<main class="wrap">
    @if (session('status'))
        <div class="flash flash-ok">{{ session('status') }}</div>
    @endif

    @if (session('budget_error'))
        {{-- NFR-4: the cap is hard, and the owner is told the actual numbers. --}}
        <div class="flash flash-budget">
            <strong>Budget cap reached.</strong>
            {{ session('budget_error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="flash flash-error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<footer class="foot">
    <span>Phase 1 &mdash; narrated scene video. Video driver: <code>{{ config('studio.video_generator') }}</code></span>
</footer>
</body>
</html>
