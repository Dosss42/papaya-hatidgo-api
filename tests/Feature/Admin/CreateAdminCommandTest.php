<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** php artisan admin:create: the first admin on a server, safe to run on every deploy. */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private function env(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        $this->env(['ADMIN_EMAIL' => '', 'ADMIN_PASSWORD' => '', 'ADMIN_PHONE' => '']);
        parent::tearDown();
    }

    public function test_it_creates_the_admin_once_and_never_prints_the_password(): void
    {
        $this->env(['ADMIN_EMAIL' => 'boss@papaya.test', 'ADMIN_PASSWORD' => 'Secret-123', 'ADMIN_PHONE' => '+639170009999']);

        $this->artisan('admin:create')->expectsOutput('Admin boss@papaya.test created.')->doesntExpectOutputToContain('Secret-123')->assertSuccessful();
        $admin = User::where('email', 'boss@papaya.test')->firstOrFail();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('Secret-123', $admin->password));

        // The next deploy runs it again: nothing changes.
        $this->artisan('admin:create')->assertSuccessful();
        $this->assertSame(1, User::where('email', 'boss@papaya.test')->count());
    }

    public function test_without_the_variables_it_skips_and_the_deploy_still_starts(): void
    {
        $this->env(['ADMIN_EMAIL' => '', 'ADMIN_PASSWORD' => '', 'ADMIN_PHONE' => '']);

        $this->artisan('admin:create')->assertSuccessful();
        $this->assertSame(0, User::count());
    }
}
