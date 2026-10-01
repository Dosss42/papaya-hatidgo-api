<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * php artisan paymongo:webhook https://<your-tunnel>/api/v1/webhooks/paymongo
 *
 * Tells PayMongo where to send "checkout paid" events, and prints the webhook SECRET that
 * PayMongo creates for it (put it in .env as PAYMONGO_WEBHOOK_SECRET). Needs
 * PAYMONGO_SECRET_KEY in .env. PayMongo can't reach 127.0.0.1, so during development the URL
 * is a tunnel (cloudflared / ngrok). Without a webhook, reconciliation still activates payments.
 */
class RegisterPayMongoWebhook extends Command
{
    protected $signature = 'paymongo:webhook {url : The public HTTPS address of /api/v1/webhooks/paymongo}';

    protected $description = 'Register this API\'s webhook URL in PayMongo and print the webhook secret for .env';

    public function handle(): int
    {
        $secretKey = (string) config('services.paymongo.secret_key');
        if ($secretKey === '') {
            $this->error('Set PAYMONGO_SECRET_KEY in .env first (a TEST key, sk_test_…).');

            return self::FAILURE;
        }

        $response = Http::baseUrl((string) config('services.paymongo.base_url'))
            ->withBasicAuth($secretKey, '')
            ->acceptJson()
            ->post('/webhooks', ['data' => ['attributes' => [
                'url' => $this->argument('url'),
                'events' => ['checkout_session.payment.paid'],
            ]]]);

        if ($response->failed()) {
            $this->error('PayMongo refused: '.json_encode($response->json('errors')));

            return self::FAILURE;
        }

        $this->info('Webhook registered: '.$response->json('data.id'));
        $this->line('Add this line to .env, then restart php artisan serve:');
        $this->line('PAYMONGO_WEBHOOK_SECRET='.$response->json('data.attributes.secret_key'));

        return self::SUCCESS;
    }
}
