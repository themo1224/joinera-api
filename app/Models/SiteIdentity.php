<?php

namespace App\Models;

use App\Enums\SiteIdentityState;
use Database\Factories\SiteIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'site', 'site_login', 'secret', 'state', 'paused'])]
#[Hidden(['secret'])]
class SiteIdentity extends Model
{
    /** @use HasFactory<SiteIdentityFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{site: string, site_login: string, state: string}
     */
    public function toPublicArray(): array
    {
        return [
            'site' => $this->site,
            'site_login' => $this->site_login,
            'state' => $this->state->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'state' => SiteIdentityState::class,
            'paused' => 'boolean',
        ];
    }
}
