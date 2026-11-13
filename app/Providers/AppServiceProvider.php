<?php

namespace App\Providers;

use App\Contracts\ArbeitsagenturDeHttpClient;
use App\Contracts\AusbildungDeHttpClient;
use App\Contracts\AzubiyoHttpClient;
use App\Contracts\CvParser;
use App\Contracts\DomainRegistrar;
use App\Contracts\LlmClient;
use App\Contracts\MailboxInboundSync;
use App\Contracts\MailboxOutboundMailer;
use App\Contracts\MailboxProvider;
use App\Contracts\PdfRenderer;
use App\Events\CandidateMatchRecomputeRequested;
use App\Events\ListingCreated;
use App\Events\ProfilePrioritiesChanged;
use App\Listeners\DispatchMatchListingOnListingCreated;
use App\Listeners\DispatchRematchOnCandidateMatchRecomputeRequested;
use App\Listeners\DispatchRematchOnProfilePrioritiesChanged;
use App\Services\Ai\FakeLlmClient;
use App\Services\Ai\OpenRouterLlmClient;
use App\Services\ArbeitsagenturDe\ArbeitsagenturDeIntakeAdapter;
use App\Services\ArbeitsagenturDe\FakeArbeitsagenturDeHttpClient;
use App\Services\ArbeitsagenturDe\LaravelArbeitsagenturDeHttpClient;
use App\Services\AusbildungDe\AusbildungDeIntakeAdapter;
use App\Services\AusbildungDe\FakeAusbildungDeHttpClient;
use App\Services\AusbildungDe\LaravelAusbildungDeHttpClient;
use App\Services\Azubiyo\AzubiyoIntakeAdapter;
use App\Services\Azubiyo\FakeAzubiyoHttpClient;
use App\Services\Azubiyo\LaravelAzubiyoHttpClient;
use App\Services\Docs\DompdfPdfRenderer;
use App\Services\Docs\FakePdfRenderer;
use App\Services\FakeMailboxInboundSync;
use App\Services\FakeMailboxOutboundMailer;
use App\Services\FixtureCvParser;
use App\Services\Gmx\GmxImapSync;
use App\Services\Gmx\GmxSmtpMailer;
use App\Services\LocalDomainRegistrar;
use App\Services\LocalMailboxProvider;
use App\Services\SiteIntakeAdapterRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CvParser::class, FixtureCvParser::class);

        $this->app->bind(LlmClient::class, function ($app) {
            if ($this->useFakeLlmClient()) {
                return $app->make(FakeLlmClient::class);
            }

            return $app->make(OpenRouterLlmClient::class);
        });

        $this->app->bind(PdfRenderer::class, function ($app) {
            if ($this->useFakePdfRenderer()) {
                return $app->make(FakePdfRenderer::class);
            }

            return $app->make(DompdfPdfRenderer::class);
        });

        // Legacy domain provision ports — no longer used by apply-mail path.
        $this->app->singleton(MailboxProvider::class, LocalMailboxProvider::class);
        $this->app->singleton(DomainRegistrar::class, LocalDomainRegistrar::class);

        $this->app->singleton(FakeMailboxInboundSync::class);
        $this->app->singleton(FakeMailboxOutboundMailer::class);

        $this->app->bind(MailboxInboundSync::class, function ($app) {
            if ($this->useFakeMailboxPorts()) {
                return $app->make(FakeMailboxInboundSync::class);
            }

            return $app->make(GmxImapSync::class);
        });

        $this->app->bind(MailboxOutboundMailer::class, function ($app) {
            if ($this->useFakeMailboxPorts()) {
                return $app->make(FakeMailboxOutboundMailer::class);
            }

            return $app->make(GmxSmtpMailer::class);
        });

        $this->app->bind(AusbildungDeHttpClient::class, function ($app) {
            if ($this->useFakeAusbildungDeHttp()) {
                return new FakeAusbildungDeHttpClient;
            }

            return $app->make(LaravelAusbildungDeHttpClient::class);
        });

        $this->app->bind(AzubiyoHttpClient::class, function ($app) {
            if ($this->useFakeAzubiyoHttp()) {
                return new FakeAzubiyoHttpClient;
            }

            return $app->make(LaravelAzubiyoHttpClient::class);
        });

        $this->app->bind(ArbeitsagenturDeHttpClient::class, function ($app) {
            if ($this->useFakeArbeitsagenturDeHttp()) {
                return new FakeArbeitsagenturDeHttpClient;
            }

            return $app->make(LaravelArbeitsagenturDeHttpClient::class);
        });

        $this->app->singleton(SiteIntakeAdapterRegistry::class, function ($app) {
            $registry = new SiteIntakeAdapterRegistry;
            $registry->register(
                'ausbildung.de',
                $app->make(AusbildungDeIntakeAdapter::class),
            );
            $registry->register(
                'azubiyo',
                $app->make(AzubiyoIntakeAdapter::class),
            );
            $registry->register(
                'arbeitsagentur.de',
                $app->make(ArbeitsagenturDeIntakeAdapter::class),
            );

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ListingCreated::class, DispatchMatchListingOnListingCreated::class);
        Event::listen(ProfilePrioritiesChanged::class, DispatchRematchOnProfilePrioritiesChanged::class);
        Event::listen(CandidateMatchRecomputeRequested::class, DispatchRematchOnCandidateMatchRecomputeRequested::class);

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }

    private function useFakeMailboxPorts(): bool
    {
        $driver = config('joinera.mailbox.driver');

        return $driver === 'fake' || $this->app->environment('testing');
    }

    private function useFakeAusbildungDeHttp(): bool
    {
        $driver = config('joinera.sites.adapters.ausbildung_de.http');

        return $driver === 'fake' || $this->app->environment('testing');
    }

    private function useFakeAzubiyoHttp(): bool
    {
        $driver = config('joinera.sites.adapters.azubiyo.http');

        return $driver === 'fake' || $this->app->environment('testing');
    }

    private function useFakeArbeitsagenturDeHttp(): bool
    {
        $driver = config('joinera.sites.adapters.arbeitsagentur_de.http');

        return $driver === 'fake' || $this->app->environment('testing');
    }

    private function useFakeLlmClient(): bool
    {
        $driver = config('joinera.ai.driver');

        return $driver === 'fake' || $this->app->environment('testing');
    }

    private function useFakePdfRenderer(): bool
    {
        return $this->app->environment('testing');
    }
}
