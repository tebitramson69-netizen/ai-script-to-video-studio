@extends('layouts.app')
@section('title', 'New project')

@section('content')
    <div class="card narrow">
        <h1>New project</h1>

        <form method="POST" action="{{ route('projects.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="title">Title</label>
            <input id="title" name="title" type="text" value="{{ old('title') }}" required maxlength="200">

            <label for="aspect_ratio">Output aspect ratio</label>
            <select id="aspect_ratio" name="aspect_ratio" required>
                @foreach ($aspectRatios as $ratio)
                    <option value="{{ $ratio->value }}" @selected(old('aspect_ratio') === $ratio->value)>
                        {{ $ratio->label() }}
                    </option>
                @endforeach
            </select>
            {{-- FR-2: this is a creation-time decision precisely because changing
                 it after shots exist would mean re-rendering every one of them. --}}
            <p class="hint">
                Locked once shots exist — changing it later means regenerating every shot.
                Only ratios <strong>{{ $videoModel->label }}</strong> can produce are listed.
            </p>

            <label for="budget_cap_usd">Budget cap (USD)</label>
            <input id="budget_cap_usd" name="budget_cap_usd" type="number" step="0.01" min="0.01"
                   max="{{ config('studio.budget.max_cap_usd') }}"
                   value="{{ old('budget_cap_usd', config('studio.budget.default_cap_usd')) }}" required>
            <p class="hint">
                Hard cap. Generation stops before exceeding it. The PRD's worked example is
                roughly $7 for a clean 60-second run, $10–14 with regenerations.
            </p>

            <label for="script">Script</label>
            <textarea id="script" name="script" rows="14"
                      placeholder="Paste your folk tale, advert or explainer script here.">{{ old('script') }}</textarea>

            <label for="script_file">…or upload a .txt / .md file</label>
            <input id="script_file" name="script_file" type="file" accept=".txt,.md,text/plain,text/markdown">

            <button type="submit" class="btn primary">Create and parse script</button>
        </form>
    </div>
@endsection
