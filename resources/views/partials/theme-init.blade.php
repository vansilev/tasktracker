{{-- Applies the saved theme before first paint (no flash). Modes: light, dark, system (default). --}}
<script>
    (function () {
        try {
            var mode = localStorage.getItem('theme') || 'system';
            var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    })();
</script>
