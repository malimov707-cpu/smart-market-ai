<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        try {
            const theme = localStorage.getItem('smart-market-theme');
            document.documentElement.dataset.theme = theme === 'light' ? 'light' : 'dark';
        } catch {
            document.documentElement.dataset.theme = 'dark';
        }
    </script>
    @viteReactRefresh
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>
<body>@inertia</body>
</html>
