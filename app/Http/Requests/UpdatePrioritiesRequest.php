<?php

namespace App\Http\Requests;

use App\Models\Profile;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePrioritiesRequest extends FormRequest
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
            'priorities' => ['required', 'array', 'size:1'],
            'priorities.*' => ['required', 'string', 'in:industry'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<string> $priorities */
            $priorities = array_values($this->input('priorities', []));
            $expected = Profile::DEFAULT_PRIORITIES;
            $sorted = $priorities;
            $expectedSorted = $expected;
            sort($sorted);
            sort($expectedSorted);

            if ($sorted !== $expectedSorted || count(array_unique($priorities)) !== count($expected)) {
                $validator->errors()->add('priorities', 'The priorities must be a permutation of industry.');
            }
        });
    }

    /**
     * @return list<string>
     */
    public function priorities(): array
    {
        /** @var list<string> $priorities */
        $priorities = array_values($this->validated('priorities'));

        return $priorities;
    }
}
