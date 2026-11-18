<?php

namespace App\Services;

use App\Contracts\CvParser;
use App\Enums\ParseStatus;
use App\Exceptions\CvMissingException;
use App\Exceptions\CvUnreadableException;
use App\Models\Profile;
use App\Models\User;
use App\Support\CvParseResult;
use App\Support\MergeProfileLanguages;

class ParseProfileCv
{
    public function __construct(private readonly CvParser $parser)
    {
    }

    /**
     * @return array{profile: Profile, result: CvParseResult}
     *
     * @throws CvUnreadableException
     * @throws CvMissingException
     */
    public function handle(User $user): array
    {
        $profile = $user->ensureProfile();
        $asset = $user->cvAsset;

        if ($asset === null) {
            throw new CvMissingException('A CV must be uploaded before parsing.');
        }

        $asset->loadMissing('user');

        try {
            $result = $this->parser->parse($asset);
        } catch (CvUnreadableException $exception) {
            $profile->parse_status = ParseStatus::ParseFailed;
            $profile->save();

            throw $exception;
        }

        $this->applyResult($user, $profile, $result);
        $profile->parse_status = ParseStatus::Parsed;
        $profile->save();
        $user->save();

        return [
            'profile' => $profile->fresh() ?? $profile,
            'result' => $result,
        ];
    }

    private function applyResult(User $user, Profile $profile, CvParseResult $result): void
    {
        foreach ($result->presentFields() as $field => $value) {
            if ($profile->isFieldEdited($field)) {
                continue;
            }

            $profile->{$field} = $value;
        }

        if ($result->autoApplyBlocked) {
            return;
        }

        $suggestedName = $result->effectiveSuggestedName();
        if ($suggestedName !== null) {
            $user->name = $suggestedName;
        }

        foreach ($result->presentStructuredFields() as $field => $value) {
            if ($profile->isFieldEdited($field)) {
                continue;
            }

            if ($field === 'languages' && is_array($value)) {
                $profile->languages = MergeProfileLanguages::merge(
                    is_array($profile->languages) ? $profile->languages : [],
                    $value
                );
                continue;
            }

            // Only fill empty structured arrays / null scalars.
            $current = $profile->{$field};
            if (is_array($current) && $current !== []) {
                continue;
            }
            if (is_string($current) && trim($current) !== '') {
                continue;
            }

            $profile->{$field} = $value;
        }

        $profile->syncEducationFromEntriesIfEmpty();
    }
}
