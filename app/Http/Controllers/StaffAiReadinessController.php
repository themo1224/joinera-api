<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiReadiness;
use Illuminate\Http\JsonResponse;

class StaffAiReadinessController extends Controller
{
    public function __construct(
        private readonly AiReadiness $aiReadiness,
    ) {}

    public function show(): JsonResponse
    {
        $result = $this->aiReadiness->check();

        if ($result['ready']) {
            return response()->json([
                'ready' => true,
            ]);
        }

        return response()->json([
            'ready' => false,
            'code' => $result['code'],
            'message' => $result['message'],
        ], 503);
    }
}
