<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Enums\Role;
use App\Exceptions\MatchNotSkippedException;
use App\Http\Requests\ListMatchesRequest;
use App\Models\CandidateListingMatch;
use App\Services\BuildMatchOpsItem;
use App\Services\ForceQueueCandidateListingMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffMatchController extends Controller
{
    public function __construct(
        private readonly BuildMatchOpsItem $buildMatchOpsItem,
        private readonly ForceQueueCandidateListingMatch $forceQueue,
    ) {
    }

    public function index(ListMatchesRequest $request): JsonResponse
    {
        $status = MatchStatus::from($request->string('status')->toString());
        $page = max(1, (int) $request->integer('page', 1));
        $limit = min(100, max(1, (int) $request->integer('limit', 20)));
        $offset = ($page - 1) * $limit;

        $query = CandidateListingMatch::query()
            ->where('status', $status)
            ->whereHas('user', fn ($q) => $q->where('role', Role::User))
            ->with(['user', 'listing', 'application', 'forceQueuedBy'])
            ->orderByDesc('id');

        if ($request->filled('userId')) {
            $query->where('user_id', (int) $request->integer('userId'));
        }

        $total = (clone $query)->count();
        $matches = $query->offset($offset)->limit($limit)->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'matches' => $matches
                ->map(fn (CandidateListingMatch $match): array => $this->buildMatchOpsItem->handle($match))
                ->values()
                ->all(),
        ]);
    }

    public function forceQueue(Request $request, CandidateListingMatch $match): JsonResponse
    {
        $match->loadMissing('user');

        if ($match->user === null || $match->user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        try {
            $result = $this->forceQueue->handle($match, $request->user());
        } catch (MatchNotSkippedException) {
            return response()->json([
                'code' => 'match_not_skipped',
                'message' => 'Only skipped matches can be force-queued.',
            ], 409);
        }

        return response()->json([
            'match' => $this->buildMatchOpsItem->handle($result['match']),
            'application' => $this->buildMatchOpsItem->applicationPayload($result['application']),
        ]);
    }
}
