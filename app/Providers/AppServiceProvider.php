<?php

namespace App\Providers;

use App\Contracts\CvParser;
use App\Contracts\DomainRegistrar;
use App\Contracts\MailboxInboundSync;
use App\Contracts\MailboxOutboundMailer;
use App\Contracts\MailboxProvider;
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

        $this->app->singleton(SiteIntakeAdapterRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
}
