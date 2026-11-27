<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListListingsRequest;
use App\Models\Listing;
use App\Services\BuildListingOpsItem;
use Illuminate\Http\JsonResponse;

class StaffListingController extends Controller
{
    public function __construct(
        private readonly BuildListingOpsItem $buildListingOpsItem,
    ) {}

    public function index(ListListingsRequest $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $limit = min(100, max(1, (int) $request->integer('limit', 20)));
        $offset = ($page - 1) * $limit;

        $query = Listing::query()->orderByDesc('id');

        if ($request->filled('source')) {
            $query->where('source', $request->string('source')->toString());
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->toString());
        }

        if ($request->filled('search')) {
            $term = '%'.trim($request->string('search')->toString()).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('title', 'like', $term)
                    ->orWhere('company', 'like', $term)
                    ->orWhere('city', 'like', $term);
            });
        }

        $total = (clone $query)->count();
        $listings = $query->offset($offset)->limit($limit)->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'listings' => $listings
                ->map(fn (Listing $listing): array => $this->buildListingOpsItem->handle($listing))
                ->values()
                ->all(),
        ]);
    }

    public function show(Listing $listing): JsonResponse
    {
        return response()->json($this->buildListingOpsItem->handle($listing, includeDescription: true));
    }
}
