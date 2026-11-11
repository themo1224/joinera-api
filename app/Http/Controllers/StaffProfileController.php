<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreCvRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\CvStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffProfileController extends Controller
{
    public function __construct(private readonly CvStorage $cvStorage)
    {
    }

    public function show(User $user): JsonResponse
    {
        $profile = $user->ensureProfile();
        $user->loadMissing('cvAsset');

        return response()->json($profile->toStaffArray($user->cvAsset));
    }

    public function update(UpdateProfileRequest $request, User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $profile = $user->ensureProfile();
        $fields = $request->validatedFields();

        if ($fields !== []) {
            $profile->fill($fields);
            $profile->markFieldsEdited(array_keys($fields));
            $profile->save();
        }

        $user->loadMissing('cvAsset');

        return response()->json($profile->fresh()->toStaffArray($user->cvAsset));
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        $profile = $user->ensureProfile();
        $profile->approveOnce($request->user());
        $user->loadMissing('cvAsset');

        return response()->json($profile->fresh()->toStaffArray($user->cvAsset));
    }

    public function storeCv(StoreCvRequest $request, User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $profile = $user->ensureProfile();
        $this->cvStorage->store($user, $request->file('cv'));
        $user->load('cvAsset');

        return response()->json($profile->fresh()->toStaffArray($user->cvAsset), 201);
    }
}
