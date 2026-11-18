<?php

namespace App\Services;

use App\Enums\DocumentAssetKind;
use App\Models\ProfileDocumentAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileDocumentStorage
{
    public const DISK = 'local';

    public function store(User $user, DocumentAssetKind $kind, UploadedFile $file): ProfileDocumentAsset
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'pdf';
        }

        $path = sprintf(
            'profile-docs/%d/%s/%s.%s',
            $user->id,
            $kind->value,
            (string) Str::uuid(),
            $extension,
        );

        Storage::disk(self::DISK)->put($path, $file->get());

        return DB::transaction(function () use ($user, $kind, $file, $path): ProfileDocumentAsset {
            $previous = $user->profileDocumentAssets()
                ->where('kind', $kind->value)
                ->first();

            if ($previous !== null) {
                Storage::disk($previous->disk)->delete($previous->path);
                $previous->delete();
            }

            return $user->profileDocumentAssets()->create([
                'kind' => $kind,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/pdf',
                'size' => $file->getSize() ?: 0,
            ]);
        });
    }
}
