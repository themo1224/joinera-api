<?php

namespace App\Services;

use App\Enums\ParseStatus;
use App\Models\CvAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CvStorage
{
    public const DISK = 'local';

    public function store(User $user, UploadedFile $file): CvAsset
    {
        $path = sprintf('cvs/%d/%s.pdf', $user->id, (string) Str::uuid());

        Storage::disk(self::DISK)->put($path, $file->get());

        return DB::transaction(function () use ($user, $file, $path): CvAsset {
            $previous = $user->cvAsset;

            if ($previous !== null) {
                Storage::disk($previous->disk)->delete($previous->path);
                $previous->delete();
            }

            $asset = $user->cvAsset()->create([
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/pdf',
                'size' => $file->getSize() ?: 0,
            ]);

            $profile = $user->ensureProfile();
            $profile->parse_status = ParseStatus::Unparsed;
            $profile->save();

            return $asset;
        });
    }
}
