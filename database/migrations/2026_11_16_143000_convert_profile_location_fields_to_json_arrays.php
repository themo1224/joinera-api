<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['industry', 'city', 'province'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            $this->backfillColumn($column);
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            foreach (self::COLUMNS as $column) {
                DB::statement("ALTER TABLE profiles ALTER COLUMN {$column} DROP DEFAULT");
                DB::statement("ALTER TABLE profiles ALTER COLUMN {$column} TYPE json USING CASE WHEN {$column} IS NULL OR btrim({$column}::text) = '' THEN '[]'::json ELSE {$column}::json END");
                DB::statement("ALTER TABLE profiles ALTER COLUMN {$column} DROP NOT NULL");
            }
        } else {
            Schema::table('profiles', function (Blueprint $table): void {
                $table->json('industry')->nullable()->change();
                $table->json('city')->nullable()->change();
                $table->json('province')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            $this->flattenColumn($column);
        }

        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('industry')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('province')->nullable()->change();
        });
    }

    private function backfillColumn(string $column): void
    {
        DB::table('profiles')->orderBy('id')->chunkById(100, function ($rows) use ($column): void {
            foreach ($rows as $row) {
                $encoded = $this->toJsonArray($row->{$column} ?? null);
                DB::table('profiles')->where('id', $row->id)->update([$column => $encoded]);
            }
        });
    }

    private function flattenColumn(string $column): void
    {
        DB::table('profiles')->orderBy('id')->chunkById(100, function ($rows) use ($column): void {
            foreach ($rows as $row) {
                $flat = $this->toScalar($row->{$column} ?? null);
                DB::table('profiles')->where('id', $row->id)->update([$column => $flat]);
            }
        });
    }

    private function toJsonArray(mixed $value): string
    {
        if ($value === null) {
            return '[]';
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '[]';
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $list = [];
                foreach ($decoded as $item) {
                    if (is_string($item) || is_numeric($item)) {
                        $itemTrimmed = trim((string) $item);
                        if ($itemTrimmed !== '') {
                            $list[] = $itemTrimmed;
                        }
                    }
                }

                return json_encode(array_values(array_unique($list)), JSON_UNESCAPED_UNICODE) ?: '[]';
            }

            return json_encode([$trimmed], JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        return '[]';
    }

    private function toScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $first = null;
                foreach ($decoded as $item) {
                    if (is_string($item) || is_numeric($item)) {
                        $itemTrimmed = trim((string) $item);
                        if ($itemTrimmed !== '') {
                            $first = $itemTrimmed;
                            break;
                        }
                    }
                }

                return $first;
            }

            return $trimmed;
        }

        return null;
    }
};
