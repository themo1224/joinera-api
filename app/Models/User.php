<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * @return HasOne<CvAsset, $this>
     */
    public function cvAsset(): HasOne
    {
        return $this->hasOne(CvAsset::class);
    }

    /**
     * @return HasOne<MailboxIdentity, $this>
     */
    public function mailboxIdentity(): HasOne
    {
        return $this->hasOne(MailboxIdentity::class);
    }

    /**
     * @return HasMany<SiteIdentity, $this>
     */
    public function siteIdentities(): HasMany
    {
        return $this->hasMany(SiteIdentity::class);
    }

    public function ensureProfile(): Profile
    {
        return $this->profile()->firstOrCreate([]);
    }

    /**
     * @return array{id: int, name: string, email: string, role: string}
     */
    public function toAuthArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
        ];
    }
}
