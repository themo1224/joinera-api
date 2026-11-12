<?php

namespace App\Models;

use App\Enums\ListingKind;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    'apply_url',
    'description',
    'language_requirement',
    'education_requirement',
    'country',
    'posted_at',
    'first_seen_at',
    'last_seen_at',
])]
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ListingKind::class,
            'posted_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
