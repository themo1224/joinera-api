<?php

namespace Database\Factories;

use App\Models\SiteIdentity;
use App\Models\StaffAccessAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffAccessAudit>
 */
class StaffAccessAuditFactory extends Factory
{
    protected $model = StaffAccessAudit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $identity = SiteIdentity::factory();

        return [
            'actor_id' => User::factory()->staff(),
            'user_id' => User::factory(),
            'site' => 'azubiyo',
            'site_identity_id' => $identity,
            'application_id' => null,
            'reason' => 'Manual reveal for challenge resolution',
        ];
    }
}
