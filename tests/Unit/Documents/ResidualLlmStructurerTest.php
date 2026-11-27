<?php

namespace Tests\Unit\Documents;

use App\Services\Ai\FakeLlmClient;
use App\Services\Documents\ResidualLlmStructurer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResidualLlmStructurerTest extends TestCase
{
    #[Test]
    public function it_fills_gaps_including_name_and_dob_when_enabled(): void
    {
        config(['joinera.documents.residual_llm' => true]);

        $fake = new FakeLlmClient;
        $fake->queueContent(json_encode([
            'suggested_name' => 'Othmane El Badaoui',
            'date_of_birth' => '1998-04-12',
            'education' => 'Fachhochschulreife',
            'languages' => [
                ['language' => 'Deutsch', 'level' => 'B2'],
                ['language' => 'Englisch', 'level' => 'B1'],
            ],
            'experiences' => [
                ['title' => 'Koch', 'organization' => 'Hotel Beispiel', 'current' => false],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $structurer = new ResidualLlmStructurer($fake);
        $result = $structurer->fillGaps(
            "Lebenslauf\nName: Othmane El Badaoui\nGeburtsdatum: 12.04.1998\nDeutsch B2 Englisch B1\nKoch bei Hotel Beispiel",
            ['languages' => [['language' => 'Deutsch', 'level' => 'B2']]]
        );

        $this->assertSame('ran', $result['status']);
        $this->assertSame('Othmane El Badaoui', $result['fields']['suggested_name'] ?? null);
        $this->assertSame('1998-04-12', $result['fields']['date_of_birth'] ?? null);
        $this->assertSame('Fachhochschulreife', $result['fields']['education'] ?? null);
        $this->assertCount(2, $result['fields']['languages'] ?? []);
        $this->assertNotEmpty($result['fields']['experiences'] ?? []);
    }

    #[Test]
    public function it_maps_name_alias_to_suggested_name(): void
    {
        config(['joinera.documents.residual_llm' => true]);

        $fake = new FakeLlmClient;
        $fake->queueContent(json_encode(['name' => 'Anna Schmidt'], JSON_UNESCAPED_UNICODE));

        $structurer = new ResidualLlmStructurer($fake);
        $result = $structurer->fillGaps('Lebenslauf Anna Schmidt', []);

        $this->assertSame('ran', $result['status']);
        $this->assertSame('Anna Schmidt', $result['fields']['suggested_name'] ?? null);
    }

    #[Test]
    public function it_skips_empty_text_without_inventing_fields(): void
    {
        config(['joinera.documents.residual_llm' => true]);

        $fake = new FakeLlmClient;
        $fake->queueContent(json_encode([
            'suggested_name' => 'Should Not Appear',
            'languages' => [['language' => 'Deutsch', 'level' => 'C2']],
        ], JSON_UNESCAPED_UNICODE));

        $structurer = new ResidualLlmStructurer($fake);
        $result = $structurer->fillGaps("   \n\t  ", []);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame([], $result['fields']);
    }

    #[Test]
    public function it_returns_failed_when_llm_throws(): void
    {
        config(['joinera.documents.residual_llm' => true]);

        $structurer = new ResidualLlmStructurer(new FakeLlmClient);
        $result = $structurer->fillGaps('Text with '.FakeLlmClient::FAIL_MARKER, []);

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['fields']);
    }

    #[Test]
    public function it_returns_disabled_when_config_off(): void
    {
        config(['joinera.documents.residual_llm' => false]);

        $fake = new FakeLlmClient;
        $fake->queueContent(json_encode(['suggested_name' => 'Nope'], JSON_UNESCAPED_UNICODE));

        $structurer = new ResidualLlmStructurer($fake);
        $result = $structurer->fillGaps('Lebenslauf with content', []);

        $this->assertSame('disabled', $result['status']);
        $this->assertSame([], $result['fields']);
    }

    #[Test]
    public function it_fills_experiences_and_inferred_skills_when_heuristic_empty(): void
    {
        config(['joinera.documents.residual_llm' => true]);

        $fake = new FakeLlmClient;
        $fake->queueContent(json_encode([
            'experiences' => [
                ['title' => 'Servicekraft', 'organization' => 'Restaurant MM', 'start' => '2026-07-06', 'end' => '2026-08-10', 'current' => false],
                ['title' => 'Commis', 'organization' => 'Mazagan Beach & Golf Resort', 'current' => false],
                ['title' => 'Grillgehilfe', 'organization' => 'Grillrestaurant', 'current' => false],
            ],
            'skills' => [
                ['name' => 'HACCP', 'level' => null, 'source' => 'inferred'],
                ['name' => 'Service', 'level' => null, 'source' => 'inferred'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $text = "Lebenslauf Yassine\nArbeitete bei Restaurant MM, Mazagan Beach & Golf Resort und Grillrestaurant. Profil mit HACCP.";
        $structurer = new ResidualLlmStructurer($fake);
        $result = $structurer->fillGaps($text, [
            'experiences' => [],
            'skills' => [],
        ]);

        $this->assertSame('ran', $result['status']);
        $this->assertGreaterThanOrEqual(3, count($result['fields']['experiences'] ?? []));
        $this->assertGreaterThanOrEqual(1, count($result['fields']['skills'] ?? []));
        $skillNames = mb_strtolower(collect($result['fields']['skills'])->pluck('name')->implode(' '));
        $this->assertTrue(str_contains($skillNames, 'haccp') || str_contains($skillNames, 'service'));
    }
}
