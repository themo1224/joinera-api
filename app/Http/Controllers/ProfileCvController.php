<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCvRequest;
use App\Services\CvStorage;
use Illuminate\Http\JsonResponse;

class ProfileCvController extends Controller
{
    public function __construct(private readonly CvStorage $cvStorage)
    {
    }

    public function store(StoreCvRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->ensureProfile();

        $this->cvStorage->store($user, $request->file('cv'));

        $user->load('cvAsset');

        return response()->json($profile->fresh()->toOwnerArray($user->cvAsset), 201);
    }
}
