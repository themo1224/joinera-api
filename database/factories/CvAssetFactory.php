<?php

namespace Database\Factories;

use App\Models\CvAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CvAsset>
 */
class CvAssetFactory extends Factory
{
    protected $model = CvAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->slug(2).'-cv.pdf';

        return [
            'user_id' => User::factory(),
            'disk' => 'local',
            'path' => 'cvs/demo/'.$name,
            'original_name' => $name,
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(40_000, 900_000),
        ];
    }
}
