<?php

namespace App\Http\Controllers;

use App\Services\BuildOpsReport;
use Illuminate\Http\JsonResponse;

class StaffOpsReportController extends Controller
{
    public function __construct(
        private readonly BuildOpsReport $buildOpsReport,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->buildOpsReport->handle());
    }
}
