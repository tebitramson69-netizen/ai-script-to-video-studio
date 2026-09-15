{{-- FR-12 – FR-15, FR-19 – FR-21: audio, then assembly, then download. --}}
<section class="card">
    <div class="head-row">
        <h2>Audio &amp; export</h2>
        <div class="row-actions">
            <form method="POST" action="{{ route('pipeline.audio', $project) }}" class="inline">
                @csrf
                <button class="btn" type="submit">Generate narration + music</button>
            </form>
            <form method="POST" action="{{ route('pipeline.export', $project) }}" class="inline">
                @csrf
                <button class="btn primary" type="submit" @disabled($exportBlockedReason !== null)>
                    Assemble final video
                </button>
            </form>
        </div>
    </div>

    <ul class="facts">
        <li>
            Narration:
            @if ($narration = $project->narrationAsset())
                <a href="{{ route('assets.show', [$project, $narration]) }}">{{ number_format((float) $narration->duration_seconds, 2) }}s</a>
            @else
                <span class="muted">not generated</span>
            @endif
        </li>
        <li>
            Music:
            @if ($music = $project->musicAsset())
                <a href="{{ route('assets.show', [$project, $music]) }}">{{ number_format((float) $music->duration_seconds, 2) }}s</a>
                <span class="muted small">(ducked {{ config('studio.audio.music_duck_db') }} dB under narration)</span>
            @else
                <span class="muted">not generated</span>
            @endif
        </li>
    </ul>

    @if ($exportBlockedReason)
        {{-- PRD §8: export is blocked while any shot is stale. --}}
        <p class="flash flash-error">Export blocked: {{ $exportBlockedReason }}</p>
    @endif

    @if ($project->finalAsset)
        <div class="final">
            <h3>Final video</h3>
            <video controls preload="metadata" src="{{ route('assets.show', [$project, $project->finalAsset]) }}"></video>
            <p>
                {{ number_format((float) $project->finalAsset->duration_seconds, 2) }}s ·
                {{ number_format(((int) $project->finalAsset->bytes) / 1048576, 2) }} MB ·
                exported {{ $project->exported_at?->diffForHumans() }}
            </p>
            <a class="btn primary" href="{{ route('assets.download', [$project, $project->finalAsset]) }}">
                Download .mp4
            </a>
        </div>
    @endif
</section>
