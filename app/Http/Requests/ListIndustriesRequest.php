<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListIndustriesRequest extends FormRequest
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
            'includeArchived' => ['sometimes', 'boolean'],
        ];
    }

    public function includeArchived(): bool
    {
        return $this->boolean('includeArchived');
    }
}
