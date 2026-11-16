<?php

namespace Database\Seeders;

use App\Enums\DocsFailureCode;
use App\Enums\Role;
use App\Enums\SiteIdentityState;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
use App\Models\CandidateListingMatch;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Opt-in CEO/PO Apply demo data (personas A–E + funnel fillers).
 *
 * Staging/demo only — not called from DatabaseSeeder.
 *
 *   php artisan db:seed --class=ApplyDemoSeeder
 */
class ApplyDemoSeeder extends Seeder
{
    private const SITE = 'azubiyo';

    public function run(): void
    {
        $listing = Listing::query()->firstOrCreate(
            [
                'source' => self::SITE,
                'external_id' => 'demo-apply-listing',
            ],
            [
                'fingerprint' => hash('sha256', self::SITE.'|demo-apply-listing'),
                'kind' => \App\Enums\ListingKind::Ausbildung,
                'category' => 'IT',
                'title' => 'Demo IT Ausbildung',
                'company' => 'Joinera Demo GmbH',
                'city' => 'Berlin',
                'url' => 'https://jobs.joinera.test/azubiyo/demo-apply-listing',
                'country' => 'DE',
                'first_seen_at' => now()->subDays(3),
                'last_seen_at' => now()->subDay(),
            ],
        );

        $this->resetDemoApplications();

        $fillerQueued = $this->candidate('Demo Queued', 'demo.apply.queued@joinera.test');
        $this->applicationFor($fillerQueued, $listing)->queued()->create(['site' => self::SITE]);

        $fillerPreparing = $this->candidate('Demo Preparing', 'demo.apply.preparing@joinera.test');
        $this->applicationFor($fillerPreparing, $listing)->preparingDocs()->create(['site' => self::SITE]);

        // A: submitted + success attempt
        $a = $this->candidate('Demo Submitted', 'demo.apply.a@joinera.test');
        $appA = $this->applicationFor($a, $listing)->submitted()->create([
            'site' => self::SITE,
            'docs_failure_code' => null,
        ]);
        DocArtifact::factory()->cv()->create(['application_id' => $appA->id]);
        DocArtifact::factory()->cover()->create(['application_id' => $appA->id]);
        ApplyAttempt::factory()->success('demo-confirmation-a')->create([
            'application_id' => $appA->id,
        ]);
        $this->ensureIdentity($a, paused: false);

        // B: applying + docs failure, no CV
        $b = $this->candidate('Demo Docs Gated', 'demo.apply.b@joinera.test');
        $appB = $this->applicationFor($b, $listing)->applying()->create([
            'site' => self::SITE,
            'docs_failure_code' => DocsFailureCode::MissingBaseCv->value,
        ]);
        DocArtifact::factory()->cover()->create(['application_id' => $appB->id]);
        $this->ensureIdentity($b, paused: false);

        // C: applying + paused identity + challenge attempt + open block
        $c = $this->candidate('Demo Challenge', 'demo.apply.c@joinera.test');
        $appC = $this->applicationFor($c, $listing)->applying()->create([
            'site' => self::SITE,
            'docs_failure_code' => null,
        ]);
        DocArtifact::factory()->cv()->create(['application_id' => $appC->id]);
        DocArtifact::factory()->cover()->create(['application_id' => $appC->id]);
        ApplyAttempt::factory()->challenge(BlockEvent::KIND_CAPTCHA)->create([
            'application_id' => $appC->id,
        ]);
        $this->ensureIdentity($c, paused: true);
        BlockEvent::factory()->create([
            'user_id' => $c->id,
            'site' => self::SITE,
            'application_id' => $appC->id,
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
        ]);

        // D: applying + retryable attempt
        $d = $this->candidate('Demo Retryable', 'demo.apply.d@joinera.test');
        $appD = $this->applicationFor($d, $listing)->applying()->create([
            'site' => self::SITE,
            'docs_failure_code' => null,
        ]);
        DocArtifact::factory()->cv()->create(['application_id' => $appD->id]);
        DocArtifact::factory()->cover()->create(['application_id' => $appD->id]);
        ApplyAttempt::factory()->retryable('timeout')->create([
            'application_id' => $appD->id,
        ]);
        $this->ensureIdentity($d, paused: false);

        // E: applying + hard_fail attempt
        $e = $this->candidate('Demo Hard Fail', 'demo.apply.e@joinera.test');
        $appE = $this->applicationFor($e, $listing)->applying()->create([
            'site' => self::SITE,
            'docs_failure_code' => null,
        ]);
        DocArtifact::factory()->cv()->create(['application_id' => $appE->id]);
        DocArtifact::factory()->cover()->create(['application_id' => $appE->id]);
        ApplyAttempt::factory()->hardFail('identity_mismatch')->create([
            'application_id' => $appE->id,
        ]);
        $this->ensureIdentity($e, paused: false);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Application>
     */
    private function applicationFor(User $user, Listing $listing)
    {
        $match = CandidateListingMatch::factory()
            ->for($user)
            ->for($listing)
            ->queued()
            ->create();

        return Application::factory()
            ->for($user)
            ->for($listing)
            ->forMatch($match);
    }

    private function resetDemoApplications(): void
    {
        $emails = [
            'demo.apply.queued@joinera.test',
            'demo.apply.preparing@joinera.test',
            'demo.apply.a@joinera.test',
            'demo.apply.b@joinera.test',
            'demo.apply.c@joinera.test',
            'demo.apply.d@joinera.test',
            'demo.apply.e@joinera.test',
        ];

        $userIds = User::query()->whereIn('email', $emails)->pluck('id');
        if ($userIds->isEmpty()) {
            return;
        }

        $applicationIds = Application::query()->whereIn('user_id', $userIds)->pluck('id');
        if ($applicationIds->isNotEmpty()) {
            ApplyAttempt::query()->whereIn('application_id', $applicationIds)->delete();
            DocArtifact::query()->whereIn('application_id', $applicationIds)->delete();
            BlockEvent::query()->whereIn('application_id', $applicationIds)->delete();
            Application::query()->whereIn('id', $applicationIds)->delete();
        }

        CandidateListingMatch::query()->whereIn('user_id', $userIds)->delete();
        BlockEvent::query()->whereIn('user_id', $userIds)->where('site', self::SITE)->delete();
        SiteIdentity::query()->whereIn('user_id', $userIds)->where('site', self::SITE)->delete();
    }

    private function ensureIdentity(User $user, bool $paused): void
    {
        SiteIdentity::factory()->for($user)->healthy()->create([
            'site' => self::SITE,
            'paused' => $paused,
            'state' => SiteIdentityState::Healthy,
        ]);
    }

    private function candidate(string $name, string $email): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'role' => Role::User,
            ],
        );
    }
}
