<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffProfileController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $profile = $user->ensureProfile();
        $user->loadMissing('cvAsset');

        return response()->json($profile->toStaffArray($user->cvAsset));
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        $profile = $user->ensureProfile();
        $profile->approveOnce($request->user());
        $user->loadMissing('cvAsset');

        return response()->json($profile->fresh()->toStaffArray($user->cvAsset));
    }
}
