<?php

namespace Tests\Unit;

use App\Support\EducationRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EducationRequirementTest extends TestCase
{
    private EducationRequirement $ranks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ranks = new EducationRequirement;
    }

    public function test_null_requirement_passes_for_any_candidate(): void
    {
        $this->assertTrue($this->ranks->satisfies(null, null));
        $this->assertTrue($this->ranks->satisfies('Master', null));
        $this->assertTrue($this->ranks->satisfies('Hauptschulabschluss', null));
    }

    public function test_beliebig_requirement_passes_like_null(): void
    {
        $this->assertTrue($this->ranks->satisfies(null, 'beliebig'));
        $this->assertTrue($this->ranks->satisfies('Master', 'beliebig'));
        $this->assertTrue($this->ranks->satisfies('Hauptschulabschluss', 'Beliebig'));
        $this->assertTrue($this->ranks->satisfies('', 'beliebig'));
    }

    public function test_fachabitur_aliases_to_fachhochschulreife_rank(): void
    {
        $this->assertSame(
            $this->ranks->rank('Fachhochschulreife'),
            $this->ranks->rank('Fachabitur'),
        );
        $this->assertSame(3, $this->ranks->rank('Fachabitur'));
        $this->assertSame('fachhochschulreife', $this->ranks->normalize('Fachabitur'));
    }

    public function test_master_passes_fachabitur_and_beliebig(): void
    {
        $this->assertTrue($this->ranks->satisfies('Master', 'Fachabitur'));
        $this->assertTrue($this->ranks->satisfies('Master', 'beliebig'));
    }

    public function test_below_fachabitur_tier_still_fails(): void
    {
        $this->assertFalse($this->ranks->satisfies('Mittlere Reife', 'Fachabitur'));
        $this->assertFalse($this->ranks->satisfies(null, 'Fachabitur'));
    }

    #[DataProvider('abiturTierProvider')]
    public function test_abitur_tier_satisfies_fachabitur(string $candidate): void
    {
        $this->assertTrue($this->ranks->satisfies($candidate, 'Fachabitur'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function abiturTierProvider(): array
    {
        return [
            'abitur' => ['Abitur'],
            'fachhochschulreife' => ['Fachhochschulreife'],
            'fachabitur' => ['Fachabitur'],
            'bachelor' => ['Bachelor'],
            'master' => ['Master'],
        ];
    }
}
