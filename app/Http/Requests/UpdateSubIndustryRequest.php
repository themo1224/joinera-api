<?php

namespace App\Http\Requests;

use App\Models\SubIndustry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubIndustryRequest extends FormRequest
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
        /** @var SubIndustry $subIndustry */
        $subIndustry = $this->route('subIndustry');

        return [
            'slug' => [
                'sometimes',
                'string',
                'max:64',
                'alpha_dash:ascii',
                Rule::unique('sub_industries', 'slug')->ignore($subIndustry->id),
            ],
            'labelEn' => ['sometimes', 'string', 'max:255'],
            'labelDe' => ['sometimes', 'string', 'max:255'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'industryId' => ['sometimes', 'integer', 'exists:industries,id'],
        ];
    }
}
