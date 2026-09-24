document.addEventListener('alpine:init', () => {
    /**
     * Application shell state.
     *
     * `collapsed` is a reactive boolean mirrored to the `.sidebar-collapsed`
     * class on <html> (set before first paint by a tiny inline script so the
     * shell never flashes the wrong sidebar width) and persisted in
     * localStorage. The shell components read `collapsed` through Alpine's
     * scope chain; the mobile navigation shadows it with a local `false`.
     *
     * No backend settings exist for this — it is purely a UI preference.
     */
    Alpine.data('appLayout', () => ({
        collapsed: document.documentElement.classList.contains('sidebar-collapsed'),
        toggleSidebar() {
            this.collapsed = !this.collapsed;
            document.documentElement.classList.toggle('sidebar-collapsed', this.collapsed);

            try {
                localStorage.setItem('bos-sidebar-collapsed', this.collapsed ? '1' : '0');
            } catch {
                // localStorage unavailable; the preference stays in-memory.
            }
        },
    }));
});