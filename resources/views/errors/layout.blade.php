<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>@yield('title') - {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{{ asset('css/werkbank-fonts.css') }}?v={{ config('version.number') }}">
    {{-- Error pages in the workbench look of the two-factor page: paper, band,
         white card, one yellow way on. Self-contained, no panel assets. --}}
    <style>
        :root { color-scheme: light dark; --ground: #f2f0eb; --surface: #ffffff; --text: #1a1a1a; --loud: #111111; --quiet: #5e5b55; --rule: #e3e0d8; --focus: #0a86ad; }
        @media (prefers-color-scheme: dark) { :root { --ground: #0b0b0c; --surface: #141415; --text: #e6e4de; --loud: #ffffff; --quiet: #a3a097; --rule: #2a2a2d; --focus: #1dc3f3; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: var(--ground); color: var(--text); font-family: "Atkinson Hyperlegible", system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 15px; line-height: 1.5; }
        body::before { content: ""; position: fixed; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #ee318a 0 25%, #1dc3f3 25% 50%, #bfd535 50% 75%, #fed329 75% 100%); }
        .card { width: 100%; max-width: 420px; padding: 28px 32px; background: var(--surface); border-radius: 12px; box-shadow: 0 0 0 1px var(--rule), 0 8px 28px rgba(17, 17, 17, .16); }
        h1 { margin: 0 0 8px; font-family: Anton, Impact, "Arial Narrow", sans-serif; font-size: 24px; font-weight: 400; line-height: 1.1; letter-spacing: .03em; text-transform: uppercase; color: var(--loud); }
        p { margin: 0 0 20px; font-size: 14px; color: var(--quiet); }
        a.weiter { display: block; min-height: 40px; padding: 9px 16px; border-radius: 8px; background: #fed329; color: #111111; font-weight: 700; text-align: center; text-decoration: none; }
        a.weiter:hover { background: #e9bc0c; }
        a.weiter:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
        .kennung { margin: 16px 0 0; font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<main class="card">
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    <a class="weiter" href="@yield('link_href', url('/admin'))">@yield('link_label', 'Zur Übersicht')</a>
    <p class="kennung">Fehler @yield('code')</p>
</main>
</body>
</html>
