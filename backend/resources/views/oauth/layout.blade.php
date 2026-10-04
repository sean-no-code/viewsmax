<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'ViewsMax')</title>
    @php
        // Analytics for the connector sign-in funnel. Mirrors the SPA's
        // loadClarity / loadGa (frontend/src/lib/tracking.ts); admins are
        // skipped there too.
        $clarityId = config('mcp.tracking.clarity_id');
        $gaId = config('mcp.tracking.ga_measurement_id');
        $trackingAllowed = ! auth('web')->user()?->isAdmin();
    @endphp
    @if ($clarityId && $trackingAllowed)
        <script>
            window.clarity = window.clarity || function () { (window.clarity.q = window.clarity.q || []).push(arguments); };
        </script>
        <script async src="https://www.clarity.ms/tag/{{ rawurlencode($clarityId) }}"></script>
    @endif
    @if ($gaId && $trackingAllowed)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ rawurlencode($gaId) }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            gtag('js', new Date());
            gtag('config', @json($gaId));
        </script>
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Hanken+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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
            --font-mono: 'JetBrains Mono', ui-monospace, monospace;
            /* Landing tokens (src/index.css) for the shared header + footer. */
            --vm-red: #FF1F3D;
            --vm-red-hot: #FF4D63;
            --vm-volt: #16E0C4;
            --ink-900: #0A0A0C;
            --ink-850: #101014;
            --ink-700: #22222B;
            --fg-1: #F6F6F8;
            --fg-2: #B3B3BE;
            --fg-3: #76767F;
            --paper-0: #FFFFFF;
            --paper-1: #FAFAF8;
            --paper-2: #F2F2EE;
            --line-1: #E4E4DE;
            --line-2: #D2D2CA;
            --ink-on-paper-1: #0A0A0C;
            --ink-on-paper-2: #45454D;
            --ink-on-paper-3: #76767F;
            --dur: 200ms;
        }
        * { box-sizing: border-box; border-color: var(--border); }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--paper-1);
            color: var(--foreground);
            font-family: var(--font-body);
        }
        main.site-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 16px;
        }
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 400;
            margin: 0 0 16px;
            cursor: pointer;
        }
        .checkbox-row input { width: auto; height: auto; margin: 0; }
        .status {
            border: 1px solid #B9EBD7;
            background: #E9FBF2;
            color: #0F6B46;
            border-radius: calc(var(--radius) - 2px);
            padding: 12px;
            font-size: 14px;
            margin-bottom: 16px;
        }

        /* ---- Site header (mirrors LandingNav in LandingChrome.tsx) ---- */
        .site-nav { position: sticky; top: 0; z-index: 50; background: rgba(250,250,248,.82); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line-1); }
        .site-nav-inner { max-width: 1200px; margin: 0 auto; padding: 14px 24px; display: flex; align-items: center; gap: 24px; }
        .site-logo { display: block; line-height: 0; }
        .site-nav a, .site-foot a { transition: color var(--dur); text-decoration: none; }
        .site-nav a:hover, .site-foot a:hover { color: var(--vm-red); }
        .nav-links { display: flex; align-items: center; gap: 28px; margin-left: 16px; }
        .nav-links > a, .nav-dd > a { color: var(--ink-on-paper-2); font-weight: 600; font-size: 14.5px; display: inline-flex; align-items: center; gap: 4px; }
        .nav-links a:hover { color: var(--ink-on-paper-1); }
        .nav-dd { position: relative; }
        .nav-dd-panel { position: absolute; top: 100%; left: -12px; padding-top: 10px; opacity: 0; pointer-events: none; transform: translateY(6px); transition: opacity var(--dur), transform var(--dur); }
        .nav-dd:hover .nav-dd-panel, .nav-dd:focus-within .nav-dd-panel { opacity: 1; pointer-events: auto; transform: none; }
        .nav-dd-card { min-width: 260px; background: var(--paper-0); border: 1px solid var(--line-1); border-radius: 14px; padding: 8px; box-shadow: 0 16px 40px rgba(16,14,12,.12); }
        .nav-dd-card a { display: block; padding: 9px 12px; border-radius: 8px; }
        .nav-dd-card a:hover { background: var(--paper-2); }
        .nav-dd-label { display: block; font-weight: 700; font-size: 14px; color: var(--ink-on-paper-1); }
        .nav-dd-desc { display: block; font-size: 12.5px; color: var(--ink-on-paper-2); margin-top: 2px; }
        .nav-right { margin-left: auto; display: flex; align-items: center; gap: 14px; }
        .nav-login { color: var(--ink-on-paper-1); font-weight: 700; font-size: 14.5px; white-space: nowrap; background: none; border: none; padding: 0; cursor: pointer; font-family: var(--font-body); }
        .nav-login:hover { color: var(--vm-red); }
        .nav-user { font-size: 13px; color: var(--ink-on-paper-3); white-space: nowrap; }
        .nav-logout { margin: 0; }
        .btn-pill { background: var(--vm-red); color: #fff !important; font-weight: 700; font-size: 13.5px; padding: 9px 16px; border-radius: 999px; white-space: nowrap; transition: background var(--dur); }
        .btn-pill:hover { background: var(--vm-red-hot); }

        /* ---- Site footer (mirrors LandingFooter) ---- */
        .site-foot { background: var(--ink-900); color: var(--fg-2); }
        .site-foot-grid { max-width: 1200px; margin: 0 auto; padding: 64px 24px 40px; display: grid; grid-template-columns: 1.4fr repeat(5, 1fr); gap: 32px; }
        .site-foot-tagline { font-size: 14px; color: var(--fg-3); line-height: 1.5; margin: 16px 0 0; max-width: 240px; }
        .site-foot-heading { font-weight: 700; font-size: 13px; color: var(--fg-1); margin-bottom: 14px; }
        .site-foot-links { display: flex; flex-direction: column; gap: 10px; }
        .site-foot-links a, .site-foot-legal a { font-size: 14px; color: var(--fg-3); }
        .site-foot-bar { border-top: 1px solid var(--ink-700); }
        .site-foot-bar-inner { max-width: 1200px; margin: 0 auto; padding: 20px 24px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px; font-size: 13px; color: var(--fg-3); }
        .site-foot-legal { display: flex; gap: 20px; }
        @media (max-width: 860px) {
            .nav-links { display: none; }
            .site-foot-grid { grid-template-columns: 1fr 1fr; }
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
        .card-footer {
            font-size: 14px;
            color: var(--muted-foreground);
            text-align: center;
            margin: 20px 0 0;
        }
        .card-footer a { color: var(--foreground); }
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
    @include('partials.site-header')
    <main class="site-main">
        <div style="width: 100%; max-width: @yield('width', '28rem');">
            @yield('content')
        </div>
    </main>
    @include('partials.site-footer')
</body>
</html>
