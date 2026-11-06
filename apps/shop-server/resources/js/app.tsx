import '@fontsource-variable/inter';
import { createInertiaApp } from '@inertiajs/react';

// Pages live in resources/js/pages; the Inertia Vite plugin resolves them.
// Every prop a page receives is an explicit array or API Resource, never an Eloquent model (spec section 4).
void createInertiaApp({
    title: (title) => (title ? `${title} · HAQ Retail OS` : 'HAQ Retail OS'),
    strictMode: true,
    progress: false,
});
