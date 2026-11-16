<?php

namespace App\Http\Requests;

use App\Models\BlockEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListBlockEventsRequest extends FormRequest
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
            'kinds' => ['sometimes', 'nullable', 'string'],
            'opens' => ['sometimes', 'nullable', 'string'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKinds = [
                BlockEvent::KIND_CAPTCHA,
                BlockEvent::KIND_TWO_FACTOR,
                BlockEvent::KIND_LOGIN_WALL,
            ];

            foreach ($this->kinds() as $kind) {
                if (! in_array($kind, $allowedKinds, true)) {
                    $validator->errors()->add('kinds', 'The selected kinds is invalid.');

                    return;
                }
            }

            foreach ($this->opens() as $open) {
                if (! in_array($open, ['true', 'false'], true)) {
                    $validator->errors()->add('opens', 'The selected opens is invalid.');

                    return;
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return $this->csvParts('kinds');
    }

    /**
     * @return list<string>
     */
    public function opens(): array
    {
        return $this->csvParts('opens');
    }

    /**
     * @return list<string>
     */
    private function csvParts(string $key): array
    {
        if (! $this->filled($key)) {
            return [];
        }

        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->string($key)->toString()),
        ), static fn (string $part): bool => $part !== ''));
    }
}
