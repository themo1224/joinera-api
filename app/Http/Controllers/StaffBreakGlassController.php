<?php

namespace App\Http\Controllers;

use App\Http\Requests\BreakGlassRequest;
use App\Models\SiteIdentity;
use App\Services\BreakGlassRevealSiteSecret;
use Illuminate\Http\JsonResponse;

class StaffBreakGlassController extends Controller
{
    public function __construct(
        private readonly BreakGlassRevealSiteSecret $breakGlass,
    ) {}

    public function store(BreakGlassRequest $request, SiteIdentity $siteIdentity): JsonResponse
    {
        $result = $this->breakGlass->handle(
            $siteIdentity,
            $request->user(),
            $request->string('reason')->toString(),
            $request->filled('applicationId')
                ? (int) $request->integer('applicationId')
                : null,
        );

        if (! $result['ok']) {
            return match ($result['code']) {
                'reason_required' => response()->json([
                    'code' => 'reason_required',
                    'message' => 'A reason is required.',
                ], 422),
                'rate_limited' => response()->json([
                    'code' => 'rate_limited',
                    'message' => 'Too many break-glass requests. Try again later.',
                ], 429),
                'audit_failed' => response()->json([
                    'code' => 'audit_failed',
                    'message' => 'Audit write failed. Secret was not revealed.',
                ], 500),
                default => response()->json([
                    'code' => 'not_found',
                    'message' => 'Not found.',
                ], 404),
            };
        }

        return response()->json([
            'success' => true,
            'secret' => $result['secret'],
            'auditId' => $result['auditId'],
        ]);
    }
}
