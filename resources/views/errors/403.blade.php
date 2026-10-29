@php
    // Permission middleware can deny access before HandleAppearance runs.
    $appearance = request()->cookie('appearance', 'system');
    $appearance = in_array($appearance, ['light', 'dark', 'system'], true) ? $appearance : 'system';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-appearance="{{ $appearance }}" @class(['dark' => $appearance === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>403 Forbidden — KPOne</title>
        <style>
            html {
                color-scheme: light;
                background: #fff;
                color: #0a0a0a;
                font-family: ui-sans-serif, system-ui, sans-serif;
            }
            html[data-appearance="dark"] {
                color-scheme: dark;
                background: #0a0a0a;
                color: #fafafa;
            }
            @media (prefers-color-scheme: dark) {
                html[data-appearance="system"] {
                    color-scheme: dark;
                    background: #0a0a0a;
                    color: #fafafa;
                }
            }
            * { box-sizing: border-box; }
            body { margin: 0; }
            main {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                min-height: 100dvh;
                padding: 24px;
            }
            .message {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: center;
                gap: 16px;
                max-width: 100%;
            }
            h1 {
                margin: 0;
                padding-right: 16px;
                border-right: 1px solid currentColor;
                font-size: 18px;
                line-height: 1.5;
            }
            p { margin: 0; font-size: 18px; line-height: 1.5; }
        </style>
    </head>
    <body>
        <main>
            <div class="message">
                <h1>403</h1>
                <p>Forbidden</p>
            </div>
        </main>
    </body>
</html>
