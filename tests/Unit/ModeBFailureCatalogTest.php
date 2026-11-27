<?php

namespace Tests\Unit;

use App\Enums\ApplyAttemptKind;
use App\Support\ModeBFailureCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModeBFailureCatalogTest extends TestCase
{
    #[DataProvider('knownCodesProvider')]
    public function test_known_codes_have_staff_labels(string $code, string $expectSnippet): void
    {
        $mapped = ModeBFailureCatalog::resolve($code, ApplyAttemptKind::HardFail);

        $this->assertSame($code, $mapped['code']);
        $this->assertStringContainsString($expectSnippet, $mapped['labelEn']);
        $this->assertNotSame('', $mapped['labelDe']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function knownCodesProvider(): array
    {
        return [
            'captcha' => ['captcha', 'Captcha'],
            'login_wall' => ['login_wall', 'account login'],
            'missing_company_apply_url' => ['missing_company_apply_url', 'board-only'],
            'apply_url_not_found' => ['apply_url_not_found', 'not found'],
            'profile_incomplete' => ['profile_incomplete', 'missing required apply'],
            'mailbox_not_ready' => ['mailbox_not_ready', 'GMX apply mailbox'],
            'confirmation_not_found' => ['confirmation_not_found', 'thank-you'],
            'empty_document_upload' => ['empty_document_upload', 'empty on a present form slot'],
            'missing_artifacts' => ['missing_artifacts', 'CV or cover letter'],
            'worker_timeout' => ['worker_timeout', 'browser worker timed out'],
            'softgarden_personal_incomplete' => ['softgarden_personal_incomplete', 'personal step incomplete'],
            'softgarden_cv_not_ready' => ['softgarden_cv_not_ready', 'CV not accepted'],
            'softgarden_waiting_letzten' => ['softgarden_waiting_letzten', 'next-step CTA'],
        ];
    }

    public function test_missing_info_lists_field_labels(): void
    {
        $mapped = ModeBFailureCatalog::resolve(
            'missing_info:Telefon|Zeugnis',
            ApplyAttemptKind::HardFail,
        );

        $this->assertSame('missing_info', $mapped['code']);
        $this->assertStringContainsString('Telefon|Zeugnis', $mapped['labelEn']);
        $this->assertFalse($mapped['needsHelp']);
    }

    public function test_block_kinds_need_help(): void
    {
        $mapped = ModeBFailureCatalog::forBlockKind('cookie_wall');

        $this->assertSame('cookie_wall', $mapped['code']);
        $this->assertTrue($mapped['needsHelp']);
        $this->assertSame('block', $mapped['severity']);
    }
}
