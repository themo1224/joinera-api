<?php

namespace App\Http\Controllers;

use App\Services\Apply\SuggestModeBStepNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal Mode B AI step navigator (shared worker secret).
 */
class InternalModeBStepNavController extends Controller
{
    public function __invoke(Request $request, SuggestModeBStepNav $suggest): JsonResponse
    {
        $snapshot = $request->input('snapshot');
        if (! is_array($snapshot)) {
            return response()->json([
                'action' => 'none',
                'confidence' => null,
                'control_id' => null,
                'click' => null,
                'reason' => null,
                'source' => 'ai_refuse',
            ], 422);
        }

        $result = $suggest->handle($snapshot);

        return response()->json($result);
    }
}
