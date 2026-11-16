<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubIndustryRequest;
use App\Http\Requests\UpdateSubIndustryRequest;
use App\Models\Industry;
use App\Models\SubIndustry;
use Illuminate\Http\JsonResponse;

class StaffSubIndustryController extends Controller
{
    public function store(StoreSubIndustryRequest $request, Industry $industry): JsonResponse
    {
        $subIndustry = SubIndustry::query()->create([
            'industry_id' => $industry->id,
            'slug' => strtolower($request->string('slug')->toString()),
            'label_en' => $request->string('labelEn')->toString(),
            'label_de' => $request->string('labelDe')->toString(),
            'sort_order' => (int) $request->integer('sortOrder', 0),
            'archived_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'subIndustry' => $subIndustry->toOpsArray(),
        ], 201);
    }

    public function update(UpdateSubIndustryRequest $request, SubIndustry $subIndustry): JsonResponse
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
        if ($request->exists('industryId')) {
            $payload['industry_id'] = (int) $request->integer('industryId');
        }

        if ($payload !== []) {
            $subIndustry->fill($payload)->save();
        }

        return response()->json([
            'success' => true,
            'subIndustry' => $subIndustry->fresh()->toOpsArray(),
        ]);
    }

    public function archive(SubIndustry $subIndustry): JsonResponse
    {
        if ($subIndustry->archived_at === null) {
            $subIndustry->archived_at = now();
            $subIndustry->save();
        }

        return response()->json([
            'success' => true,
            'subIndustry' => $subIndustry->toOpsArray(),
        ]);
    }

    public function unarchive(SubIndustry $subIndustry): JsonResponse
    {
        if ($subIndustry->archived_at !== null) {
            $subIndustry->archived_at = null;
            $subIndustry->save();
        }

        return response()->json([
            'success' => true,
            'subIndustry' => $subIndustry->toOpsArray(),
        ]);
    }
}
