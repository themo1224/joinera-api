<?php

namespace App\Http\Requests;

use App\Enums\DocumentAssetKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfileDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'max:25600', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
            'kind' => ['required', 'string', Rule::in(DocumentAssetKind::values())],
        ];
    }
}
