<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** forgot-password / reset-password — phase-5 tests T10–T12. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake(); // capture emails instead of sending them
        $this->user = User::factory()->passenger()->create(['email' => 'jose@example.test']);
    }

    /** Request a code and read it from the captured email (like the user reading their inbox). */
    private function requestCode(): string
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'jose@example.test'])->assertOk();

        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('jose@example.test');
        });

        return $code;
    }

    private function reset(string $code, string $password = 'Bagong123'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'jose@example.test', 'code' => $code,
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    public function test_t10_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        $real = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'jose@example.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

        $this->assertSame($real->json(), $unknown->json());
        Mail::assertSent(PasswordResetCodeMail::class, 1); // only the real account got one
    }

    public function test_code_is_six_digits_and_stored_only_as_a_hash(): void
    {
        $code = $this->requestCode();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $stored = DB::table('password_reset_tokens')->where('email', 'jose@example.test')->value('token');
        $this->assertNotSame($code, $stored);
        $this->assertStringStartsWith('$2y$', $stored); // bcrypt hash
    }

    public function test_t11_right_code_works_and_wrong_code_fails(): void
    {
        $code = $this->requestCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->reset($wrong)->assertUnprocessable()->assertJsonPath('code', 'INVALID_OR_EXPIRED_CODE');
        $this->reset($code)->assertOk();
    }

    public function test_t11_code_expires_after_15_minutes(): void
    {
        $code = $this->requestCode();

        $this->travel(16)->minutes();

        $this->reset($code)->assertUnprocessable()->assertJsonPath('code', 'INVALID_OR_EXPIRED_CODE');
    }

    public function test_a_new_request_replaces_the_old_code(): void
    {
        $first = $this->requestCode();
        Mail::fake(); // reset the captured mailbox
        $second = $this->requestCode();

        if ($first !== $second) { // (1 in a million they're equal)
            $this->reset($first)->assertUnprocessable();
        }
        $this->reset($second)->assertOk();
    }

    public function test_t12_reset_revokes_old_tokens_and_the_code_is_single_use(): void
    {
        $oldToken = $this->user->createToken('phone')->plainTextToken;
        $code = $this->requestCode();

        $this->reset($code)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$oldToken}"])->assertUnauthorized();

        $this->reset($code)->assertUnprocessable(); // cannot be used twice

        $this->postJson('/api/v1/auth/login', ['login' => 'jose@example.test', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['login' => 'jose@example.test', 'password' => 'Bagong123'])->assertOk();
    }
}
