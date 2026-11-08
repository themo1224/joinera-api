<?php

namespace App\Http\Controllers;

use App\Exceptions\SharedSiteLoginException;
use App\Exceptions\SitesUnconfiguredException;
use App\Exceptions\UnknownSiteException;
use App\Http\Requests\StoreSiteIdentityRequest;
use App\Services\StoreSiteIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteIdentityController extends Controller
{
    public function __construct(private readonly StoreSiteIdentity $storeSiteIdentity)
    {
    }

    public function store(StoreSiteIdentityRequest $request): JsonResponse
    {
        try {
            $identity = $this->storeSiteIdentity->handle(
                $request->user(),
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
        } catch (SharedSiteLoginException) {
            return response()->json([
                'code' => 'shared_site_login',
                'message' => 'That site login is already used by another user.',
            ], 409);
        }

        $status = $identity->wasRecentlyCreated ? 201 : 200;

        return response()->json($identity->toPublicArray(), $status);
    }

    public function index(Request $request): JsonResponse
    {
        $identities = $request->user()
            ->siteIdentities()
            ->orderBy('site')
            ->get()
            ->map(fn ($identity) => $identity->toPublicArray())
            ->values();

        return response()->json(['data' => $identities]);
    }
}
