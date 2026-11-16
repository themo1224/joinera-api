<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIndustryRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:64', 'alpha_dash:ascii', Rule::unique('industries', 'slug')],
            'labelEn' => ['required', 'string', 'max:255'],
            'labelDe' => ['required', 'string', 'max:255'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
