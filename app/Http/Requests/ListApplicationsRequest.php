<?php

namespace App\Http\Requests;

use App\Enums\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListApplicationsRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', 'string'],
            'userId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'site' => ['sometimes', 'nullable', 'string', 'max:255'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('status')) {
                return;
            }

            $allowed = array_map(
                static fn (ApplicationStatus $status): string => $status->value,
                ApplicationStatus::cases(),
            );
            $parts = array_values(array_filter(array_map(
                'trim',
                explode(',', $this->string('status')->toString()),
            ), static fn (string $part): bool => $part !== ''));

            if ($parts === []) {
                $validator->errors()->add('status', 'The status field must contain at least one status.');

                return;
            }

            foreach ($parts as $part) {
                if (! in_array($part, $allowed, true)) {
                    $validator->errors()->add('status', 'The selected status is invalid.');

                    return;
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        if (! $this->filled('status')) {
            return [];
        }

        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->string('status')->toString()),
        ), static fn (string $part): bool => $part !== ''));
    }
}
