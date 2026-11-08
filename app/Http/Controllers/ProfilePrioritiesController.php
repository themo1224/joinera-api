<?php

namespace App\Http\Controllers;

use App\Events\ProfilePrioritiesChanged;
use App\Http\Requests\UpdatePrioritiesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfilePrioritiesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        return response()->json([
            'priorities' => $profile->ensurePriorities(),
        ]);
    }

    public function update(UpdatePrioritiesRequest $request): JsonResponse
    {
        $user = $request->user();
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
