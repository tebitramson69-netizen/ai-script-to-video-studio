@extends('layouts.app')
@section('title', $project->title)

@php
    use App\Enums\ProjectStatus;
    use App\Enums\ShotStatus;
    $status = $project->status;
@endphp

@section('content')
    <div class="head-row">
        <div>
            <h1>{{ $project->title }}</h1>
            <p class="muted">
                {{ $project->aspect_ratio->value }} ·
                {{ strtoupper($project->language) }} ·
                {{ $project->scenes->count() }} scenes ·
                {{ $project->shots->count() }} shots ·
                <span title="Rendering model">{{ $videoModel->label }}</span>
            </p>
        </div>
        <form method="POST" action="{{ route('projects.destroy', $project) }}"
              onsubmit="return confirm('Delete this project and all its generated media?')">
            @csrf @method('DELETE')
            <button class="btn danger" type="submit">Delete project</button>
        </form>
    </div>

    {{-- Capability gaps: the render will succeed but produce something other
         than what the PRD promises. Shown before the pipeline because the
         decision they force is "should I pay for this at all?". --}}
    @foreach ($degradationWarnings as $warning)
        <div class="flash flash-budget">
            <strong>Reduced output.</strong> {{ $warning }}
        </div>
    @endforeach

    {{-- ── Pipeline state (PRD §8) ─────────────────────────────────────── --}}
    <ol class="pipeline">
        @foreach (ProjectStatus::cases() as $stage)
            <li class="stage
                {{ $status === $stage ? 'current' : '' }}
                {{ $status->isAtLeast($stage) ? 'done' : '' }}">
                <span class="dot"></span>{{ $stage->label() }}
            </li>
        @endforeach
    </ol>

    @if ($status->nextAction())
        <p class="next-action">Next: {{ $status->nextAction() }}</p>
    @endif

    {{-- ── Cost (FR-11, NFR-4) ─────────────────────────────────────────── --}}
    <section class="card">
        <h2>Cost</h2>
        <div class="cost-grid">
            <div><span class="muted">Spent</span><strong>${{ number_format($project->spentUsd(), 2) }}</strong></div>
            <div><span class="muted">Estimated for remaining run</span><strong>${{ number_format($estimate->estimatedUsd(), 2) }}</strong></div>
            <div><span class="muted">Projected total</span><strong>${{ number_format($estimate->projectedTotalUsd(), 2) }}</strong></div>
            <div><span class="muted">Budget cap</span><strong>${{ number_format((float) $project->budget_cap_usd, 2) }}</strong></div>
            <div><span class="muted">Estimated runtime</span><strong>{{ number_format($runtimeSeconds, 1) }}s</strong></div>
        </div>

        @if ($estimate->significantLineItems())
            <table class="table compact">
                @foreach ($estimate->significantLineItems() as $label => $usd)
                    <tr><td>{{ $label }}</td><td class="num">${{ number_format($usd, 4) }}</td></tr>
                @endforeach
            </table>
        @else
            <p class="hint">
                Every line item estimates $0.00 because the active drivers are the local
                <code>fake</code> set. Point <code>STUDIO_*_DRIVER</code> at a real provider
                and these become real money.
            </p>
        @endif

        @if ($estimate->exceedsBudget())
            <p class="flash flash-budget">This run would exceed the cap. Raise the cap or cut shots.</p>
        @endif
    </section>

    {{-- ── Script & scenes (FR-1, FR-3) ────────────────────────────────── --}}
    <section class="card">
        <div class="head-row">
            <h2>Scenes</h2>
            <form method="POST" action="{{ route('pipeline.parse', $project) }}">
                @csrf
                <button class="btn" type="submit">Re-parse script</button>
            </form>
        </div>

        @if ($project->scenes->isEmpty())
            <p class="muted">No scenes yet. The parser runs on the queue — refresh in a moment.</p>
        @else
            @foreach ($project->scenes as $scene)
                <details class="scene">
                    <summary>
                        <strong>{{ $scene->sequence }}.</strong>
                        {{ $scene->setting }}
                        <span class="pill small">{{ $scene->mood ?? 'neutral' }}</span>
                        <span class="muted small">{{ $scene->wordCount() }} words</span>
                    </summary>

                    <form method="POST" action="{{ route('scenes.update', [$project, $scene]) }}">
                        @csrf @method('PATCH')

                        <label>Setting</label>
                        <input name="setting" value="{{ $scene->setting }}" maxlength="255" required>

                        <label>Narration</label>
                        <textarea name="narration" rows="4" maxlength="5000" required>{{ $scene->narration }}</textarea>

                        <label>Mood</label>
                        <input name="mood" value="{{ $scene->mood }}" maxlength="64">

                        <label>Action / camera direction</label>
                        <textarea name="action" rows="2" maxlength="2000">{{ $scene->action }}</textarea>

                        <div class="row-actions">
                            <button class="btn" type="submit">Save scene</button>
                        </div>
                    </form>

                    <div class="row-actions">
                        <form method="POST" action="{{ route('scenes.move', [$project, $scene]) }}" class="inline">
                            @csrf <input type="hidden" name="direction" value="up">
                            <button class="btn small" type="submit">&uarr; Up</button>
                        </form>
                        <form method="POST" action="{{ route('scenes.move', [$project, $scene]) }}" class="inline">
                            @csrf <input type="hidden" name="direction" value="down">
                            <button class="btn small" type="submit">&darr; Down</button>
                        </form>
                        <form method="POST" action="{{ route('scenes.destroy', [$project, $scene]) }}" class="inline"
                              onsubmit="return confirm('Delete this scene?')">
                            @csrf @method('DELETE')
                            <button class="btn small danger" type="submit">Delete</button>
                        </form>
                    </div>
                </details>
            @endforeach

            <p class="hint">
                Editing any scene resets the project to “Scene list ready” and marks
                existing shots stale — export stays blocked until they are re-rendered.
            </p>
        @endif
    </section>

    @include('projects.partials.characters')
    @include('projects.partials.shots')
    @include('projects.partials.export')
@endsection
