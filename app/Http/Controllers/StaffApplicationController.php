<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ListApplicationsRequest;
use App\Models\Application;
use App\Services\BuildApplicationOpsItem;
use App\Services\ManualCompleteApplication;
use App\Services\RetryApplyApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffApplicationController extends Controller
{
    public function __construct(
        private readonly BuildApplicationOpsItem $buildApplicationOpsItem,
        private readonly RetryApplyApplication $retryApplyApplication,
        private readonly ManualCompleteApplication $manualCompleteApplication,
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

    public function retryApply(Application $application): JsonResponse
    {
        $application->loadMissing(['user']);

        if ($application->user === null || $application->user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $result = $this->retryApplyApplication->handle($application);
        if (! $result['ok']) {
            $messages = [
                'application_already_submitted' => 'Application is already submitted.',
                'retry_apply_wrong_status' => 'Apply can only be retried while status is applying.',
                'apply_in_progress' => 'An apply attempt is already in progress.',
                'docs_not_ready' => 'Documents are not ready for Mode B apply.',
                'profile_not_approved' => 'Applicant profile is not approved.',
                'site_not_ready' => 'No healthy site identity for this application.',
            ];

            return response()->json([
                'code' => $result['code'],
                'message' => $messages[$result['code']] ?? 'Retry apply is not allowed.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'applicationId' => $application->id,
        ], 202);
    }

    public function manualComplete(Request $request, Application $application): JsonResponse
    {
        $application->loadMissing(['user']);

        if ($application->user === null || $application->user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $result = $this->manualCompleteApplication->handle($application, $request->user());
        if (! $result['ok']) {
            $messages = [
                'not_eligible' => 'Application is not eligible for manual complete.',
                'not_found' => 'Not found.',
            ];

            $status = $result['code'] === 'not_found' ? 404 : 409;

            return response()->json([
                'code' => $result['code'],
                'message' => $messages[$result['code']] ?? 'Manual complete is not allowed.',
            ], $status);
        }

        return response()->json([
            'success' => true,
            'alreadySubmitted' => $result['alreadySubmitted'],
            'application' => $this->buildApplicationOpsItem->handle($result['application']),
        ]);
    }
}
