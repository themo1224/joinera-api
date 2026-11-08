<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProfilePrioritiesChanged
{
    use Dispatchable, SerializesModels;

    public const NAME = 'profile.priorities_changed';

    /**
     * @param  list<string>  $priorities
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $priorities,
    ) {
    }
}
