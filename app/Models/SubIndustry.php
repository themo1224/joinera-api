<?php

namespace App\Models;

use Database\Factories\SubIndustryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'industry_id',
    'slug',
    'label_en',
    'label_de',
    'sort_order',
    'archived_at',
])]
class SubIndustry extends Model
{
    /** @use HasFactory<SubIndustryFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Industry, $this>
     */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toOpsArray(): array
    {
        return [
            'id' => $this->id,
            'industryId' => $this->industry_id,
            'slug' => $this->slug,
            'labelEn' => $this->label_en,
            'labelDe' => $this->label_de,
            'sortOrder' => $this->sort_order,
            'archivedAt' => $this->archived_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
