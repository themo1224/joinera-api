<?php

namespace App\Models;

use App\Enums\DocumentAssetKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'kind', 'disk', 'path', 'original_name', 'mime', 'size'])]
class ProfileDocumentAsset extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DocumentAssetKind::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'kind' => $this->kind instanceof DocumentAssetKind ? $this->kind->value : (string) $this->kind,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }
}
