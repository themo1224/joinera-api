<?php

namespace App\Http\Controllers;

use App\Services\ImportListings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingImportController extends Controller
{
    public function __construct(private readonly ImportListings $importListings)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $rows = $request->input('rows');

        if (! is_array($rows)) {
            return response()->json([
                'code' => 'validation_failed',
                'message' => 'rows must be an array.',
            ], 422);
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = array_values($rows);

        $result = $this->importListings->handle($normalized);

        return response()->json($result, 200);
    }
}
