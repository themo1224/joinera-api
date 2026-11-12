<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application pipeline row for one Candidate + listing.
 *
 * Default status is {@see ApplicationStatus::Matched} (PRD first pipeline stage).
 * Auto-promote matched → queued is a later plan — not done on create.
 */
#[Fillable([
    'user_id',
    'listing_id',
    'candidate_listing_match_id',
    'site',
    'status',
])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<CandidateListingMatch, $this>
     */
    public function candidateListingMatch(): BelongsTo
    {
        return $this->belongsTo(CandidateListingMatch::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
        ];
    }
}
