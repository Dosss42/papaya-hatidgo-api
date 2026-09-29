<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every /api response, success or error, has the shape the mobile app expects.
 */
class ErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_ok_with_database(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'db' => 'connected', 'version' => 'v1']);
    }

    public function test_unknown_api_route_returns_standard_404_without_a_stack_trace(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Hindi nahanap ang hinahanap mo.',
                'code' => 'NOT_FOUND',
            ]);
    }

    public function test_wrong_http_method_returns_standard_405(): void
    {
        $this->postJson('/api/v1/health')
            ->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    public function test_the_test_suite_runs_on_mysql_not_sqlite(): void
    {
        // Our schema relies on MySQL CHECK constraints and generated columns.
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('papaya_hatidgo_test', config('database.connections.mysql.database'));
    }
}
