<?php

namespace App\Models;

use Database\Factories\IndustryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug',
    'label_en',
    'label_de',
    'sort_order',
    'archived_at',
])]
class Industry extends Model
{
    /** @use HasFactory<IndustryFactory> */
    use HasFactory;

    /**
     * @return HasMany<SubIndustry, $this>
     */
    public function subIndustries(): HasMany
    {
        return $this->hasMany(SubIndustry::class)->orderBy('sort_order')->orderBy('id');
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
    public function toOpsArray(bool $includeSubIndustries = true): array
    {
        $payload = [
            'id' => $this->id,
            'slug' => $this->slug,
            'labelEn' => $this->label_en,
            'labelDe' => $this->label_de,
            'sortOrder' => $this->sort_order,
            'archivedAt' => $this->archived_at?->toISOString(),
        ];

        if ($includeSubIndustries) {
            $payload['subIndustries'] = $this->relationLoaded('subIndustries')
                ? $this->subIndustries->map(fn (SubIndustry $child) => $child->toOpsArray())->values()->all()
                : [];
        }

        return $payload;
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
