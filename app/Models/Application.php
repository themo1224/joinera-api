<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Services\CreateQueuedApplicationFromMatch;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Application pipeline row for one Candidate + listing.
 *
 * Default status is {@see ApplicationStatus::Matched} (PRD first pipeline stage).
 * Staff queue-selected (or force-queue) promotes matched → queued via {@see CreateQueuedApplicationFromMatch}.
 * Docs prepare (queued → preparing_docs → applying) is owned by PrepareApplicationDocuments.
 * Browser submit (Mode B) starts only from applying with both DocArtifacts present.
 */
#[Fillable([
    'user_id',
    'listing_id',
    'candidate_listing_match_id',
    'site',
    'status',
    'docs_failure_code',
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
     * @return HasMany<DocArtifact, $this>
     */
    public function docArtifacts(): HasMany
    {
        return $this->hasMany(DocArtifact::class);
    }

    /**
     * @return HasMany<ApplyAttempt, $this>
     */
    public function applyAttempts(): HasMany
    {
        return $this->hasMany(ApplyAttempt::class);
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
