<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
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
            $profile->fill($fields);
            $profile->markFieldsEdited(array_keys($fields));
            $profile->save();
        }

        $user->loadMissing('cvAsset');

        return response()->json($profile->toOwnerArray($user->cvAsset));
    }
}
