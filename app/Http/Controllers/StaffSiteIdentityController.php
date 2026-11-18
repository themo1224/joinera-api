<?php

namespace App\Http\Controllers;

use App\Exceptions\SitesUnconfiguredException;
use App\Exceptions\UnknownSiteException;
use App\Http\Requests\StoreSiteIdentityRequest;
use App\Models\User;
use App\Services\StoreSiteIdentity;
use Illuminate\Http\JsonResponse;

class StaffSiteIdentityController extends Controller
{
    public function __construct(private readonly StoreSiteIdentity $storeSiteIdentity)
    {
    }

    public function index(User $user): JsonResponse
    {
        $identities = $user->siteIdentities()
            ->orderBy('site')
            ->get()
            ->map(fn ($identity) => $identity->toPublicArray())
            ->values();

        return response()->json([
            'data' => $identities,
            'availableSites' => $this->configuredSites(),
        ]);
    }

    public function store(StoreSiteIdentityRequest $request, User $user): JsonResponse
    {
        try {
            $identity = $this->storeSiteIdentity->handle(
                $user,
                $request->string('site')->toString(),
                $request->string('site_login')->toString(),
                $request->string('secret')->toString(),
            );
        } catch (SitesUnconfiguredException) {
            return response()->json([
                'code' => 'sites_unconfigured',
                'message' => 'No job sites are configured.',
            ], 409);
        } catch (UnknownSiteException) {
            return response()->json([
                'code' => 'unknown_site',
                'message' => 'That site is not configured.',
            ], 422);
        }

        $status = $identity->wasRecentlyCreated ? 201 : 200;

        return response()->json($identity->toPublicArray(), $status);
    }

    /**
     * @return list<string>
     */
    private function configuredSites(): array
    {
        $sites = config('joinera.sites.v1', []);

        if (! is_array($sites)) {
            return [];
        }

        return array_values(array_filter(
            $sites,
            fn (mixed $site): bool => is_string($site) && $site !== '',
        ));
    }
}
