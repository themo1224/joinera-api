<?php

namespace App\Http\Requests;

use App\Models\Industry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIndustryRequest extends FormRequest
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
        /** @var Industry $industry */
        $industry = $this->route('industry');

        return [
            'slug' => [
                'sometimes',
                'string',
                'max:64',
                'alpha_dash:ascii',
                Rule::unique('industries', 'slug')->ignore($industry->id),
            ],
            'labelEn' => ['sometimes', 'string', 'max:255'],
            'labelDe' => ['sometimes', 'string', 'max:255'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
