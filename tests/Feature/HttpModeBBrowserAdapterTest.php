<?php

namespace Tests\Feature;

use App\Enums\ModeBBrowserOutcome;
use App\Services\Apply\HttpModeBBrowserAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpModeBBrowserAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.mode_b.worker_url' => 'http://mode-b-worker.test',
            'joinera.mode_b.worker_secret' => 'test-secret',
            'joinera.mode_b.timeout' => 5,
        ]);
    }

    public function test_maps_success_json_to_mode_b_result(): void
    {
        Http::fake([
            'http://mode-b-worker.test/submit' => Http::response([
                'outcome' => 'success',
                'confirmation_reference' => 'http-ref-9',
            ], 200),
        ]);

        $result = app(HttpModeBBrowserAdapter::class)->submit(1, 'azubiyo', 9);

        $this->assertSame(ModeBBrowserOutcome::Success, $result->outcome);
        $this->assertSame('http-ref-9', $result->confirmationReference);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://mode-b-worker.test/submit'
                && $request->header('X-Mode-B-Worker-Secret')[0] === 'test-secret'
                && $request['user_id'] === 1
                && $request['site_id'] === 'azubiyo'
                && $request['application_id'] === 9;
        });
    }

    public function test_maps_challenge_and_retryable_outcomes(): void
    {
        Http::fake([
            'http://mode-b-worker.test/submit' => Http::sequence()
                ->push(['outcome' => 'challenge', 'detail' => 'captcha'], 200)
                ->push(['outcome' => 'retryable', 'detail' => 'timeout'], 200)
                ->push(['outcome' => 'identity_mismatch', 'detail' => 'bad'], 200),
        ]);

        $adapter = app(HttpModeBBrowserAdapter::class);

        $challenge = $adapter->submit(1, 'azubiyo', 1);
        $this->assertSame(ModeBBrowserOutcome::Challenge, $challenge->outcome);

        $retryable = $adapter->submit(1, 'azubiyo', 1);
        $this->assertSame(ModeBBrowserOutcome::Retryable, $retryable->outcome);

        $mismatch = $adapter->submit(1, 'azubiyo', 1);
        $this->assertSame(ModeBBrowserOutcome::IdentityMismatch, $mismatch->outcome);
    }

    public function test_http_failure_is_retryable(): void
    {
        Http::fake([
            'http://mode-b-worker.test/submit' => Http::response(['error' => 'down'], 503),
        ]);

        $result = app(HttpModeBBrowserAdapter::class)->submit(1, 'azubiyo', 1);

        $this->assertSame(ModeBBrowserOutcome::Retryable, $result->outcome);
    }

    public function test_maps_form_plan_on_identity_mismatch(): void
    {
        Http::fake([
            'http://mode-b-worker.test/submit' => Http::response([
                'outcome' => 'identity_mismatch',
                'detail' => 'form_plan_ready',
                'form_plan' => [
                    'version' => 1,
                    'ready' => true,
                    'answers' => [['field_id' => 'email', 'value' => 'a@gmx.de']],
                ],
            ], 200),
        ]);

        $result = app(HttpModeBBrowserAdapter::class)->submit(1, 'azubiyo', 1);

        $this->assertSame(ModeBBrowserOutcome::IdentityMismatch, $result->outcome);
        $this->assertSame('form_plan_ready', $result->detail);
        $this->assertTrue($result->formPlan['ready'] ?? false);
        $this->assertSame('a@gmx.de', $result->formPlan['answers'][0]['value'] ?? null);
    }

    public function test_maps_form_plan_on_success(): void
    {
        Http::fake([
            'http://mode-b-worker.test/submit' => Http::response([
                'outcome' => 'success',
                'confirmation_reference' => 'company-mock-1',
                'form_plan' => [
                    'version' => 1,
                    'ready' => true,
                    'fill' => ['typed' => ['vorname']],
                ],
            ], 200),
        ]);

        $result = app(HttpModeBBrowserAdapter::class)->submit(1, 'azubiyo', 1);

        $this->assertSame(ModeBBrowserOutcome::Success, $result->outcome);
        $this->assertSame('company-mock-1', $result->confirmationReference);
        $this->assertSame(['vorname'], $result->formPlan['fill']['typed'] ?? null);
    }
}
