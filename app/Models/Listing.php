<?php

namespace App\Models;

use App\Enums\ListingKind;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source',
    'external_id',
    'fingerprint',
    'kind',
    'category',
    'title',
    'company',
    'city',
    'url',
    'apply_url',
    'description',
    'language_requirement',
    'education_requirement',
    'country',
    'posted_at',
    'first_seen_at',
    'last_seen_at',
])]
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    /**
     * @return HasMany<CandidateListingMatch, $this>
     */
    public function candidateListingMatches(): HasMany
    {
        return $this->hasMany(CandidateListingMatch::class);
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ListingKind::class,
            'posted_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
