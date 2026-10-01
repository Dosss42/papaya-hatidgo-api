<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionState;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The admin's demo fallback (Phase 8 step 8.4, guarantee #6): manual activation, audited. */
class AdminSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['payments.gateway' => 'fake']);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_admin_activates_a_pending_subscription_with_a_reason(): void
    {
        $driver = $this->driverUser();
        [$id] = $this->checkout($driver, 'drv_1m');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/subscriptions/{$id}/activate", ['reason' => 'GCash paid in person; PayMongo down during the demo'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.payment.method', 'manual');

        $log = AuditLog::where('action', 'subscription.activated')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('manual', $log->new_values['via']);
        $this->assertSame('GCash paid in person; PayMongo down during the demo', $log->new_values['reason']);
    }

    public function test_a_reason_is_required_and_a_paid_subscription_cannot_be_activated_again(): void
    {
        [$id] = $this->checkout($this->driverUser(), 'drv_1m');
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/subscriptions/{$id}/activate", ['reason' => ''])->assertStatus(422);
        $this->postJson("/api/v1/admin/subscriptions/{$id}/activate", ['reason' => 'Paid in cash'])->assertOk();
        $this->postJson("/api/v1/admin/subscriptions/{$id}/activate", ['reason' => 'Again'])
            ->assertStatus(409)->assertJsonPath('code', 'SUBSCRIPTION_NOT_ACTIVATABLE');
        $this->assertSame(1, SubscriptionTransaction::where('subscription_id', $id)->where('status', 'paid')->count());
    }

    public function test_the_admin_can_find_subscriptions_by_email_and_state(): void
    {
        $driver = $this->driverUser();
        [$id] = $this->checkout($driver, 'drv_1m');
        $this->checkout($this->passengerUser(), 'pax_1m');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/subscriptions?state=pending&email='.urlencode($driver->email))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/admin/subscriptions?state=pending')->assertJsonCount(2, 'data');
    }

    public function test_only_admins_may_activate_by_hand(): void
    {
        $driver = $this->driverUser();
        [$id] = $this->checkout($driver, 'drv_1m');

        Sanctum::actingAs($driver);
        $this->postJson("/api/v1/admin/subscriptions/{$id}/activate", ['reason' => 'I paid, trust me'])->assertForbidden();
        $this->assertSame(SubscriptionState::Pending, Subscription::findOrFail($id)->state);
    }
}
