<?php

namespace Tests\Unit;

use App\Support\ScrubSecrets;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScrubSecretsTest extends TestCase
{
    #[Test]
    public function test_redacts_password_keys_nested_in_form_plan(): void
    {
        $plan = [
            'soft_signup' => [
                'attempted' => true,
                'email' => 'ada.apply@gmx.de',
                'password' => 'Jo!should-never-persist',
                'ok' => true,
            ],
            'answers' => [
                ['field_id' => 'vorname', 'value' => 'Ada'],
            ],
        ];

        $scrubbed = ScrubSecrets::array($plan);

        $this->assertSame('[redacted]', $scrubbed['soft_signup']['password'] ?? null);
        $this->assertSame('ada.apply@gmx.de', $scrubbed['soft_signup']['email'] ?? null);
        $this->assertSame('Ada', $scrubbed['answers'][0]['value'] ?? null);
    }
}
