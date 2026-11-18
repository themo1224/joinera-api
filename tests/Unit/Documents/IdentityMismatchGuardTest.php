<?php

namespace Tests\Unit\Documents;

use App\Services\Documents\IdentityMismatchGuard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdentityMismatchGuardTest extends TestCase
{
    #[Test]
    public function it_warns_but_allows_when_names_differ(): void
    {
        $guard = new IdentityMismatchGuard;
        $result = $guard->evaluate('Yassine Moussaid', ['Yassine Almou']);

        $this->assertTrue($result['ok']);
        $this->assertSame('name_mismatch', $result['reason'] ?? null);
        $this->assertNotNull($result['message']);
    }

    #[Test]
    public function it_allows_compatible_name_order(): void
    {
        $guard = new IdentityMismatchGuard;
        $result = $guard->evaluate('Chaimae Modden Malki', ['Modden Malki Chaimae']);

        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function it_blocks_multi_person_pack(): void
    {
        $guard = new IdentityMismatchGuard;
        $result = $guard->evaluate('Othmane El Badaoui', ['Othmane El Badaoui', 'Yassine Almou']);

        $this->assertFalse($result['ok']);
    }
}
