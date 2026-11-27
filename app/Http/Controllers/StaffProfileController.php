<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentAssetKind;
use App\Enums\Role;
use App\Events\CandidateMatchRecomputeRequested;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\CvMissingException;
use App\Exceptions\CvUnreadableException;
use App\Exceptions\DocumentTooLargeForAiException;
use App\Exceptions\DocumentsIncompleteException;
use App\Exceptions\LlmRequestException;
use App\Http\Requests\StoreCvRequest;
use App\Http\Requests\StoreProfileDocumentRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Profile;
use App\Models\User;
use App\Services\AnalyzeApplicantDocumentsWithAi;
use App\Services\CvStorage;
use App\Services\ParseProfileCv;
use App\Services\ParseProfileDocument;
use App\Services\ProfileDocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffProfileController extends Controller
{
    private const MATCH_INPUT_FIELDS = ['industry', 'language', 'education', 'desired_path'];

    public function __construct(
        private readonly CvStorage $cvStorage,
        private readonly ProfileDocumentStorage $documentStorage,
        private readonly ParseProfileCv $parseProfileCv,
        private readonly ParseProfileDocument $parseProfileDocument,
        private readonly AnalyzeApplicantDocumentsWithAi $analyzeApplicantDocumentsWithAi,
    ) {}

    public function show(User $user): JsonResponse
    {
        $profile = $user->ensureProfile();
        $user->loadMissing(['cvAsset', 'profileDocumentAssets']);

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
            $profile->syncEducationFromEntriesIfEmpty();
            $profile->markFieldsEdited(array_keys($fields));
            $profile->save();

            if ($before !== $this->matchInputSnapshot($profile)) {
                CandidateMatchRecomputeRequested::dispatch($user->id);
            }
        }

        $user->loadMissing(['cvAsset', 'profileDocumentAssets']);

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

        $user->loadMissing(['cvAsset', 'profileDocumentAssets']);

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
        $user->load(['cvAsset', 'profileDocumentAssets']);

        return response()->json($profile->fresh()->toStaffArray($user->cvAsset), 201);
    }

    public function parse(User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        try {
            $parsed = $this->parseProfileCv->handle($user);
        } catch (CvMissingException) {
            return response()->json([
                'code' => 'cv_missing',
                'message' => 'A CV must be uploaded before parsing.',
            ], 422);
        } catch (CvUnreadableException) {
            return response()->json([
                'code' => 'cv_unreadable',
                'message' => 'The CV could not be read.',
            ], 422);
        }

        $user->load(['cvAsset', 'profileDocumentAssets']);

        return response()->json([
            ...$parsed['profile']->toStaffArray($user->cvAsset),
            'ingestion' => $parsed['result']->ingestion,
        ]);
    }

    public function storeDocument(StoreProfileDocumentRequest $request, User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $kind = DocumentAssetKind::from((string) $request->validated('kind'));
        $asset = $this->documentStorage->store($user, $kind, $request->file('document'));

        $ingestion = null;
        $parseError = null;

        try {
            $parsed = $this->parseProfileDocument->handleAsset($user, $asset);
            $ingestion = $parsed['ingestion']->toStaffPayload();
        } catch (CvUnreadableException $e) {
            $parseError = $e->getMessage();
        }

        $user->load(['cvAsset', 'profileDocumentAssets']);
        $profile = $user->ensureProfile();

        return response()->json([
            ...$profile->fresh()->toStaffArray($user->cvAsset),
            'uploaded_document' => $asset->toPublicArray(),
            'ingestion' => $ingestion,
            'parse_error' => $parseError,
        ], 201);
    }

    public function analyzeDocuments(User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        try {
            $analyzed = $this->analyzeApplicantDocumentsWithAi->handle($user);
        } catch (AiUnavailableException $e) {
            return response()->json([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], 503);
        } catch (DocumentsIncompleteException $e) {
            return response()->json([
                'code' => 'documents_incomplete',
                'message' => $e->getMessage(),
            ], 422);
        } catch (DocumentTooLargeForAiException $e) {
            return response()->json([
                'code' => 'document_too_large',
                'message' => $e->getMessage(),
            ], 422);
        } catch (LlmRequestException $e) {
            if ($e->status === 402) {
                return response()->json([
                    'code' => AiUnavailableException::CODE_NO_CREDIT,
                    'message' => 'No AI credit remaining.',
                ], 503);
            }

            return response()->json([
                'code' => 'ai_analyze_failed',
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'AI document analyze failed.',
            ], 422);
        }

        $user->load(['cvAsset', 'profileDocumentAssets']);

        return response()->json([
            ...$analyzed['profile']->toStaffArray($user->cvAsset),
            'ingestion' => $analyzed['ingestion']->toStaffPayload(),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function matchInputSnapshot(Profile $profile): array
    {
        $snapshot = [];

        foreach (self::MATCH_INPUT_FIELDS as $field) {
            $value = $profile->{$field};
            if (is_array($value)) {
                $snapshot[$field] = array_values($value);
            } elseif (is_string($value)) {
                $snapshot[$field] = $value;
            } else {
                $snapshot[$field] = null;
            }
        }

        return $snapshot;
    }
}
