<?php

namespace App\Models;

use Database\Factories\BlockEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'site',
    'application_id',
    'observed_kind',
])]
class BlockEvent extends Model
{
    /** @use HasFactory<BlockEventFactory> */
    use HasFactory;

    public const KIND_CAPTCHA = 'captcha';

    public const KIND_TWO_FACTOR = 'two_factor';

    public const KIND_LOGIN_WALL = 'login_wall';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
