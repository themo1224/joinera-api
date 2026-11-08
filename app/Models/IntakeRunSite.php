<?php

namespace App\Models;

use App\Enums\IntakeRunSiteStatus;
use Database\Factories\IntakeRunSiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['intake_run_id', 'site', 'status', 'error', 'listings_created'])]
class IntakeRunSite extends Model
{
    /** @use HasFactory<IntakeRunSiteFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<IntakeRun, $this>
     */
    public function intakeRun(): BelongsTo
    {
        return $this->belongsTo(IntakeRun::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntakeRunSiteStatus::class,
            'listings_created' => 'integer',
        ];
    }
}
