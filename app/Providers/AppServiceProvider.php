<?php

namespace App\Providers;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\FareSetting;
use App\Models\Passenger;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Polymorphic columns (notifications.notifiable_type, audit_logs.auditable_type) store these
        // short names instead of PHP class names, so renaming or moving a class never breaks
        // stored rows. "Enforce" = an unmapped model throws instead of silently storing a class name.
        Relation::enforceMorphMap([
            'user' => User::class,
            'passenger' => Passenger::class,
            'driver' => Driver::class,
            'vehicle' => Vehicle::class,
            'driver_requirement' => DriverRequirement::class,
            'driver_document' => DriverDocument::class,
            'fare_setting' => FareSetting::class,
            'system_setting' => SystemSetting::class,
            'ride_request' => RideRequest::class,
            'subscription_plan' => SubscriptionPlan::class,
            'subscription' => Subscription::class,
        ]);

        // Outside production, make Eloquent mistakes loud instead of silent:
        // - filling a non-fillable attribute throws (instead of being silently ignored),
        // - lazy loading in a loop (the "N+1 queries" problem) throws,
        // - reading an attribute that wasn't selected throws.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimiting();
    }

    /**
     * Named limits against guessing attacks (phase-5-authentication.md § 3).
     * Each is keyed on WHAT is being attacked + the caller's IP, so one attacker
     * cannot lock everyone else out of the whole API.
     */
    private function configureRateLimiting(): void
    {
        // 5 login attempts per minute for one account (email/phone) from one IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('login')).'|'.$request->ip()));

        // 5 registrations per minute per IP (stops scripted account creation).
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip()));

        // 3 reset-code emails per 10 minutes for one email from one IP.
        RateLimiter::for('forgot-password', fn (Request $request) => Limit::perMinutes(10, 3)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        // 5 code guesses per 15 minutes per email (a 6-digit code can't be brute-forced).
        RateLimiter::for('reset-password', fn (Request $request) => Limit::perMinutes(15, 5)
            ->by(Str::lower((string) $request->input('email'))));
    }
}
