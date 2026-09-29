<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** POST /api/v1/auth/login — phase-5 tests T5–T8. */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->passenger()->create([
            'email' => 'maria@example.test',
            'phone' => '+639171234567',
            // factory password is "password"
        ]);
    }

    public function test_t5_login_with_email(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'maria@example.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'role']])
            ->assertJsonPath('user.email', 'maria@example.test');
    }

    public function test_t5_login_with_phone_in_any_format(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => '0917 123 4567', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $this->user->id);
    }

    public function test_token_expires_in_30_days(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'maria@example.test', 'password' => 'password'])->assertOk();

        $expiresAt = $this->user->tokens()->first()->expires_at;
        $this->assertTrue($expiresAt->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
    }

    public function test_t6_wrong_password_and_unknown_account_look_identical(): void
    {
        $wrongPassword = $this->postJson('/api/v1/auth/login', ['login' => 'maria@example.test', 'password' => 'nope']);
        $unknownAccount = $this->postJson('/api/v1/auth/login', ['login' => 'nobody@example.test', 'password' => 'nope']);

        $wrongPassword->assertUnprocessable()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->assertSame($wrongPassword->json(), $unknownAccount->json(), 'must not reveal which accounts exist');
        $this->assertSame($wrongPassword->status(), $unknownAccount->status());
    }

    public function test_t7_suspended_account_cannot_log_in(): void
    {
        $this->user->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->postJson('/api/v1/auth/login', ['login' => 'maria@example.test', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $this->assertSame(0, $this->user->tokens()->count(), 'no token for a suspended account');
    }

    public function test_t8_sixth_attempt_within_a_minute_is_blocked(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['login' => 'maria@example.test', 'password' => "wrong{$attempt}"])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['login' => 'maria@example.test', 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')
            ->assertJsonStructure(['retry_after']);
    }
}
