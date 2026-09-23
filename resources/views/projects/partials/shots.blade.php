{{-- FR-8, FR-9, FR-10: plan, render, review, regenerate. --}}
<section class="card">
    <div class="head-row">
        <h2>Shots</h2>
        <div class="row-actions">
            <form method="POST" action="{{ route('pipeline.plan', $project) }}" class="inline">
                @csrf
                <button class="btn" type="submit">Plan shots from scenes</button>
            </form>
            <form method="POST" action="{{ route('pipeline.render', $project) }}" class="inline">
                @csrf
                <button class="btn primary" type="submit">Render pending shots</button>
            </form>
        </div>
    </div>

    @if ($project->shots->isEmpty())
        <p class="muted">No shots planned yet. Planning is free — it only writes prompts and durations.</p>
    @else
        <div class="shots">
            @foreach ($project->shots as $shot)
                <article class="shot status-{{ $shot->status->value }}">
                    <header>
                        <strong>Shot #{{ $shot->sequence }}</strong>
                        <span class="pill {{ $shot->status === \App\Enums\ShotStatus::Rendered ? 'ok' : ($shot->status === \App\Enums\ShotStatus::Stale || $shot->status === \App\Enums\ShotStatus::Failed ? 'warn' : '') }}">
                            {{ $shot->status->label() }}
                        </span>
                        <span class="muted small">
                            scene {{ $shot->scene?->sequence }} ·
                            clip {{ number_format((float) $shot->target_duration_seconds, 1) }}s ·
                            narration {{ number_format((float) $shot->narration_duration_seconds, 2) }}s
                            {{-- Shown per shot because with a companion model
                                 configured they genuinely differ, and the two
                                 rates in the cost estimate are otherwise
                                 unexplained. --}}
                            @if (($shotModels[$shot->id] ?? null) && count(array_unique($shotModels->all())) > 1)
                                · <span title="Rendering model">{{ $shotModels[$shot->id] }}</span>
                            @endif
                        </span>
                    </header>

                    @if ($shot->asset && $shot->isRendered())
                        <video controls preload="metadata" src="{{ route('assets.show', [$project, $shot->asset]) }}"></video>
                    @elseif ($shot->status === \App\Enums\ShotStatus::Stale && $shot->asset)
                        <video controls preload="metadata" class="stale" src="{{ route('assets.show', [$project, $shot->asset]) }}"></video>
                        <p class="hint">Kept for reference — an upstream change invalidated it.</p>
                    @endif

                    @if ($shot->error)
                        <p class="flash flash-error small">{{ $shot->error }}</p>
                    @endif

                    <p class="muted small">{{ $shot->narration_segment }}</p>

                    <form method="POST" action="{{ route('shots.regenerate', [$project, $shot]) }}">
                        @csrf
                        <label class="small">Prompt (edit to steer the regeneration)</label>
                        <textarea name="prompt" rows="3" maxlength="2000">{{ $shot->prompt }}</textarea>
                        <button class="btn small" type="submit">Regenerate this shot</button>
                    </form>
                </article>
            @endforeach
        </div>
    @endif
</section>
