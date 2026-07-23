<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'ViewsMax')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Mirrors the SPA's design tokens (src/index.css): Max Red primary,
           Archivo display / Hanken Grotesk body, shadcn-style card + inputs. */
        :root {
            --background: hsl(0 0% 100%);
            --foreground: hsl(0 0% 15.69%);
            --primary: hsl(351 100% 56%);
            --primary-hover: hsl(351 100% 51%);
            --primary-foreground: #fff;
            --muted-foreground: hsl(225 15% 45%);
            --border: hsl(0 0% 89.41%);
            --input: hsl(214.3 31.8% 91.4%);
            --ring: hsl(222.2 84% 4.9%);
            --radius: 0.5rem;
            --shadow-card: 0 4px 20px -2px hsl(225 25% 12% / 0.08);
            --font-display: 'Archivo', system-ui, sans-serif;
            --font-body: 'Hanken Grotesk', system-ui, sans-serif;
        }
        * { box-sizing: border-box; border-color: var(--border); }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--background);
            color: var(--foreground);
            font-family: var(--font-body);
            padding: 16px;
        }
        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .brand span {
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 20px;
            letter-spacing: -0.02em;
        }
        .card {
            width: 100%;
            max-width: 28rem;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-card);
            padding: 24px;
        }
        .card-title {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 24px;
            line-height: 1.15;
            letter-spacing: -0.02em;
            text-align: center;
            margin: 0 0 6px;
        }
        .card-description {
            font-size: 14px;
            color: var(--muted-foreground);
            text-align: center;
            margin: 0 0 24px;
        }
        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        input {
            width: 100%;
            height: 40px;
            padding: 8px 12px;
            font-size: 14px;
            font-family: var(--font-body);
            color: var(--foreground);
            background: var(--background);
            border: 1px solid var(--input);
            border-radius: calc(var(--radius) - 2px);
            outline: none;
            margin-bottom: 16px;
        }
        input:focus-visible {
            box-shadow: 0 0 0 2px var(--background), 0 0 0 4px var(--ring);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 40px;
            padding: 8px 16px;
            font-family: var(--font-body);
            font-size: 14px;
            font-weight: 500;
            border-radius: calc(var(--radius) - 2px);
            border: 1px solid transparent;
            cursor: pointer;
            transition: background-color 120ms ease, opacity 120ms ease;
        }
        .btn-primary {
            background: var(--primary);
            color: var(--primary-foreground);
        }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline {
            background: var(--background);
            color: var(--foreground);
            border-color: var(--input);
        }
        .btn-outline:hover { background: hsl(220 14% 96%); }
        .error {
            border: 1px solid hsl(351 100% 85%);
            background: #FFE7EA;
            color: hsl(351 80% 35%);
            border-radius: calc(var(--radius) - 2px);
            padding: 12px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .muted { color: var(--muted-foreground); }
    </style>
</head>
<body>
    <div style="width: 100%; max-width: 28rem;">
        <div class="brand">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                <rect x="1" y="1" width="30" height="30" rx="8" fill="#FF1F3D" />
                <circle cx="9.5" cy="16" r="2.6" fill="#fff" />
                <circle cx="22" cy="9.5" r="2.6" fill="#fff" />
                <circle cx="22" cy="22.5" r="2.6" fill="#16E0C4" />
                <path d="M11.6 14.7 L20 10.4 M11.6 17.3 L20 21.6" stroke="#fff" stroke-width="1.8" stroke-linecap="round" />
            </svg>
            <span>ViewsMax</span>
        </div>
        @yield('content')
    </div>
</body>
</html>
