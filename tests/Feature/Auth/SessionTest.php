<?php

namespace Tests\Feature\Auth;

use App\Enums\ComplianceStatus;
use App\Enums\SubscriptionState;
use App\Models\Driver;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /auth/me and POST /auth/logout — phase-5 test T9. */
class SessionTest extends TestCase
{
    use RefreshDatabase;

    /** Log in through the real endpoint and return the plain-text token. */
    private function loginToken(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['login' => $email, 'password' => 'password'])->json('token');
    }

    /**
     * Laravel's test client remembers the authenticated user between requests in one test.
     * Forgetting the guards makes each request prove itself with its own token, like a real phone.
     */
    private function freshRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    public function test_t9_me_with_token_without_token_and_after_logout(): void
    {
        User::factory()->passenger()->create(['email' => 'maria@example.test']);
        $token = $this->loginToken('maria@example.test');

        $this->freshRequest()->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('user.email', 'maria@example.test')
            ->assertJsonPath('driver', null);

        $this->freshRequest()->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->freshRequest()->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertNoContent();

        $this->freshRequest()->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertUnauthorized();
    }

    public function test_logout_only_ends_the_current_device(): void
    {
        User::factory()->passenger()->create(['email' => 'maria@example.test']);
        $phone = $this->loginToken('maria@example.test');
        $tablet = $this->loginToken('maria@example.test');

        $this->freshRequest()->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$phone}"])->assertNoContent();

        $this->freshRequest()->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$tablet}"])->assertOk();
    }

    public function test_expired_token_is_rejected(): void
    {
        User::factory()->passenger()->create(['email' => 'maria@example.test']);
        $token = $this->loginToken('maria@example.test');

        $this->travel(31)->days();

        $this->freshRequest()->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_me_for_a_driver_includes_compliance_and_computed_subscription(): void
    {
        $user = User::factory()->driver()->create(['email' => 'juan@example.test']);
        Driver::forceCreate(['user_id' => $user->id, 'compliance_status' => ComplianceStatus::Verified]);
        $plan = SubscriptionPlan::create([
            'code' => 'drv_1m', 'name' => 'Driver · 1 buwan', 'user_type' => 'driver', 'duration_months' => 1, 'price' => 199,
        ]);
        Subscription::forceCreate([
            'user_id' => $user->id, 'subscription_plan_id' => $plan->id, 'state' => SubscriptionState::Paid,
            'amount' => 199, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29),
        ]);
        $token = $this->loginToken('juan@example.test');

        $this->freshRequest()->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('driver.compliance_status', 'verified')
            ->assertJsonPath('subscription.status', 'active');

        // Same stored row, 30 days later: the status is COMPUTED, so it becomes "expired" by itself.
        $this->travel(30)->days();
        $token = $this->loginToken('juan@example.test');
        $this->freshRequest()->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertJsonPath('subscription.status', 'expired');
    }
}
