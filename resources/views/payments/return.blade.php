<!doctype html>
{{-- PayMongo sends the user here after paying or cancelling (Phase 8). It only leads back to the
     app; the app then asks the API whether the payment really arrived. --}}
<html lang="{{ app()->getLocale() === 'en' ? 'en' : 'fil' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Papaya HatidGo</title>
    <style>
        :root { --orange: #c2410c; --ink: #1c1714; --soft: #5c524b; --tint: #fff1e6; --green: #2e7d32; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px;
               background: #fff; color: var(--ink); font: 18px/1.45 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        main { width: 100%; max-width: 420px; text-align: center; }
        .mark { width: 88px; height: 88px; margin: 0 auto 20px; border-radius: 50%; display: grid; place-items: center;
                background: var(--tint); }
        .mark svg { width: 48px; height: 48px; }
        h1 { margin: 0 0 8px; font-size: 1.6rem; line-height: 1.2; }
        p { margin: 0 0 28px; color: var(--soft); }
        a { display: flex; align-items: center; justify-content: center; min-height: 60px; padding: 0 20px;
            border-radius: 16px; background: var(--orange); color: #fff; font-weight: 700; font-size: 1.1rem; text-decoration: none; }
        a:active { background: #9a3412; }
    </style>
</head>
<body>
<main>
    <div class="mark" aria-hidden="true">
        @if ($result === 'success')
            <svg viewBox="0 0 24 24" fill="none" stroke="#2e7d32" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        @else
            <svg viewBox="0 0 24 24" fill="none" stroke="#5c524b" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        @endif
    </div>
    <h1>{{ __($result === 'success' ? 'api.return_success_title' : 'api.return_cancel_title') }}</h1>
    <p>{{ __($result === 'success' ? 'api.return_success_body' : 'api.return_cancel_body') }}</p>
    <a href="{{ $appUrl }}">{{ __('api.return_button') }}</a>
</main>
<script>
    // Try to reopen the app right away; the button stays for phones that block this.
    window.location.href = @json($appUrl);
</script>
</body>
</html>
