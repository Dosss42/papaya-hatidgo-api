<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionState;
use App\Enums\TransactionStatus;
use App\Models\Driver;
use App\Models\Passenger;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** Shared setup for the Phase 8 tests. */
trait SubscriptionHelpers
{
    protected function passengerUser(): User
    {
        $user = User::factory()->passenger()->create();
        Passenger::forceCreate(['user_id' => $user->id]);

        return $user->refresh(); // with the database defaults (account_status…)
    }

    protected function driverUser(): User
    {
        $user = User::factory()->driver()->create();
        Driver::forceCreate(['user_id' => $user->id]);

        return $user->refresh();
    }

    protected function plan(string $code): SubscriptionPlan
    {
        return SubscriptionPlan::where('code', $code)->firstOrFail();
    }

    /** Through the real endpoint, as the app does. Returns [subscription id, checkout id]. */
    protected function checkout(User $user, string $planCode): array
    {
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/subscriptions', ['plan_id' => $this->plan($planCode)->id])
            ->assertCreated()
            ->json('data.subscription.id');

        return [$id, SubscriptionTransaction::where('subscription_id', $id)->latest('id')->value('provider_checkout_id')];
    }

    /** A pending subscription + transaction made directly (for webhook tests with a known checkout id). */
    protected function pendingSubscription(User $user, string $planCode, string $checkoutId): Subscription
    {
        $plan = $this->plan($planCode);
        $subscription = Subscription::forceCreate([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'state' => SubscriptionState::Pending,
            'amount' => $plan->price,
        ]);
        SubscriptionTransaction::forceCreate([
            'subscription_id' => $subscription->id,
            'amount' => $plan->price,
            'status' => TransactionStatus::Pending,
            'provider' => 'paymongo',
            'provider_checkout_id' => $checkoutId,
        ]);

        return $subscription;
    }
}
