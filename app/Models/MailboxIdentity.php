<?php

namespace App\Models;

use App\Enums\MailboxState;
use Database\Factories\MailboxIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'domain',
    'address',
    'password',
    'provider',
    'state',
    'last_synced_at',
    'last_sync_error',
])]
#[Hidden(['password'])]
class MailboxIdentity extends Model
{
    /** @use HasFactory<MailboxIdentityFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MailboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class);
    }

    /**
     * @return array{
     *     domain: string,
     *     address: string,
     *     state: string,
     *     lastSyncedAt: string|null,
     *     lastSyncError: string|null
     * }
     */
    public function toPublicArray(): array
    {
        return [
            'domain' => $this->domain,
            'address' => $this->address,
            'state' => $this->state->value,
            'lastSyncedAt' => $this->last_synced_at?->toISOString(),
            'lastSyncError' => $this->last_sync_error,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'state' => MailboxState::class,
            'last_synced_at' => 'datetime',
        ];
    }
}
