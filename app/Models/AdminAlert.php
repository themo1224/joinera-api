<?php

namespace App\Models;

use Database\Factories\AdminAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'site', 'payload'])]
class AdminAlert extends Model
{
    /** @use HasFactory<AdminAlertFactory> */
    use HasFactory;

    public const KIND_INTAKE_SITE_REPEATED_FAILURE = 'intake_site_repeated_failure';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
