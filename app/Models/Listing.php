<?php

namespace App\Models;

use App\Enums\ListingKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'external_id',
    'fingerprint',
    'kind',
    'category',
    'title',
    'company',
    'city',
    'url',
    'first_seen_at',
    'last_seen_at',
])]
class Listing extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ListingKind::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
