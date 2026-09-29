<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** POST /api/v1/auth/register — phase-5 tests T1–T4. */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'email' => 'maria@example.test',
            'phone' => '09171234567',
            'password' => 'Papaya123',
            'password_confirmation' => 'Papaya123',
            'role' => 'passenger',
            'device_name' => 'test-phone',
        ], $overrides);
    }

    public function test_t1_passenger_registers_and_gets_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'first_name', 'last_name', 'full_name', 'email', 'phone', 'role', 'account_status']])
            ->assertJsonPath('user.role', 'passenger')
            ->assertJsonPath('user.phone', '+639171234567') // stored normalized
            ->assertJsonMissingPath('user.password');

        $user = User::firstWhere('email', 'maria@example.test');
        $this->assertNotNull($user->passenger, 'passengers row must be created with the user');
        $this->assertNull($user->driver);
        $this->assertNotSame('Papaya123', $user->password, 'password must be hashed');
    }

    public function test_t2_driver_registers_and_starts_pending_verification(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'juan@example.test', 'phone' => '09181234567', 'role' => 'driver',
        ]))->assertCreated()->assertJsonPath('user.role', 'driver');

        $driver = User::firstWhere('email', 'juan@example.test')->driver;
        $this->assertNotNull($driver);
        $this->assertSame('pending_verification', $driver->compliance_status->value);
    }

    public function test_t3_nobody_can_register_as_admin(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['role' => 'admin']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_t4_same_phone_typed_differently_is_a_duplicate(): void
    {
        User::factory()->create(['phone' => '+639171234567']);

        $this->postJson('/api/v1/auth/register', $this->payload(['phone' => '0917 123 4567']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_t4b_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'maria@example.test']);

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'MARIA@Example.test']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_weak_or_unconfirmed_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'short', 'password_confirmation' => 'other']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_failed_registration_leaves_no_half_created_account(): void
    {
        // The whole registration is one transaction: user + passenger row, or nothing.
        $this->postJson('/api/v1/auth/register', $this->payload(['role' => 'pilot']))->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('passengers', 0);
    }
}
