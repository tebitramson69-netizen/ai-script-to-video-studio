@extends('layouts.app')
@section('title', 'New project')

@section('content')
    <div class="card narrow">
        <h1>New project</h1>

        <form method="POST" action="{{ route('projects.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="title">Title</label>
            <input id="title" name="title" type="text" value="{{ old('title') }}" required maxlength="200">

            <label for="video_model">Rendering model</label>
            <select id="video_model" name="video_model" required>
                @foreach ($videoModels as $key => $model)
                    <option value="{{ $key }}"
                            @selected(old('video_model', $videoModel->key) === $key)>
                        {{ $model->label }} (${{ number_format($model->costPerSecondUsd(), 3) }}/s)
                    </option>
                @endforeach
            </select>
            <p class="hint">
                Renders every shot, unless you set a companion below.
            </p>

            {{-- FR-6/G2. A text-to-video model cannot hold a character's face
                 between cuts; an image-to-video one cannot render an empty
                 street. Real scripts contain both, so the pair is what makes
                 one project able to do both. --}}
            <label for="video_model_i2v">Companion model for character shots <span class="hint">(optional)</span></label>
            <select id="video_model_i2v" name="video_model_i2v">
                <option value="">None — one model renders everything</option>
                @foreach ($videoModels as $key => $model)
                    @if ($imageCapableKeys->contains($key))
                        <option value="{{ $key }}" @selected(old('video_model_i2v') === $key)>
                            {{ $model->label }} (${{ number_format($model->costPerSecondUsd(), 3) }}/s)
                        </option>
                    @endif
                @endforeach
            </select>
            <p class="hint">
                Shots with a locked character reference render on this one instead, so faces
                stay consistent between cuts. Shots without a character stay on the model
                above. Only models that can start from a reference image are listed.
            </p>

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
                Only ratios <strong>every model you chose</strong> can produce are accepted;
                the list narrows as you choose.
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

    {{-- Progressive enhancement only. StoreProjectRequest validates the ratio
         against the intersection of the chosen models server-side and is what
         actually decides; this just stops the form offering a ratio that will
         be rejected. With scripting off the form still works — you may simply
         pick a ratio the pair cannot render and be told so on submit.

         The capability map goes out as JSON in a data island rather than
         interpolated into script source, so a model label can never break out
         of its string (§14). --}}
    <script type="application/json" id="ratios-by-model">@json($ratiosByModel)</script>
    <script type="application/json" id="all-ratios">@json($allAspectRatios)</script>
    <script>
        (function () {
            var island = document.getElementById('ratios-by-model');
            var allIsland = document.getElementById('all-ratios');
            var primary = document.getElementById('video_model');
            var companion = document.getElementById('video_model_i2v');
            var ratios = document.getElementById('aspect_ratio');

            if (!island || !allIsland || !primary || !companion || !ratios) {
                return;
            }

            var byModel, all;
            try {
                byModel = JSON.parse(island.textContent);
                all = JSON.parse(allIsland.textContent);
            } catch (e) {
                return;
            }

            // Read from the island, not the DOM: the select ships filtered for
            // the default model, so the DOM alone could only ever narrow
            // further — never widen back when a more capable model is chosen.

            var chosenOnLoad = ratios.value;

            function supported() {
                return [primary.value, companion.value]
                    .filter(function (key) { return key && byModel[key]; })
                    .reduce(function (carry, key) {
                        return carry === null
                            ? byModel[key].slice()
                            : carry.filter(function (r) { return byModel[key].indexOf(r) !== -1; });
                    }, null);
            }

            function narrow() {
                var allowed = supported();
                var chosen = ratios.value || chosenOnLoad;

                ratios.innerHTML = '';

                all.forEach(function (ratio) {
                    if (allowed !== null && allowed.indexOf(ratio.value) === -1) {
                        return;
                    }

                    var option = document.createElement('option');
                    option.value = ratio.value;
                    option.textContent = ratio.label;
                    option.selected = ratio.value === chosen;
                    ratios.appendChild(option);
                });

                // Everything filtered out means the pair shares no ratio at
                // all. Restore the full list rather than presenting an empty
                // control, and let the server say which model is the problem.
                if (!ratios.options.length) {
                    all.forEach(function (ratio) {
                        var option = document.createElement('option');
                        option.value = ratio.value;
                        option.textContent = ratio.label;
                        ratios.appendChild(option);
                    });
                }
            }

            primary.addEventListener('change', narrow);
            companion.addEventListener('change', narrow);
            narrow();
        })();
    </script>
@endsection
