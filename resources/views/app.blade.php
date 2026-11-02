<!DOCTYPE html>
<html lang="en" class="h-full" data-theme="light" data-theme-preference="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <script>
    (function () {
      try {
        var pref = localStorage.getItem('proofpatch-theme');
        if (pref !== 'light' && pref !== 'dark' && pref !== 'system') {
          pref = 'light';
        }
        var sysDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var resolved = pref === 'system' ? (sysDark ? 'dark' : 'light') : pref;
        var meta = document.querySelector('meta[name="color-scheme"]');
        if (meta) {
          meta.content = pref === 'system' ? 'light dark' : resolved;
        }
        var root = document.documentElement;
        root.dataset.themePreference = pref;
        root.dataset.theme = resolved;
        root.classList.toggle('dark', resolved === 'dark');
      } catch (e) {
        document.documentElement.dataset.themePreference = 'light';
        document.documentElement.dataset.theme = 'light';
      }
    })();
    </script>
    <title inertia>ProofPatcher</title>
    <link rel="icon" type="image/svg+xml" href="/icon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-full flex flex-col bg-[var(--bg)] text-[var(--fg)] antialiased font-mono">
    @inertia
</body>
</html>
