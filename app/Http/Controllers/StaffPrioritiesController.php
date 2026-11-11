<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Events\ProfilePrioritiesChanged;
use App\Http\Requests\UpdatePrioritiesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StaffPrioritiesController extends Controller
{
    public function show(User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $profile = $user->ensureProfile();

        return response()->json([
            'priorities' => $profile->ensurePriorities(),
        ]);
    }

    public function update(UpdatePrioritiesRequest $request, User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $profile = $user->ensureProfile();
        $priorities = $request->priorities();

        if ($profile->setPriorities($priorities)) {
            ProfilePrioritiesChanged::dispatch($user->id, $priorities);
        }

        return response()->json([
            'priorities' => $profile->normalizedPriorities(),
        ]);
    }
}
