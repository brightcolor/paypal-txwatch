<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $mode === 'pin' ? 'PIN eingeben' : 'Zwei-Faktor-Authentifizierung' }} - {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{{ asset('css/werkbank-fonts.css') }}?v={{ config('version.number') }}">
    {{-- Workbench look outside the panel: paper, four-colour band, white card,
         yellow main button. Dark mode follows the system setting. --}}
    <style>
        :root { color-scheme: light dark; --ground: #f2f0eb; --surface: #ffffff; --text: #1a1a1a; --loud: #111111; --quiet: #5e5b55; --rule: #e3e0d8; --edge: #8e8a80; --focus: #0a86ad; --bad: #b3146a; }
        @media (prefers-color-scheme: dark) { :root { --ground: #0b0b0c; --surface: #141415; --text: #e6e4de; --loud: #ffffff; --quiet: #a3a097; --rule: #2a2a2d; --edge: #6e6e74; --focus: #1dc3f3; --bad: #ff5ca8; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: var(--ground); color: var(--text); font-family: "Atkinson Hyperlegible", system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 15px; line-height: 1.5; }
        body::before { content: ""; position: fixed; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #ee318a 0 25%, #1dc3f3 25% 50%, #bfd535 50% 75%, #fed329 75% 100%); }
        .card { width: 100%; max-width: 380px; padding: 28px 32px; background: var(--surface); border-radius: 12px; box-shadow: 0 0 0 1px var(--rule), 0 8px 28px rgba(17, 17, 17, .16); }
        h1 { margin: 0 0 8px; font: 400 24px/1.1 Anton, Impact, "Arial Narrow", sans-serif; letter-spacing: .03em; text-transform: uppercase; color: var(--loud); }
        p { margin: 0 0 20px; font-size: 14px; color: var(--quiet); }
        input { width: 100%; height: 44px; padding: 0 12px; border-radius: 8px; border: 1px solid var(--edge); background: var(--surface); color: var(--text); font: inherit; font-size: 18px; letter-spacing: 3px; text-align: center; }
        input:focus { outline: 0; border-color: var(--focus); box-shadow: 0 0 0 3px color-mix(in srgb, var(--focus) 22%, transparent); }
        button { width: 100%; min-height: 40px; margin-top: 16px; border: 0; border-radius: 8px; background: #fed329; color: #111111; font: inherit; font-weight: 700; cursor: pointer; }
        button:hover { background: #e9bc0c; }
        button:focus-visible, a:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
        .error { margin-top: 10px; color: var(--bad); font-size: 13px; font-weight: 700; }
        .links { display: flex; flex-direction: column; align-items: center; gap: 10px; margin-top: 18px; }
        .links form { margin: 0; }
        .links a, button.link { width: auto; min-height: 0; margin: 0; padding: 0; border: 0; background: none; color: var(--quiet); font: inherit; font-size: 13px; font-weight: 400; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
        .links a:hover, button.link:hover { background: none; color: var(--loud); }
    </style>
</head>
<body>
<div class="card">
    @if ($mode === 'pin')
        <h1>PIN eingeben</h1>
        <p>Dieses Gerät ist bestätigt. Gib deine PIN ein, um weiterzuarbeiten.</p>

        <form method="POST" action="{{ route('two-factor.verify') }}">
            @csrf
            <input type="password" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="{{ \App\Support\TwoFactorSettings::PIN_MAX_LENGTH }}" autocomplete="off" autofocus aria-label="PIN" @error('pin') aria-invalid="true" aria-describedby="pin-fehler" @enderror>
            @error('pin')
                <div class="error" id="pin-fehler">{{ $message }}</div>
            @enderror
            <button type="submit">Entsperren</button>
        </form>
    @else
        <h1>Zwei-Faktor-Authentifizierung</h1>
        <p>Bitte gib den 6-stelligen Code aus deiner Authenticator-App ein, oder einen deiner Wiederherstellungscodes.</p>

        <form method="POST" action="{{ route('two-factor.verify') }}">
            @csrf
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="123456" aria-label="Code" @error('code') aria-invalid="true" aria-describedby="code-fehler" @enderror>
            @error('code')
                <div class="error" id="code-fehler">{{ $message }}</div>
            @enderror
            <button type="submit">Bestätigen</button>
        </form>
    @endif

    <div class="links">
        @if ($mode === 'pin')
            <a href="{{ route('two-factor.challenge', ['via' => 'code']) }}">Code aus der Authenticator-App verwenden</a>
        @elseif ($pinUsable)
            <a href="{{ route('two-factor.challenge') }}">Mit PIN entsperren</a>
        @endif
        <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
            @csrf
            <button type="submit" class="link">Abmelden</button>
        </form>
    </div>
</div>
</body>
</html>
