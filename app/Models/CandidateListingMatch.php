<?php

namespace App\Models;

use App\Enums\MatchHardSkipReason;
use App\Enums\MatchStatus;
use Database\Factories\CandidateListingMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'listing_id',
    'status',
    'hard_skip_reason',
    'soft_signals',
    'rank_score',
    'ranked_at',
    'queued_at',
    'force_queued_by',
    'force_queued_at',
])]
class CandidateListingMatch extends Model
{
    /** @use HasFactory<CandidateListingMatchFactory> */
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
     * @return BelongsTo<User, $this>
     */
    public function forceQueuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'force_queued_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'hard_skip_reason' => MatchHardSkipReason::class,
            'soft_signals' => 'array',
            'rank_score' => 'integer',
            'ranked_at' => 'datetime',
            'queued_at' => 'datetime',
            'force_queued_at' => 'datetime',
        ];
    }
}
