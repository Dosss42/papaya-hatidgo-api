<?php

namespace App\Http\Controllers;

use App\Payments\FakeGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * The two web pages of the payment flow (Phase 8). Not part of /api: a browser opens them.
 *
 * - return:  PayMongo's success_url / cancel_url. Shows "Back to the app", a deep link that
 *            reopens the app, which then asks the API for the real status. This page proves
 *            NOTHING about the payment (guarantee #1): anyone can open it.
 * - fake:    the local stand-in for PayMongo's checkout page (PAYMENT_GATEWAY=fake only).
 */
class PaymentPageController extends Controller
{
    public function return(Request $request): View
    {
        App::setLocale($request->query('lang') === 'en' ? 'en' : 'fil');
        $result = $request->query('result') === 'success' ? 'success' : 'cancelled';

        return view('payments.return', [
            'result' => $result,
            'appUrl' => config('payments.app_return_url').'?result='.$result,
        ]);
    }

    public function fakeShow(string $checkout): View
    {
        return view('payments.fake-checkout', [
            'id' => $checkout,
            'checkout' => $this->fakeCheckout($checkout),
        ]);
    }

    public function fakePay(Request $request, string $checkout): RedirectResponse
    {
        $data = $this->fakeCheckout($checkout);
        $method = in_array($request->input('method'), ['gcash', 'paymaya', 'card'], true) ? $request->input('method') : 'gcash';
        FakeGateway::markPaid($checkout, $method);

        return redirect()->away($data['success_url']);
    }

    public function fakeCancel(string $checkout): RedirectResponse
    {
        return redirect()->away($this->fakeCheckout($checkout)['cancel_url']);
    }

    /** @return array<string, mixed> */
    private function fakeCheckout(string $id): array
    {
        abort_unless(config('payments.gateway') === 'fake' && ! app()->isProduction(), 404);

        return Cache::get(FakeGateway::cacheKey($id)) ?? abort(404);
    }
}
