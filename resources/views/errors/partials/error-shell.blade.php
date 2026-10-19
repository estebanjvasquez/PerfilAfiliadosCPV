{{--
    CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES (Issue #2 `6264837159`): marco común de las páginas de error en
    español. Es HTML autocontenido: no usa el panel ni la sesión ni el usuario, y no carga recursos externos. Así
    funciona aunque el error ocurra antes de que haya panel o sesión, y nunca muestra detalles técnicos.
    Recibe $title, $lead, $items (lista de ayudas, opcional) y $links ([texto => url]).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f9fafb; --card: #ffffff; --text: #111827; --muted: #4b5563; --border: #e5e7eb; --accent: #b45309; --accent-text: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b0b0c; --card: #18181b; --text: #f4f4f5; --muted: #a1a1aa; --border: #27272a; --accent: #f59e0b; --accent-text: #111827; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 560px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 28px 24px; }
        .code { color: var(--muted); font-size: 14px; margin: 0 0 4px; }
        h1 { font-size: 24px; line-height: 1.25; margin: 0 0 12px; }
        p, li { color: var(--muted); }
        ul { padding-left: 20px; margin: 12px 0 20px; }
        li { margin: 4px 0; }
        nav { display: flex; flex-wrap: wrap; gap: 8px; }
        nav a { display: inline-block; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); color: var(--text); text-decoration: none; font-weight: 600; font-size: 15px; }
        nav a:first-child { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
        nav a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <p class="code">Error 404</p>
        <h1>{{ $title }}</h1>
        <p>{{ $lead }}</p>
        @if (! empty($items))
            <ul>
                @foreach ($items as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif
        @if (! empty($links))
            <nav aria-label="Opciones">
                @foreach ($links as $text => $url)
                    <a href="{{ $url }}">{{ $text }}</a>
                @endforeach
            </nav>
        @endif
    </main>
</body>
</html>
