<?php

namespace App\Http\Requests;

use App\Enums\AspectRatio;
use App\Services\Provider\ModelRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],

            // FR-2: aspect ratio is a creation-time decision and cannot change
            // once shots exist, so it is validated here and never in an update.
            //
            // Restricted to what the chosen models can actually produce —
            // Veo 3.1, for instance, has no 1:1. Catching it here costs nothing;
            // catching it at render time costs a paid clip.
            //
            // Validated against the INTERSECTION when a companion is chosen: a
            // ratio only one of the pair can render would fail halfway through
            // a paid run.
            'aspect_ratio' => [
                'required',
                Rule::enum(AspectRatio::class),
                Rule::in(array_map(
                    fn (AspectRatio $r) => $r->value,
                    $this->allowedAspectRatios(),
                )),
            ],

            // Both default to the configured model when omitted, so a form
            // that never asks behaves exactly as it did before per-shot
            // selection existed.
            'video_model' => [
                'nullable', 'string',
                Rule::in(app(ModelRegistry::class)->availableKeys()),
            ],

            // The companion for shots with a locked character reference
            // (FR-6). Null means one model renders everything.
            'video_model_i2v' => [
                'nullable', 'string',
                Rule::in(app(ModelRegistry::class)->availableKeys()),
            ],

            'budget_cap_usd' => [
                'required', 'numeric', 'min:0.01',
                'max:'.config('studio.budget.max_cap_usd', 100),
            ],

            'script' => [
                'nullable', 'string',
                'max:'.config('studio.limits.max_script_characters', 20000),
            ],

            'script_file' => [
                'nullable', 'file',
                'mimetypes:text/plain,text/markdown,application/octet-stream',
                'max:'.config('studio.limits.max_upload_kilobytes', 512),
            ],
        ];
    }

    /**
     * The model this project will render most shots on.
     */
    public function primaryModelKey(): string
    {
        return $this->input('video_model')
            ?: app(ModelRegistry::class)->defaultVideo()->key;
    }

    /**
     * The companion model, or null for single-model behaviour.
     */
    public function companionModelKey(): ?string
    {
        $key = trim((string) $this->input('video_model_i2v'));

        // Choosing the same model twice is not a pairing, it is the default
        // dressed up — and storing it would make every later "is a companion
        // set?" check answer yes misleadingly.
        return $key === '' || $key === $this->primaryModelKey() ? null : $key;
    }

    /**
     * Ratios every model this project may use can produce.
     *
     * @return list<AspectRatio>
     */
    public function allowedAspectRatios(): array
    {
        $registry = app(ModelRegistry::class);
        $keys = array_filter([$this->primaryModelKey(), $this->companionModelKey()]);
        $available = $registry->availableKeys();

        $ratios = null;

        foreach ($keys as $key) {
            if (! in_array($key, $available, true)) {
                continue;
            }

            $supported = $registry->aspectRatiosFor($registry->video($key));

            // Intersected by value, not with array_intersect(), which
            // stringifies its arguments and cannot take a backed enum.
            $ratios = $ratios === null
                ? $supported
                : array_values(array_filter(
                    $ratios,
                    fn (AspectRatio $r) => in_array($r, $supported, true),
                ));
        }

        return $ratios ?? $registry->aspectRatiosFor();
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // FR-1 accepts a script by paste or upload — but one of them has
                // to actually be there.
                if (blank($this->input('script')) && ! $this->hasFile('script_file')) {
                    $validator->errors()->add('script', 'Paste a script or upload a .txt/.md file.');
                }
            },
        ];
    }

    /**
     * The script text, from whichever input carried it.
     */
    public function scriptText(): string
    {
        if ($this->hasFile('script_file')) {
            $contents = (string) file_get_contents($this->file('script_file')->getRealPath());

            // Uploaded bytes are not guaranteed to be valid UTF-8, and invalid
            // UTF-8 breaks both the parser and JSON encoding downstream.
            if (! mb_check_encoding($contents, 'UTF-8')) {
                $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
            }

            return mb_substr(
                $contents,
                0,
                (int) config('studio.limits.max_script_characters', 20000),
            );
        }

        return (string) $this->input('script');
    }
}
