<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['started_at', 'finished_at'])]
class IntakeRun extends Model
{
    /**
     * @return HasMany<IntakeRunSite, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(IntakeRunSite::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
