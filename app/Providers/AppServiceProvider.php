<?php

namespace App\Providers;

use App\Contracts\CvParser;
use App\Contracts\DomainRegistrar;
use App\Contracts\MailboxProvider;
use App\Services\FixtureCvParser;
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
        $this->app->singleton(MailboxProvider::class, LocalMailboxProvider::class);
        $this->app->singleton(DomainRegistrar::class, LocalDomainRegistrar::class);
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
}
