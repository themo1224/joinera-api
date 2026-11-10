<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\Role;
use App\Http\Requests\StoreApplicantRequest;
use App\Models\User;
use App\Services\BuildApplicantDirectoryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicantController extends Controller
{
    public function __construct(
        private readonly BuildApplicantDirectoryItem $directoryItem,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $limit = min(100, max(1, (int) $request->integer('limit', 10)));
        $offset = ($page - 1) * $limit;

        $query = User::query()
            ->where('role', Role::User)
            ->with(['profile.approvedBy', 'cvAsset', 'mailboxIdentity']);

        $this->applySearch($query, $request->string('search')->toString());
        $this->applyApprovalFilter($query, $request->string('approvals')->toString());
        $this->applyMailboxStateFilter($query, $request->string('mailboxStates')->toString());
        $this->applySort($query, $request->string('sort')->toString());

        $users = $query->get();

        $readinessFilter = $this->csvValues($request->string('readiness')->toString());

        $items = $users
            ->map(fn (User $user): array => $this->directoryItem->handle($user))
            ->when(
                $readinessFilter !== [],
                fn ($collection) => $collection->filter(
                    fn (array $item): bool => in_array($item['readiness'], $readinessFilter, true),
                )->values(),
            );

        $total = $items->count();
        $pageItems = $items->slice($offset, $limit)->values();

        return response()->json([
            'success' => true,
            'time' => now()->toISOString(),
            'message' => 'OK',
            'total_users' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'users' => $pageItems,
        ]);
    }

    public function store(StoreApplicantRequest $request): JsonResponse
    {
        $applicant = DB::transaction(function () use ($request): User {
            $user = User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'role' => Role::User,
            ]);

            $user->ensureProfile();

            return $user->fresh(['profile.approvedBy', 'cvAsset', 'mailboxIdentity']) ?? $user;
        });

        return response()->json($this->directoryItem->handle($applicant), 201);
    }

    public function show(User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        return response()->json($this->directoryItem->handle($user));
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        DB::transaction(function () use ($user): void {
            $user->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Candidate deleted. GMX account was not deleted.',
        ]);
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $term = trim($search);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('name', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyApprovalFilter(Builder $query, string $raw): void
    {
        $values = array_values(array_filter(
            $this->csvValues($raw),
            fn (string $value): bool => ApprovalStatus::tryFrom($value) !== null,
        ));

        if ($values === []) {
            return;
        }

        $query->whereHas('profile', function (Builder $profile) use ($values): void {
            $profile->whereIn('approval', $values);
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyMailboxStateFilter(Builder $query, string $raw): void
    {
        $values = array_values(array_filter(
            $this->csvValues($raw),
            fn (string $value): bool => MailboxState::tryFrom($value) !== null,
        ));

        if ($values === []) {
            return;
        }

        $includesPending = in_array(MailboxState::Pending->value, $values, true);
        $other = array_values(array_filter(
            $values,
            fn (string $value): bool => $value !== MailboxState::Pending->value,
        ));

        $query->where(function (Builder $outer) use ($includesPending, $other): void {
            if ($other !== []) {
                $outer->whereHas('mailboxIdentity', function (Builder $mailbox) use ($other): void {
                    $mailbox->whereIn('state', $other);
                });
            }

            if ($includesPending) {
                $method = $other === [] ? 'where' : 'orWhere';
                $outer->{$method}(function (Builder $pending) {
                    $pending->whereDoesntHave('mailboxIdentity')
                        ->orWhereHas('mailboxIdentity', function (Builder $mailbox): void {
                            $mailbox->where('state', MailboxState::Pending);
                        });
                });
            }
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');

        if ($field === 'name') {
            $query->orderBy('name', $direction);

            return;
        }

        if ($field === 'email') {
            $query->orderBy('email', $direction);

            return;
        }

        $query->orderBy('created_at', $direction === 'asc' && $field === 'createdAt' ? 'asc' : 'desc');
    }

    /**
     * @return list<string>
     */
    private function csvValues(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(',', $raw),
        )));
    }
}
