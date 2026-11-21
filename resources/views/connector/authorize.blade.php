@php
    $appearance = request()->cookie('appearance', 'system');
    $appearance = in_array($appearance, ['light', 'dark', 'system'], true) ? $appearance : 'system';
@endphp
<!DOCTYPE html>
<html lang="en" data-appearance="{{ $appearance }}" @class(['dark' => $appearance === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="same-origin">
        <title>Connect an app to KPOne</title>
        <style>
            :root { color-scheme: light; --bg: #fff; --fg: #0a0a0a; --muted: #52525b; --card: #fff; --border: #d4d4d8; --brand: #be185d; --brand-fg: #fff; }
            html[data-appearance="dark"] { color-scheme: dark; --bg: #0a0a0a; --fg: #fafafa; --muted: #a1a1aa; --card: #18181b; --border: #3f3f46; --brand: #f472b6; --brand-fg: #0a0a0a; }
            @media (prefers-color-scheme: dark) {
                html[data-appearance="system"] { color-scheme: dark; --bg: #0a0a0a; --fg: #fafafa; --muted: #a1a1aa; --card: #18181b; --border: #3f3f46; --brand: #f472b6; --brand-fg: #0a0a0a; }
            }
            * { box-sizing: border-box; }
            body { margin: 0; background: var(--bg); color: var(--fg); font-family: ui-sans-serif, system-ui, sans-serif; line-height: 1.5; }
            main { min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
            .card { width: 100%; max-width: 28rem; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; }
            h1 { margin: 0 0 8px; font-size: 1.25rem; }
            p { margin: 0 0 12px; color: var(--muted); }
            ul { margin: 0 0 16px; padding-left: 20px; }
            li { margin-bottom: 4px; }
            .who { color: var(--fg); font-weight: 600; word-break: break-word; }
            .actions { display: flex; flex-wrap: wrap; gap: 12px; }
            form { flex: 1 1 8rem; margin: 0; }
            button { width: 100%; min-height: 44px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--fg); font: inherit; font-weight: 600; cursor: pointer; }
            button.allow { background: var(--brand); border-color: var(--brand); color: var(--brand-fg); }
            button:focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }
        </style>
    </head>
    <body>
        <main>
            <div class="card">
                <h1>Allow {{ $client->name }} to read KPOne totals?</h1>
                <p>You are signed in as <span class="who">{{ $user->email }}</span>.</p>
                <p>What it can see:</p>
                <ul>
                    <li>Totals only (counts and amounts) for your own branches.</li>
                </ul>
                <p>What it can never see:</p>
                <ul>
                    <li>Patient or staff names, contact details or identity numbers.</li>
                    <li>Clinical notes, diagnoses or anything typed by staff about a person.</li>
                </ul>
                <p>It cannot change anything in KPOne. KPOne never sends anything to the app by itself: the app asks, and each question is recorded.</p>
                <p>How long: each sign-in lasts about 1 hour and renews for up to 30 days. To stop it, disconnect KPOne in the app, or ask the person who manages the KPOne server to switch the connector off.</p>
                <div class="actions">
                    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="state" value="{{ $request->input('state') }}">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <button type="submit">Cancel</button>
                    </form>
                    <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                        @csrf
                        <input type="hidden" name="state" value="{{ $request->input('state') }}">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <button type="submit" class="allow">Allow</button>
                    </form>
                </div>
            </div>
        </main>
    </body>
</html>
