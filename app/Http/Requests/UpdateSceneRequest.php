<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'setting' => ['required', 'string', 'max:255'],
            'narration' => ['required', 'string', 'max:5000'],
            'mood' => ['nullable', 'string', 'max:64'],
            'action' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
