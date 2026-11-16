<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Enums\Role;
use App\Exceptions\ApplyCapExceededException;
use App\Exceptions\MatchNotProposedException;
use App\Exceptions\MatchNotSkippedException;
use App\Http\Requests\ListMatchesRequest;
use App\Http\Requests\QueueSelectedMatchesRequest;
use App\Models\CandidateListingMatch;
use App\Models\User;
use App\Services\BuildMatchOpsItem;
use App\Services\ForceQueueCandidateListingMatch;
use App\Services\QueueSelectedCandidateListingMatches;
use App\Services\ResolveApplyCapUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class StaffMatchController extends Controller
{
    public function __construct(
        private readonly BuildMatchOpsItem $buildMatchOpsItem,
        private readonly ForceQueueCandidateListingMatch $forceQueue,
        private readonly QueueSelectedCandidateListingMatches $queueSelected,
        private readonly ResolveApplyCapUsage $resolveApplyCapUsage,
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

        $userId = null;
        if ($request->filled('userId')) {
            $userId = (int) $request->integer('userId');
            $query->where('user_id', $userId);
        }

        $total = (clone $query)->count();
        $matches = $query->offset($offset)->limit($limit)->get();

        $payload = [
            'success' => true,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'matches' => $matches
                ->map(fn (CandidateListingMatch $match): array => $this->buildMatchOpsItem->handle($match))
                ->values()
                ->all(),
        ];

        if ($userId !== null) {
            $candidate = User::query()->where('role', Role::User)->find($userId);
            if ($candidate !== null) {
                $payload['applyCap'] = $this->resolveApplyCapUsage->handle($candidate);
            }
        }

        return response()->json($payload);
    }

    public function queueSelected(QueueSelectedMatchesRequest $request): JsonResponse
    {
        $userId = (int) $request->integer('userId');
        /** @var list<int> $matchIds */
        $matchIds = array_map('intval', $request->input('matchIds', []));

        $candidate = User::query()->where('role', Role::User)->find($userId);

        if ($candidate === null) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        try {
            $result = $this->queueSelected->handle($candidate, $matchIds, $request->user());
        } catch (ApplyCapExceededException $exception) {
            return response()->json([
                'code' => 'apply_cap_reached',
                'message' => $exception->getMessage(),
                'applyCap' => $exception->applyCap,
            ], 409);
        } catch (MatchNotProposedException) {
            return response()->json([
                'code' => 'match_not_proposed',
                'message' => 'Only proposed matches can be queued.',
            ], 409);
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();
            if ($code === 'queue_selected_not_found') {
                return response()->json([
                    'code' => 'not_found',
                    'message' => 'Not found.',
                ], 404);
            }

            return response()->json([
                'code' => 'validation_error',
                'message' => 'Invalid queue selection.',
            ], 422);
        }

        return response()->json([
            'matches' => array_map(
                fn (CandidateListingMatch $match): array => $this->buildMatchOpsItem->handle($match),
                $result['matches'],
            ),
            'applications' => array_map(
                fn ($application): array => $this->buildMatchOpsItem->applicationPayload($application),
                $result['applications'],
            ),
            'applyCap' => $result['applyCap'],
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
        } catch (ApplyCapExceededException $exception) {
            return response()->json([
                'code' => 'apply_cap_reached',
                'message' => $exception->getMessage(),
                'applyCap' => $exception->applyCap,
            ], 409);
        } catch (MatchNotSkippedException) {
            return response()->json([
                'code' => 'match_not_skipped',
                'message' => 'Only skipped matches can be force-queued.',
            ], 409);
        }

        return response()->json([
            'match' => $this->buildMatchOpsItem->handle($result['match']),
            'application' => $this->buildMatchOpsItem->applicationPayload($result['application']),
            'applyCap' => $result['applyCap'] ?? null,
        ]);
    }
}
