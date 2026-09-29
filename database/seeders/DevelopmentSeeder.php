<?php

namespace Database\Seeders;

use App\Enums\ComplianceStatus;
use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Enums\SubscriptionState;
use App\Enums\TransactionStatus;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\DriverRequirementReview;
use App\Models\Passenger;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY sample accounts (password for all: "password").
 * DatabaseSeeder never runs this in production.
 *
 * | Account                    | Purpose                                                   |
 * |----------------------------|-----------------------------------------------------------|
 * | admin@example.test         | Admin (reviews documents)                                 |
 * | pasahero1@example.test     | Passenger WITH an active subscription → can book          |
 * | pasahero2@example.test     | Passenger WITHOUT a subscription → tests the booking gate |
 * | driver1@example.test       | Verified driver, tricycle, approved documents, subscribed |
 * | driver2@example.test       | New driver, nothing submitted → tests the checklist       |
 *
 * Note: the approved sample documents have no uploaded files (there are no real ID images).
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'first_name' => 'Ana', 'last_name' => 'Reyes',
            'email' => 'admin@example.test', 'phone' => '+639170000100',
        ]);

        // Passengers
        $pax1 = $this->passenger('Maria', 'Cruz', 'pasahero1@example.test', '+639170000001');
        $this->passenger('Jose', 'Santos', 'pasahero2@example.test', '+639170000002');
        $this->activeSubscription($pax1->user, 'pax_1m');

        // Driver 1: fully eligible
        $driver1 = $this->driver('Juan', 'Dela Cruz', 'driver1@example.test', '+639170000011');
        $tricycle = $driver1->vehicles()->create([
            'plate_number' => 'ABC 1234', 'body_number' => '127', 'color' => 'Pula', 'make' => 'Honda', 'model' => 'TMX 155',
        ]);
        $tricycle->forceFill(['status' => VehicleStatus::Verified])->save();
        $driver1->forceFill([
            'active_vehicle_id' => $tricycle->id,
            'compliance_status' => ComplianceStatus::Verified,
        ])->save();

        foreach (DriverRequirement::orderBy('sort_order')->get() as $requirement) {
            $isVehicleDoc = $requirement->applies_to->value === 'vehicle';
            $document = DriverDocument::forceCreate([
                'driver_id' => $driver1->id,
                'driver_requirement_id' => $requirement->id,
                'vehicle_id' => $isVehicleDoc ? $tricycle->id : null,
                'document_number' => strtoupper($requirement->code).'-SAMPLE-001',
                'issued_at' => now()->subMonths(6)->toDateString(),
                'expires_at' => now()->addMonths(6)->toDateString(),
                'status' => DocumentStatus::Approved,
                'is_current' => true,
                'submitted_at' => now()->subDays(3),
            ]);
            DriverRequirementReview::forceCreate([
                'driver_document_id' => $document->id,
                'reviewer_id' => $admin->id,
                'action' => ReviewAction::Approved,
            ]);
        }
        $this->activeSubscription($driver1->user, 'drv_1m');

        // Driver 2: brand new (pending_verification by default, nothing submitted)
        $this->driver('Pedro', 'Bautista', 'driver2@example.test', '+639170000012');
    }

    private function passenger(string $first, string $last, string $email, string $phone): Passenger
    {
        $user = User::factory()->passenger()->create([
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
        ]);

        return Passenger::forceCreate(['user_id' => $user->id]);
    }

    private function driver(string $first, string $last, string $email, string $phone): Driver
    {
        $user = User::factory()->driver()->create([
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
        ]);

        return Driver::forceCreate(['user_id' => $user->id]);
    }

    /** A paid, currently active subscription with its matching paid (sample) transaction. */
    private function activeSubscription(User $user, string $planCode): void
    {
        $plan = SubscriptionPlan::where('code', $planCode)->firstOrFail();

        $subscription = Subscription::forceCreate([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'state' => SubscriptionState::Paid,
            'amount' => $plan->price,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subDays(5)->addMonths($plan->duration_months),
        ]);

        SubscriptionTransaction::forceCreate([
            'subscription_id' => $subscription->id,
            'amount' => $plan->price,
            'status' => TransactionStatus::Paid,
            'provider' => 'paymongo',
            'provider_checkout_id' => 'seed_cs_'.$subscription->id,
            'provider_payment_id' => 'seed_pay_'.$subscription->id,
            'payment_method' => 'gcash',
            'paid_at' => now()->subDays(5),
        ]);
    }
}
