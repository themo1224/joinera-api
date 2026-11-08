<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ResolveIdentityReadiness;
use Illuminate\Http\JsonResponse;

class StaffIdentityReadinessController extends Controller
{
    public function __construct(private readonly ResolveIdentityReadiness $resolveIdentityReadiness)
    {
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(
            $this->resolveIdentityReadiness->handle($user),
        );
    }
}
