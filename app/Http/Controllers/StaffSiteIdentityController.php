<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class StaffSiteIdentityController extends Controller
{
    public function index(User $user): JsonResponse
    {
        $identities = $user->siteIdentities()
            ->orderBy('site')
            ->get()
            ->map(fn ($identity) => $identity->toPublicArray())
            ->values();

        return response()->json(['data' => $identities]);
    }
}
