<?php

namespace App\Http\Controllers;

use App\Events\CandidateMatchRecomputeRequested;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    private const MATCH_INPUT_FIELDS = ['industry', 'city', 'province', 'language', 'education'];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->ensureProfile();
        $user->loadMissing('cvAsset');

        return response()->json($profile->toOwnerArray($user->cvAsset));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->ensureProfile();
        $fields = $request->validatedFields();

        if ($fields !== []) {
            $before = $this->matchInputSnapshot($profile);
            $profile->fill($fields);
            $profile->markFieldsEdited(array_keys($fields));
            $profile->save();

            if ($before !== $this->matchInputSnapshot($profile)) {
                CandidateMatchRecomputeRequested::dispatch($user->id);
            }
        }

        $user->loadMissing('cvAsset');

        return response()->json($profile->toOwnerArray($user->cvAsset));
    }

    /**
     * @return array<string, string|null>
     */
    private function matchInputSnapshot(Profile $profile): array
    {
        $snapshot = [];

        foreach (self::MATCH_INPUT_FIELDS as $field) {
            $value = $profile->{$field};
            $snapshot[$field] = is_string($value) ? $value : null;
        }

        return $snapshot;
    }
}
