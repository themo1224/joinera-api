<?php

namespace App\Services;

use App\Enums\DocumentAssetKind;
use App\Exceptions\CvUnreadableException;
use App\Models\Profile;
use App\Models\ProfileDocumentAsset;
use App\Models\User;
use App\Services\Documents\DocumentIngestionPipeline;
use App\Support\CvParseResult;
use App\Support\Documents\DocumentIngestionResult;
use App\Support\MergeProfileLanguages;
use Illuminate\Support\Facades\Storage;

class ParseProfileDocument
{
    public function __construct(private readonly DocumentIngestionPipeline $pipeline)
    {
    }

    /**
     * @return array{profile: Profile, result: CvParseResult, ingestion: DocumentIngestionResult}
     *
     * @throws CvUnreadableException
     */
    public function handle(User $user, DocumentAssetKind $kind): array
    {
        $asset = $user->profileDocumentAssets()
            ->where('kind', $kind->value)
            ->first();

        if ($asset === null) {
            throw new CvUnreadableException('The document could not be read.');
        }

        return $this->handleAsset($user, $asset);
    }

    /**
     * @return array{profile: Profile, result: CvParseResult, ingestion: DocumentIngestionResult}
     *
     * @throws CvUnreadableException
     */
    public function handleAsset(User $user, ProfileDocumentAsset $asset): array
    {
        if (! Storage::disk($asset->disk)->exists($asset->path)) {
            throw new CvUnreadableException('The document could not be read.');
        }

        $absolute = Storage::disk($asset->disk)->path($asset->path);
        $mime = (string) ($asset->mime ?? '');

        // Images: wrap as single-page text via OCR path inside pipeline is limited;
        // for non-PDF, run a lightweight textless ingest by creating a tiny PDF is overkill —
        // instead OCR the image file directly when Tesseract is available via pipeline helper.
        if (! str_contains($mime, 'pdf') && ! str_ends_with(strtolower($asset->path), '.pdf')) {
            $ingestion = $this->pipeline->ingestImageFile($absolute, (string) $user->name);
        } else {
            $ingestion = $this->pipeline->ingestFile($absolute, (string) $user->name);
        }

        $result = CvParseResult::fromIngestion($ingestion);
        $profile = $user->ensureProfile();

        if (! $result->autoApplyBlocked) {
            $suggestedName = $result->effectiveSuggestedName();
            if ($suggestedName !== null) {
                $user->name = $suggestedName;
                $user->save();
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
            $profile->save();
        }

        return [
            'profile' => $profile->fresh() ?? $profile,
            'result' => $result,
            'ingestion' => $ingestion,
        ];
    }
}
