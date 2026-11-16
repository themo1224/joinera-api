<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BreakGlassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('reason')) {
            $this->merge([
                'reason' => trim((string) $this->input('reason')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'applicationId' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
