<?php

namespace App\Services;

use App\Contracts\CvParser;
use App\Enums\ParseStatus;
use App\Exceptions\CvMissingException;
use App\Exceptions\CvUnreadableException;
use App\Models\Profile;
use App\Models\User;
use App\Support\CvParseResult;

class ParseProfileCv
{
    public function __construct(private readonly CvParser $parser)
    {
    }

    /**
     * @throws CvUnreadableException
     * @throws CvMissingException
     */
    public function handle(User $user): Profile
    {
        $profile = $user->ensureProfile();
        $asset = $user->cvAsset;

        if ($asset === null) {
            throw new CvMissingException('A CV must be uploaded before parsing.');
        }

        try {
            $result = $this->parser->parse($asset);
        } catch (CvUnreadableException $exception) {
            $profile->parse_status = ParseStatus::ParseFailed;
            $profile->save();

            throw $exception;
        }

        $this->applyResult($profile, $result);
        $profile->parse_status = ParseStatus::Parsed;
        $profile->save();

        return $profile->fresh() ?? $profile;
    }

    private function applyResult(Profile $profile, CvParseResult $result): void
    {
        foreach ($result->presentFields() as $field => $value) {
            if ($profile->isFieldEdited($field)) {
                continue;
            }

            $profile->{$field} = $value;
        }
    }
}
