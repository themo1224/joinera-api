<?php

namespace App\Http\Controllers;

use App\Exceptions\CvMissingException;
use App\Exceptions\CvUnreadableException;
use App\Services\ParseProfileCv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileParseController extends Controller
{
    public function __construct(private readonly ParseProfileCv $parseProfileCv)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $profile = $this->parseProfileCv->handle($user);
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

        $user->load('cvAsset');

        return response()->json($profile->toOwnerArray($user->cvAsset));
    }
}
