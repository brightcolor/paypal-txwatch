<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Zwei-Faktor-Authentifizierung - {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; border-radius: 12px; padding: 32px; width: 100%; max-width: 380px; box-shadow: 0 10px 30px rgba(0,0,0,.3); }
        h1 { font-size: 18px; margin: 0 0 6px 0; }
        p { font-size: 13px; color: #94a3b8; margin: 0 0 20px 0; }
        input { width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; font-size: 16px; letter-spacing: 2px; text-align: center; }
        button { width: 100%; margin-top: 16px; padding: 10px; border-radius: 8px; border: none; background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; }
        .error { color: #f87171; font-size: 13px; margin-top: 10px; }
        .logout { text-align: center; margin-top: 16px; }
        .logout a { color: #64748b; font-size: 12px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Zwei-Faktor-Authentifizierung</h1>
    <p>Bitte gib den 6-stelligen Code aus deiner Authenticator-App ein, oder einen deiner Wiederherstellungscodes.</p>

    <form method="POST" action="{{ route('two-factor.verify') }}">
        @csrf
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="123456">
        @error('code')
            <div class="error">{{ $message }}</div>
        @enderror
        <button type="submit">Bestätigen</button>
    </form>

    <div class="logout">
        <form method="POST" action="/admin/logout">
            @csrf
            <a href="#" onclick="this.closest('form').submit(); return false;">Abmelden</a>
        </form>
    </div>
</div>
</body>
</html>
