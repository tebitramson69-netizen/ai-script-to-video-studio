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
            // Restricted to what the rendering model can actually produce —
            // Veo 3.1, for instance, has no 1:1. Catching it here costs nothing;
            // catching it at render time costs a paid clip.
            'aspect_ratio' => [
                'required',
                Rule::enum(AspectRatio::class),
                Rule::in(array_map(
                    fn (AspectRatio $r) => $r->value,
                    app(ModelRegistry::class)->aspectRatiosFor(),
                )),
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
