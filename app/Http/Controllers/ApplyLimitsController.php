<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\Apply\ApplySlotLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plan 32 / P0-14 — Candidate reads their own apply-limit layers.
 */
class ApplyLimitsController extends Controller
{
    public function show(Request $request, ApplySlotLimiter $limiter): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['code' => 'invalid_credentials'], 401);
        }

        $site = strtolower(trim((string) $request->query('site', '')));
        if ($site === '') {
            return response()->json([
                'code' => 'validation_failed',
                'message' => 'Query site is required.',
            ], 422);
        }

        $targetUserId = (int) $user->id;
        $asUser = $request->query('user_id');
        if ($asUser !== null) {
            $requestedId = (int) $asUser;
            $isStaff = in_array($user->role, [Role::Staff, Role::Admin], true);
            if ($requestedId !== $targetUserId && ! $isStaff) {
                // P0-14 — another User cannot read this user’s limits.
                return response()->json(['code' => 'not_found'], 404);
            }
            if ($isStaff) {
                $targetUserId = $requestedId;
            }
        }

        $described = $limiter->describe($targetUserId, $site);

        return response()->json([
            'success' => true,
            'userId' => $targetUserId,
            'site' => $site,
            'profileKey' => $described['profileKey'],
            'layers' => $described['layers'],
        ]);
    }
}
