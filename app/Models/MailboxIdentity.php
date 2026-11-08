<?php

namespace App\Models;

use App\Enums\MailboxState;
use Database\Factories\MailboxIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'domain', 'address', 'state'])]
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
     * @return array{domain: string, address: string, state: string}
     */
    public function toPublicArray(): array
    {
        return [
            'domain' => $this->domain,
            'address' => $this->address,
            'state' => $this->state->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => MailboxState::class,
        ];
    }
}
