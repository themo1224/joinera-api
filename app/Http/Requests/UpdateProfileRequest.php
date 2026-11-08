<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'industry' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'province' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'language' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['industry', 'city', 'province', 'language'] as $field) {
                if (! $this->exists($field)) {
                    continue;
                }

                if (trim((string) $this->input($field)) === '') {
                    $validator->errors()->add($field, "The {$field} field cannot be empty.");
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function validatedFields(): array
    {
        $fields = [];

        foreach (['industry', 'city', 'province', 'language'] as $field) {
            if ($this->exists($field)) {
                $fields[$field] = trim($this->string($field)->toString());
            }
        }

        return $fields;
    }
}

