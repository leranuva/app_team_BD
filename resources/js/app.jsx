import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Blue Draft';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const pages = import.meta.glob(['./Pages/**/*.jsx', './Pages/**/*.tsx']);
        const jsx = `./Pages/${name}.jsx`;
        const tsx = `./Pages/${name}.tsx`;
        if (pages[jsx] && pages[tsx]) {
            throw new Error(`Duplicate page: ${name}. Keep only ${name}.tsx.`);
        }
        return resolvePageComponent([jsx, tsx], pages);
    },
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
