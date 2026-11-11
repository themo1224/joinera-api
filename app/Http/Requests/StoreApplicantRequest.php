<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreApplicantRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Optional ops contact (WhatsApp etc.) — not a login identity. Not the GMX apply mailbox.
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            // Candidates have no login; Admin must not invent a password.
            'password' => ['prohibited'],
        ];
    }
}
