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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

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
    }
}
