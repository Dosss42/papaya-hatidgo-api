<?php

namespace Tests\Feature\Drivers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** role:… middleware (App\Http\Middleware\EnsureRole). */
class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only routes, so the guard is tested on its own before real driver/admin routes exist.
        Route::middleware(['api', 'auth:sanctum', 'role:driver'])->get('/api/test/driver-only', fn () => ['ok' => true]);
        Route::middleware(['api', 'auth:sanctum', 'role:driver,admin'])->get('/api/test/driver-or-admin', fn () => ['ok' => true]);
    }

    public function test_the_right_role_gets_in(): void
    {
        Sanctum::actingAs(User::factory()->driver()->create());

        $this->getJson('/api/test/driver-only')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_another_role_gets_403_in_the_standard_error_format(): void
    {
        Sanctum::actingAs(User::factory()->passenger()->create());

        $this->getJson('/api/test/driver-only')
            ->assertForbidden()
            ->assertJson(['code' => 'FORBIDDEN_ROLE', 'message' => 'Hindi para sa account mo ang bahaging ito.']);
    }

    public function test_several_roles_can_be_allowed(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/test/driver-or-admin')->assertOk();
    }

    public function test_without_a_token_it_is_401_not_403(): void
    {
        $this->getJson('/api/test/driver-only')->assertUnauthorized();
    }
}
