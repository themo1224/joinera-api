<?php

namespace App\Http\Requests;

use App\Enums\ListingKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListListingsRequest extends FormRequest
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
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'kind' => ['sometimes', 'nullable', 'string', Rule::enum(ListingKind::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
