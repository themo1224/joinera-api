<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueueSelectedMatchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'userId' => ['required', 'integer', 'min:1'],
            'matchIds' => ['required', 'array', 'min:1', 'max:100'],
            'matchIds.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
