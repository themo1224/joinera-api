<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Events\CandidateMatchRecomputeRequested;
use App\Http\Requests\StoreCvRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Profile;
use App\Models\User;
use App\Services\CvStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffProfileController extends Controller
{
    private const MATCH_INPUT_FIELDS = ['industry', 'city', 'province', 'language', 'education'];

    public function __construct(private readonly CvStorage $cvStorage)
    {
    }

    public function show(User $user): JsonResponse
    {
        $profile = $user->ensureProfile();
        $user->loadMissing('cvAsset');

        return response()->json($profile->toStaffArray($user->cvAsset));
    }

    public function update(UpdateProfileRequest $request, User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $profile = $user->ensureProfile();
        $fields = $request->validatedFields();

        if ($fields !== []) {
            $before = $this->matchInputSnapshot($profile);
            $profile->fill($fields);
            $profile->markFieldsEdited(array_keys($fields));
            $profile->save();

            if ($before !== $this->matchInputSnapshot($profile)) {
                CandidateMatchRecomputeRequested::dispatch($user->id);
            }
        }

        $user->loadMissing('cvAsset');

        return response()->json($profile->fresh()->toStaffArray($user->cvAsset));
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        $profile = $user->ensureProfile();
        $wasApproved = $profile->approval === ApprovalStatus::Approved;
        $profile->approveOnce($request->user());

        if (! $wasApproved && $profile->approval === ApprovalStatus::Approved) {
            CandidateMatchRecomputeRequested::dispatch($user->id);
        }

        $user->loadMissing('cvAsset');

        return response()->json($profile->fresh()->toStaffArray($user->cvAsset));
    }

    public function storeCv(StoreCvRequest $request, User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $profile = $user->ensureProfile();
        $this->cvStorage->store($user, $request->file('cv'));
        $user->load('cvAsset');

        return response()->json($profile->fresh()->toStaffArray($user->cvAsset), 201);
    }

    /**
     * @return array<string, string|null>
     */
    private function matchInputSnapshot(Profile $profile): array
    {
        $snapshot = [];

        foreach (self::MATCH_INPUT_FIELDS as $field) {
            $value = $profile->{$field};
            $snapshot[$field] = is_string($value) ? $value : null;
        }

        return $snapshot;
    }
}
