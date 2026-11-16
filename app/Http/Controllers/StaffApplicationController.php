<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ListApplicationsRequest;
use App\Models\Application;
use App\Services\BuildApplicationOpsItem;
use Illuminate\Http\JsonResponse;

class StaffApplicationController extends Controller
{
    public function __construct(
        private readonly BuildApplicationOpsItem $buildApplicationOpsItem,
    ) {}

    public function index(ListApplicationsRequest $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $limit = min(100, max(1, (int) $request->integer('limit', 20)));
        $offset = ($page - 1) * $limit;

        $query = Application::query()
            ->whereHas('user', fn ($q) => $q->where('role', Role::User))
            ->with(['user', 'listing', 'candidateListingMatch', 'docArtifacts', 'applyAttempts'])
            ->orderByDesc('id');

        $statuses = $request->statuses();
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('userId')) {
            $query->where('user_id', (int) $request->integer('userId'));
        }

        if ($request->filled('site')) {
            $query->where('site', $request->string('site')->toString());
        }

        if ($request->filled('search')) {
            $raw = trim($request->string('search')->toString());
            $term = '%'.$raw.'%';
            $query->where(function ($q) use ($raw, $term): void {
                $q->whereHas('user', function ($userQuery) use ($term): void {
                    $userQuery->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                })->orWhereHas('listing', function ($listingQuery) use ($term): void {
                    $listingQuery->where('title', 'like', $term);
                })->orWhere('site', 'like', $term);

                // Digits-only search also matches applicant user id (ops often type the id).
                if ($raw !== '' && ctype_digit($raw)) {
                    $q->orWhere('user_id', (int) $raw);
                }
            });
        }

        $total = (clone $query)->count();
        $statusCounts = (clone $query)
            ->reorder()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $applications = $query->offset($offset)->limit($limit)->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'statusCounts' => $statusCounts,
            'applications' => $applications
                ->map(fn (Application $application): array => $this->buildApplicationOpsItem->handle($application))
                ->values()
                ->all(),
        ]);
    }

    public function show(Application $application): JsonResponse
    {
        $application->loadMissing(['user', 'listing', 'candidateListingMatch', 'docArtifacts', 'applyAttempts']);

        if ($application->user === null || $application->user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        return response()->json($this->buildApplicationOpsItem->handle($application));
    }
}
