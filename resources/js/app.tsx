import '../css/app.css';
import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

createInertiaApp({
    title: title => `${title} · Smart Market AI`,
    resolve: async name => {
        const page = await resolvePageComponent<{ default: ResolvedComponent }>(`./Pages/${name}.tsx`, import.meta.glob<{ default: ResolvedComponent }>('./Pages/*.tsx'));
        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#17765c' },
});
