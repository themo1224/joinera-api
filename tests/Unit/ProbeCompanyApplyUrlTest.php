<?php

namespace Tests\Unit;

use App\Services\ProbeCompanyApplyUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeCompanyApplyUrlTest extends TestCase
{
    public function test_404_is_dead(): void
    {
        Http::fake([
            'https://jobs.lidl.example.test/gone' => Http::response('Not Found', 404),
        ]);

        $this->assertTrue(app(ProbeCompanyApplyUrl::class)->isDead('https://jobs.lidl.example.test/gone'));
    }

    public function test_410_is_dead(): void
    {
        Http::fake([
            'https://jobs.lidl.example.test/gone' => Http::response('Gone', 410),
        ]);

        $this->assertTrue(app(ProbeCompanyApplyUrl::class)->isDead('https://jobs.lidl.example.test/gone'));
    }

    public function test_200_is_not_dead(): void
    {
        Http::fake([
            'https://karriere.firma.example.test/apply' => Http::response('<form></form>', 200),
        ]);

        $this->assertFalse(
            app(ProbeCompanyApplyUrl::class)->isDead('https://karriere.firma.example.test/apply'),
        );
    }

    public function test_403_is_not_hard_dead(): void
    {
        Http::fake([
            'https://karriere.firma.example.test/blocked' => Http::response('Forbidden', 403),
        ]);

        $this->assertFalse(
            app(ProbeCompanyApplyUrl::class)->isDead('https://karriere.firma.example.test/blocked'),
        );
    }

    public function test_meta_refresh_to_404_is_dead(): void
    {
        Http::fake([
            'https://www.aplitrak.example.test/*' => Http::response(
                '<meta http-equiv="refresh" content="0;url=\'https://employer.example.test/jobs/1/application-form/\'">',
                200,
            ),
            'https://employer.example.test/jobs/1/application-form/' => Http::response('Not Found', 404),
        ]);

        $this->assertTrue(
            app(ProbeCompanyApplyUrl::class)->isDead('https://www.aplitrak.example.test/?adid=x'),
        );
    }
}
