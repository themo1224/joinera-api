<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListIndustriesRequest;
use App\Http\Requests\StoreIndustryRequest;
use App\Http\Requests\UpdateIndustryRequest;
use App\Models\Industry;
use App\Models\SubIndustry;
use Illuminate\Http\JsonResponse;

class StaffIndustryController extends Controller
{
    public function index(ListIndustriesRequest $request): JsonResponse
    {
        $includeArchived = $request->includeArchived();

        $query = Industry::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['subIndustries' => function ($relation) use ($includeArchived): void {
                if (! $includeArchived) {
                    $relation->active();
                }
            }]);

        if (! $includeArchived) {
            $query->active();
        }

        $industries = $query->get()->map(fn (Industry $industry) => $industry->toOpsArray())->values()->all();

        return response()->json([
            'success' => true,
            'industries' => $industries,
        ]);
    }

    public function store(StoreIndustryRequest $request): JsonResponse
    {
        $industry = Industry::query()->create([
            'slug' => strtolower($request->string('slug')->toString()),
            'label_en' => $request->string('labelEn')->toString(),
            'label_de' => $request->string('labelDe')->toString(),
            'sort_order' => (int) $request->integer('sortOrder', 0),
            'archived_at' => null,
        ]);

        $industry->load(['subIndustries']);

        return response()->json([
            'success' => true,
            'industry' => $industry->toOpsArray(),
        ], 201);
    }

    public function update(UpdateIndustryRequest $request, Industry $industry): JsonResponse
    {
        $payload = [];

        if ($request->exists('slug')) {
            $payload['slug'] = strtolower($request->string('slug')->toString());
        }
        if ($request->exists('labelEn')) {
            $payload['label_en'] = $request->string('labelEn')->toString();
        }
        if ($request->exists('labelDe')) {
            $payload['label_de'] = $request->string('labelDe')->toString();
        }
        if ($request->exists('sortOrder')) {
            $payload['sort_order'] = (int) $request->integer('sortOrder');
        }

        if ($payload !== []) {
            $industry->fill($payload)->save();
        }

        $industry->load(['subIndustries']);

        return response()->json([
            'success' => true,
            'industry' => $industry->toOpsArray(),
        ]);
    }

    public function archive(Industry $industry): JsonResponse
    {
        if ($industry->archived_at === null) {
            $industry->archived_at = now();
            $industry->save();
        }

        SubIndustry::query()
            ->where('industry_id', $industry->id)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);

        $industry->load(['subIndustries']);

        return response()->json([
            'success' => true,
            'industry' => $industry->toOpsArray(),
        ]);
    }

    public function unarchive(Industry $industry): JsonResponse
    {
        if ($industry->archived_at !== null) {
            $industry->archived_at = null;
            $industry->save();
        }

        $industry->load(['subIndustries']);

        return response()->json([
            'success' => true,
            'industry' => $industry->toOpsArray(),
        ]);
    }
}
