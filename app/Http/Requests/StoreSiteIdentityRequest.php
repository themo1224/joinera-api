<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSiteIdentityRequest extends FormRequest
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
            'site' => ['required', 'string', 'max:255'],
            'site_login' => ['required', 'string', 'max:255'],
            'secret' => ['required', 'string', 'max:2000'],
        ];
    }
}
