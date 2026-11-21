<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ListBlockEventsRequest;
use App\Models\BlockEvent;
use App\Models\SiteIdentity;
use App\Services\BuildBlockAlertOpsItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class StaffBlockEventController extends Controller
{
    public function __construct(
        private readonly BuildBlockAlertOpsItem $buildBlockAlertOpsItem,
    ) {}

    public function index(ListBlockEventsRequest $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $limit = min(100, max(1, (int) $request->integer('limit', 20)));
        $offset = ($page - 1) * $limit;

        $base = BlockEvent::query()
            ->whereHas('user', fn ($q) => $q->where('role', Role::User));

        $kinds = $request->kinds();
        if ($kinds !== []) {
            $base->whereIn('observed_kind', $kinds);
        }

        if ($request->filled('search')) {
            $raw = trim($request->string('search')->toString());
            $term = '%'.$raw.'%';
            $base->where(function (Builder $q) use ($raw, $term): void {
                $q->whereHas('user', function (Builder $userQuery) use ($term): void {
                    $userQuery->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                })->orWhere('site', 'like', $term);

                if ($raw !== '' && ctype_digit($raw)) {
                    $q->orWhere('user_id', (int) $raw)
                        ->orWhere('application_id', (int) $raw)
                        ->orWhere('id', (int) $raw);
                }
            });
        }

        // Plan 25 — open = unresolved BlockEvent (independent of board SiteIdentity pause).
        $openCount = (clone $base)->whereNull('resolved_at')->count();

        $opens = $request->opens();
        // Default: open alerts only when no open filter is provided.
        if ($opens === []) {
            $base->whereNull('resolved_at');
        } else {
            $wantOpen = in_array('true', $opens, true);
            $wantClosed = in_array('false', $opens, true);

            if ($wantOpen && ! $wantClosed) {
                $base->whereNull('resolved_at');
            } elseif ($wantClosed && ! $wantOpen) {
                $base->whereNotNull('resolved_at');
            }
        }

        $this->applySort($base, $request->input('sort'));

        $total = (clone $base)->count();
        $events = $base->with(['user', 'application.listing'])->offset($offset)->limit($limit)->get();

        if ($events->isEmpty()) {
            $identities = collect();
        } else {
            $identities = SiteIdentity::query()
                ->where(function (Builder $q) use ($events): void {
                    foreach ($events as $event) {
                        $q->orWhere(function (Builder $inner) use ($event): void {
                            $inner->where('user_id', $event->user_id)
                                ->where('site', $event->site);
                        });
                    }
                })
                ->get()
                ->keyBy(fn (SiteIdentity $identity): string => $identity->user_id.'|'.$identity->site);
        }

        return response()->json([
            'success' => true,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'openCount' => $openCount,
            'alerts' => $events
                ->map(function (BlockEvent $event) use ($identities): array {
                    $key = $event->user_id.'|'.$event->site;

                    return $this->buildBlockAlertOpsItem->handle(
                        $event,
                        $identities->get($key),
                    );
                })
                ->values()
                ->all(),
        ]);
    }

    private function applySort(Builder $query, mixed $sort): void
    {
        $query->orderByDesc('block_events.id');

        if (! is_string($sort) || trim($sort) === '') {
            return;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($sort, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (! is_array($decoded) || $decoded === []) {
            return;
        }

        $first = $decoded[0] ?? null;
        if (! is_array($first) || ! isset($first['id']) || ! is_string($first['id'])) {
            return;
        }

        $column = match ($first['id']) {
            'createdAt', 'id' => 'block_events.id',
            'kind' => 'block_events.observed_kind',
            'siteId' => 'block_events.site',
            default => null,
        };

        if ($column === null) {
            return;
        }

        $query->reorder();
        $desc = ($first['desc'] ?? false) === true;
        $query->orderBy($column, $desc ? 'desc' : 'asc');
    }
}
