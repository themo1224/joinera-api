<?php

namespace App\Models;

use App\Enums\ApplyAttemptKind;
use Database\Factories\ApplyAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id',
    'kind',
    'confirmation_reference',
    'stop_reason',
    'form_plan',
])]
class ApplyAttempt extends Model
{
    /** @use HasFactory<ApplyAttemptFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ApplyAttemptKind::class,
            'form_plan' => 'array',
        ];
    }
}
