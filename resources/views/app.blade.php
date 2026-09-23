<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Scrub the QR fragment before any application asset or follow-up request. --}}
        <script>
            (function() {
                if (window.location.pathname !== '/check-in') {
                    return;
                }

                const exchangeAttempted = window.location.hash !== '' || window.location.search !== '';
                const token = window.location.hash.startsWith('#')
                    ? window.location.hash.slice(1)
                    : '';

                if (window.location.hash || window.location.search) {
                    window.history.replaceState(window.history.state, '', window.location.pathname);
                }

                if (exchangeAttempted) {
                    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
                    document.cookie = @json(config('public-intake.exchange_attempt_cookie'))
                        + '=1; Path=/check-in; Max-Age=300; SameSite=Lax'
                        + secure;
                }

                if (/^[A-Za-z0-9_-]{43}$/.test(token)) {
                    window.__KPOnePublicIntakeExchangeToken = token;
                }
                window.__KPOnePublicIntakeExchangeAttempted = exchangeAttempted;
            })();
        </script>

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">


        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
