<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/** The API answers in the app's language: Accept-Language en | fil (default fil = Taglish). */
class LanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_header_the_api_answers_in_taglish(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'nobody@example.test', 'password' => 'wrong-pass1'])
            ->assertJsonPath('message', 'Mali ang email/mobile number o password.');
    }

    public function test_accept_language_en_gives_english_errors(): void
    {
        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/auth/login', ['login' => 'nobody@example.test', 'password' => 'wrong-pass1'])
            ->assertJsonPath('message', 'Wrong email/mobile number or password.');
    }

    public function test_english_validation_uses_our_field_names_and_custom_lines(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->postJson('/api/v1/auth/register', [
                'first_name' => '',
                'last_name' => 'Cruz',
                'email' => 'taken@example.test',
                'phone' => '09171234567',
                'password' => 'abc',
                'password_confirmation' => 'abc',
                'role' => 'passenger',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Some of what you entered needs fixing. Please check the fields.')
            ->assertJsonPath('errors.first_name.0', 'The first name field is required.')
            ->assertJsonPath('errors.email.0', 'An account already uses this email.')
            ->assertJsonPath('errors.password.0', 'The password field must be at least 8 characters.');
    }

    public function test_an_unsupported_language_falls_back_to_taglish(): void
    {
        $this->withHeader('Accept-Language', 'ja')
            ->postJson('/api/v1/auth/login', ['login' => '', 'password' => ''])
            ->assertJsonPath('errors.login.0', 'Ilagay ang email o mobile number mo.');
    }

    public function test_every_message_exists_in_both_languages(): void
    {
        // Our own files only (not Laravel's merged ones, which add framework samples).
        $en = require lang_path('en/api.php');
        $fil = require lang_path('fil/api.php');
        $this->assertEqualsCanonicalizing(array_keys($fil), array_keys($en), 'api.php keys differ');

        $en = require lang_path('en/requirements.php');
        $fil = require lang_path('fil/requirements.php');
        $this->assertEqualsCanonicalizing(array_keys($fil), array_keys($en), 'requirements.php codes differ');
        foreach ($fil as $code => $lines) {
            $this->assertEqualsCanonicalizing(array_keys($lines), array_keys($en[$code]), "requirements.$code differs");
        }

        $en = require lang_path('en/plans.php'); // Phase 8
        $fil = require lang_path('fil/plans.php');
        $this->assertEqualsCanonicalizing(array_keys($fil), array_keys($en), 'plans.php codes differ');

        $en = require lang_path('en/validation.php');
        $fil = require lang_path('fil/validation.php');
        $this->assertEqualsCanonicalizing(array_keys($fil['attributes']), array_keys($en['attributes']), 'attributes differ');
        $this->assertEqualsCanonicalizing(array_keys($fil['custom']), array_keys($en['custom']), 'custom fields differ');
        foreach ($fil['custom'] as $field => $rules) {
            $this->assertEqualsCanonicalizing(array_keys($rules), array_keys($en['custom'][$field]), "custom.$field rules differ");
        }
    }
}
