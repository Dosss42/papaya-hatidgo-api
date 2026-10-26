<?php

namespace Tests\Feature\Notifications;

use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Phase 13: the Alerts list endpoints and push registration (decisions #3, #5, #9). */
class AlertsApiTest extends TestCase
{
    use RefreshDatabase;

    private function alert(User $user, string $kind = 'ride_cancelled_by_driver', array $params = ['reason' => 'passenger_no_show']): void
    {
        $user->notify(new AppNotification($kind, $params, 'ride:7')); // no Firebase key in tests: stored only
    }

    public function test_the_list_is_written_in_the_language_of_the_request(): void
    {
        $user = User::factory()->passenger()->create();
        $this->alert($user);
        Sanctum::actingAs($user);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.kind', 'ride_cancelled_by_driver')
            ->assertJsonPath('data.0.title', 'Your ride was cancelled')
            ->assertJsonPath('data.0.body', "The driver cancelled the ride: the passenger didn't show up. You were not charged.")
            ->assertJsonPath('data.0.target', 'ride:7')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('meta.unread', 1);

        // The same stored notification, read later with the app in Taglish.
        $this->withHeader('Accept-Language', 'fil')->getJson('/api/v1/notifications')
            ->assertJsonPath('data.0.title', 'Na-cancel ang biyahe mo')
            ->assertJsonPath('data.0.body', 'Kinansela ng driver ang biyahe: hindi dumating ang pasahero. Hindi ka sinisingil.');
    }

    public function test_free_text_reasons_stay_as_typed_and_papers_get_their_names(): void
    {
        $user = User::factory()->driver()->create();
        $this->alert($user, 'document_rejected', ['requirement' => 'or_cr', 'requirement_name' => 'OR/CR', 'reason' => 'Malabo ang plate']);
        $this->travel(1)->seconds(); // newest first: one second apart, so the order is certain
        $this->alert($user, 'document_approved', ['requirement' => 'barangay_id', 'requirement_name' => 'Barangay ID']); // added by the admin later
        Sanctum::actingAs($user);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/notifications')
            ->assertJsonPath('data.1.body', "Your Tricycle's OR/CR was rejected: Malabo ang plate. Please upload it again.")
            ->assertJsonPath('data.0.body', 'Your Barangay ID is approved.');
    }

    public function test_reading_one_or_all_counts_the_badge_down_and_others_ids_are_404(): void
    {
        $user = User::factory()->passenger()->create();
        $this->alert($user);
        $this->alert($user);
        $other = User::factory()->passenger()->create();
        $this->alert($other);
        $othersId = $other->notifications()->value('id');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications/unread-count')->assertExactJson(['data' => ['unread' => 2]]);
        $id = $this->getJson('/api/v1/notifications')->json('data.0.id');
        $this->patchJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.id', $id);
        $this->assertNotNull($this->getJson('/api/v1/notifications')->json('data.0.read_at'));
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 1);

        $this->patchJson("/api/v1/notifications/{$othersId}/read")->assertNotFound();
        $this->assertNull($other->notifications()->first()->read_at);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 0);
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_a_phone_is_registered_with_its_login_and_forgotten_at_logout(): void
    {
        $user = User::factory()->passenger()->create();
        $login = $user->createToken('papaya-mobile')->plainTextToken;

        $this->withToken($login)->postJson('/api/v1/device-tokens', ['token' => 'fcm-token-phone-1', 'locale' => 'en'])->assertCreated();
        $device = DeviceToken::where('token', 'fcm-token-phone-1')->firstOrFail();
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame('en', $device->locale);
        $this->assertNotNull($device->personal_access_token_id);

        // Again (every app start, or the language changed): updated, not duplicated.
        $this->withToken($login)->postJson('/api/v1/device-tokens', ['token' => 'fcm-token-phone-1', 'locale' => 'fil'])->assertOk();
        $this->assertSame(1, DeviceToken::count());
        $this->assertSame('fil', $device->refresh()->locale);

        // Logging out deletes the login, and the phone's push token goes with it (decision #5).
        $this->withToken($login)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_the_same_phone_moves_to_whoever_logs_in_next(): void
    {
        $first = User::factory()->passenger()->create();
        $second = User::factory()->driver()->create();
        $this->withToken($first->createToken('a')->plainTextToken)->postJson('/api/v1/device-tokens', ['token' => 'shared-phone'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($second->createToken('b')->plainTextToken)->postJson('/api/v1/device-tokens', ['token' => 'shared-phone'])->assertOk();

        $this->assertSame([$second->id], DeviceToken::pluck('user_id')->all());
    }

    public function test_registration_needs_a_login_and_a_valid_body(): void
    {
        $this->postJson('/api/v1/device-tokens', ['token' => 'x'])->assertUnauthorized();
        $this->getJson('/api/v1/notifications')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->passenger()->create());
        $this->postJson('/api/v1/device-tokens', ['token' => '', 'locale' => 'jp'])
            ->assertStatus(422)->assertJsonValidationErrors(['token', 'locale']);
    }
}
