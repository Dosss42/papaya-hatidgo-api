<!doctype html>
{{-- The LOCAL stand-in for PayMongo's checkout page (PAYMENT_GATEWAY=fake, development only).
     "Pay" marks the test checkout paid in the cache; the server still confirms it by asking the
     gateway (reconciliation) before activating anything. No real money, no real card. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test checkout</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px 16px; background: #f4f1ee; color: #1c1714;
               font: 18px/1.45 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        main { max-width: 440px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 24px; box-shadow: inset 0 0 0 2px #d8cfc6; }
        .test { display: inline-block; margin-bottom: 12px; padding: 2px 10px; border-radius: 999px; background: #fff1e6;
                color: #c2410c; font-weight: 700; font-size: .95rem; box-shadow: inset 0 0 0 2px #c2410c; }
        h1 { margin: 0 0 4px; font-size: 1.3rem; }
        .amount { margin: 0 0 20px; font-size: 2rem; font-weight: 800; }
        fieldset { border: 0; padding: 0; margin: 0 0 20px; display: grid; gap: 10px; }
        legend { font-weight: 700; margin-bottom: 8px; }
        label { display: flex; align-items: center; gap: 12px; min-height: 56px; padding: 0 16px; border-radius: 16px;
                box-shadow: inset 0 0 0 2px #8a7f77; }
        input[type=radio] { width: 22px; height: 22px; accent-color: #c2410c; }
        button { width: 100%; min-height: 60px; border: 0; border-radius: 16px; font: inherit; font-weight: 700; font-size: 1.1rem; }
        .pay { background: #c2410c; color: #fff; margin-bottom: 10px; }
        .cancel { background: transparent; color: #c2410c; text-decoration: underline; }
        small { display: block; margin-top: 16px; color: #5c524b; }
    </style>
</head>
<body>
<main>
    <span class="test">TEST MODE · no real money</span>
    <h1>{{ $checkout['description'] }}</h1>
    <p class="amount">₱{{ number_format($checkout['amount'] / 100, 2) }}</p>

    @if ($checkout['status'] === 'active')
        <form method="post" action="{{ route('payments.fake.pay', ['checkout' => $id]) }}">
            @csrf
            <fieldset>
                <legend>Pay with</legend>
                <label><input type="radio" name="method" value="gcash" checked> GCash</label>
                <label><input type="radio" name="method" value="paymaya"> Maya</label>
                <label><input type="radio" name="method" value="card"> Card</label>
            </fieldset>
            <button class="pay" type="submit">Pay (test)</button>
        </form>
        <form method="post" action="{{ route('payments.fake.cancel', ['checkout' => $id]) }}">
            @csrf
            <button class="cancel" type="submit">Cancel</button>
        </form>
    @else
        <p>This test checkout is {{ $checkout['status'] }}.</p>
    @endif
    <small>Papaya HatidGo local test gateway (PAYMENT_GATEWAY=fake). With real PayMongo test keys, PayMongo's own page opens instead.</small>
</main>
</body>
</html>
