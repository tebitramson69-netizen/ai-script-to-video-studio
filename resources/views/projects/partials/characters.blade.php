{{-- FR-4, FR-5, FR-6: detect the cast, generate candidates, lock exactly one. --}}
<section class="card">
    <div class="head-row">
        <h2>Characters</h2>
        <form method="POST" action="{{ route('pipeline.characters', $project) }}">
            @csrf
            <button class="btn" type="submit">Generate reference candidates</button>
        </form>
    </div>

    @if ($project->characters->isEmpty())
        <p class="muted">No characters detected. A narrated explainer with no cast is fine — skip to shots.</p>
    @else
        @foreach ($project->characters as $character)
            @php $candidates = $candidatesByCharacter[$character->id] ?? collect(); @endphp

            <div class="character">
                <div class="head-row">
                    <h3>
                        {{ $character->name }}
                        @if ($character->isLocked())
                            <span class="pill ok">locked</span>
                        @else
                            <span class="pill warn">not locked</span>
                        @endif
                    </h3>

                    <form method="POST" action="{{ route('characters.destroy', [$project, $character]) }}" class="inline"
                          onsubmit="return confirm('Remove this character?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Remove</button>
                    </form>
                </div>

                <form method="POST" action="{{ route('characters.update', [$project, $character]) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="name" value="{{ $character->name }}">

                    <label>Description used in the reference prompt</label>
                    <textarea name="description" rows="2" maxlength="1000">{{ $character->description }}</textarea>

                    <button class="btn small" type="submit">Save description</button>
                </form>

                @if ($candidates->isNotEmpty())
                    <div class="candidates">
                        @foreach ($candidates as $candidate)
                            <figure class="candidate {{ $character->canonical_reference_asset_id === $candidate->id ? 'locked' : '' }}">
                                <img src="{{ route('assets.show', [$project, $candidate]) }}"
                                     alt="Reference candidate for {{ $character->name }}">
                                <figcaption>
                                    @if ($character->canonical_reference_asset_id === $candidate->id)
                                        <span class="pill ok">canonical</span>
                                    @else
                                        <form method="POST" action="{{ route('characters.lock', [$project, $character]) }}">
                                            @csrf
                                            <input type="hidden" name="asset_id" value="{{ $candidate->id }}">
                                            <button class="btn small" type="submit">Lock this one</button>
                                        </form>
                                    @endif
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                @else
                    <p class="muted small">No candidates generated yet.</p>
                @endif
            </div>
        @endforeach

        <p class="hint">
            The locked image is reused for every shot this character appears in. Re-locking
            marks those shots stale — only the affected ones, not the whole video.
        </p>
    @endif
</section>
