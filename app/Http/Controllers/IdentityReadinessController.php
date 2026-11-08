<?php

namespace App\Http\Controllers;

use App\Services\ResolveIdentityReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityReadinessController extends Controller
{
    public function __construct(private readonly ResolveIdentityReadiness $resolveIdentityReadiness)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $this->resolveIdentityReadiness->handle($request->user()),
        );
    }

    public function rejectPut(): JsonResponse
    {
        return response()->json([
            'code' => 'method_not_allowed',
            'message' => 'Identity readiness cannot be updated.',
        ], 405);
    }
}
