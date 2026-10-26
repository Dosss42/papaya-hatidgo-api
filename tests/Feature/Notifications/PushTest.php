<?php

namespace Tests\Feature\Notifications;

use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Push\FcmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 13 decision #1: the FCM HTTP v1 client, against a FAKED Google (Http::fake) with a
 * throwaway key made for the test. Nothing leaves the PC. The fake key's public half checks that
 * the login we send Google is really signed by the private half.
 */
class PushTest extends TestCase
{
    use RefreshDatabase;

    private string $publicKey;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        // PHP on Windows needs an OpenSSL config file to make a key; a minimal one is enough.
        $config = ['config' => tempnam(sys_get_temp_dir(), 'ossl'), 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        file_put_contents($config['config'], "[req]\ndistinguished_name = dn\n[dn]\n");
        $key = openssl_pkey_new($config);
        openssl_pkey_export($key, $privateKey, null, $config);
        @unlink($config['config']);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->keyFile = tempnam(sys_get_temp_dir(), 'fcm-test');
        file_put_contents($this->keyFile, json_encode([
            'type' => 'service_account',
            'project_id' => 'papaya-test',
            'client_email' => 'push@papaya-test.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        $this->app->instance(FcmClient::class, new FcmClient($this->keyFile));

        Http::preventStrayRequests(); // a real request to Google would fail the test
        $this->withoutDefer();        // send "after the response" right away, inside the test
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    private function fakeGoogle(int $fcmStatus = 200, array $fcmBody = ['name' => 'projects/papaya-test/messages/1']): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response($fcmBody, $fcmStatus),
        ]);
    }

    private function phone(User $user, string $token, string $locale): DeviceToken
    {
        return DeviceToken::forceCreate(['user_id' => $user->id, 'token' => $token, 'platform' => 'android', 'locale' => $locale]);
    }

    public function test_each_phone_gets_the_push_in_its_own_language_with_a_signed_login(): void
    {
        $this->fakeGoogle();
        $user = User::factory()->passenger()->create();
        $this->phone($user, 'phone-en', 'en');
        $this->phone($user, 'phone-fil', 'fil');

        $user->notify(new AppNotification('ride_arrived', ['plate' => 'ABC 1234', 'color' => 'Pula'], 'ride:9',
            inList: false, channel: AppNotification::RIDES, tag: 'ride-9'));

        // The login: a JWT whose signature the PUBLIC key confirms, asking only for messaging.
        $login = Http::recorded(fn (Request $r) => str_contains($r->url(), 'oauth2'))->first()[0];
        [$header, $claims, $signature] = explode('.', $login['assertion']);
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', json_decode($decode($claims), true)['scope']);

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $sent);
        $byToken = $sent->keyBy(fn (Request $r) => $r['message']['token']);
        $this->assertSame('https://fcm.googleapis.com/v1/projects/papaya-test/messages:send', $byToken['phone-en']->url());
        $this->assertSame('Bearer test-access', $byToken['phone-en']->header('Authorization')[0]);
        $this->assertSame('Your driver is here', $byToken['phone-en']['message']['notification']['title']);
        $this->assertSame('Look for tricycle ABC 1234 (Pula).', $byToken['phone-en']['message']['notification']['body']);
        $this->assertSame('Nandito na ang driver mo', $byToken['phone-fil']['message']['notification']['title']);
        $this->assertSame(['kind' => 'ride_arrived', 'target' => 'ride:9', 'notification_id' => ''], $byToken['phone-en']['message']['data']);
        $this->assertSame(['priority' => 'HIGH', 'notification' => ['channel_id' => 'rides', 'tag' => 'ride-9', 'sound' => 'default']],
            $byToken['phone-en']['message']['android']);

        $this->assertNotNull(DeviceToken::where('token', 'phone-en')->value('last_used_at'));
        Http::assertSentCount(3); // one login (then cached), two pushes
    }

    public function test_a_stored_notification_carries_its_id_so_a_tap_can_mark_it_read(): void
    {
        $this->fakeGoogle();
        $user = User::factory()->driver()->create();
        $this->phone($user, 'phone-1', 'en');

        $user->notify(new AppNotification('document_approved', ['requirement' => 'clearance'], 'requirement:clearance'));

        $id = $user->notifications()->value('id');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com')
            && $r['message']['data']['notification_id'] === $id
            && $r['message']['android'] === ['priority' => 'NORMAL', 'notification' => ['channel_id' => 'general']]);
    }

    public function test_a_phone_google_no_longer_knows_is_forgotten(): void
    {
        $this->fakeGoogle(404, ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]);
        $user = User::factory()->passenger()->create();
        $this->phone($user, 'uninstalled', 'fil');

        $user->notify(new AppNotification('ride_no_driver', [], 'ride:1'));

        $this->assertSame(0, DeviceToken::count());
        $this->assertSame(1, $user->notifications()->count()); // the Alerts entry is still there
    }

    public function test_google_trouble_never_breaks_anything(): void
    {
        $this->fakeGoogle(503, ['error' => ['status' => 'UNAVAILABLE']]);
        $user = User::factory()->passenger()->create();
        $this->phone($user, 'phone-1', 'en');

        $user->notify(new AppNotification('ride_no_driver', [], 'ride:1')); // no exception

        $this->assertSame(1, DeviceToken::count()); // kept: maybe next time
        $this->assertNull(DeviceToken::value('last_used_at'));
    }

    public function test_when_google_cannot_be_reached_pushes_pause_instead_of_each_one_waiting(): void
    {
        $logins = 0;
        $down = true;
        Http::fake(function (Request $request) use (&$logins, &$down) {
            if (str_contains($request->url(), 'oauth2')) {
                $logins++;
            }
            if ($down) {
                throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host');
            }

            return Http::response(str_contains($request->url(), 'oauth2') ? ['access_token' => 'a'] : ['name' => 'x']);
        });
        $user = User::factory()->passenger()->create();
        $this->phone($user, 'phone-1', 'en');
        $this->phone($user, 'phone-2', 'en');

        $user->notify(new AppNotification('ride_no_driver', [], 'ride:1')); // tries once, then stops the loop
        $user->notify(new AppNotification('ride_no_driver', [], 'ride:2')); // paused: not even tried

        $this->assertSame(1, $logins);
        $this->assertSame(2, $user->notifications()->count()); // the Alerts list is unaffected
        $this->travel(61)->seconds();
        $down = false;
        $user->notify(new AppNotification('ride_no_driver', [], 'ride:3'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com')); // a minute later it tries again
    }

    public function test_without_a_key_nothing_is_sent_and_the_list_still_works(): void
    {
        $this->app->instance(FcmClient::class, new FcmClient(null));
        Http::fake();
        $user = User::factory()->passenger()->create();
        $this->phone($user, 'phone-1', 'en');

        $user->notify(new AppNotification('ride_no_driver', [], 'ride:1'));

        Http::assertNothingSent();
        $this->assertSame(1, $user->notifications()->count());
    }
}
