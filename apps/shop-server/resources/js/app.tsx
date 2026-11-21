import '@fontsource-variable/inter';
import { createInertiaApp } from '@inertiajs/react';
import { CrashScreen } from '@/components/crash-screen';
import { installErrorReporting } from '@/lib/error-report';

// A screen's errors reach the server's log, and a screen that fails says so and opens again (finding LG3).
installErrorReporting();

// Pages live in resources/js/pages; the Inertia Vite plugin resolves them.
// Every prop a page receives is an explicit array or API Resource, never an Eloquent model (spec section 4).
void createInertiaApp({
    title: (title) => (title ? `${title} · HAQ Retail OS` : 'HAQ Retail OS'),
    strictMode: true,
    progress: false,
    withApp: (app) => <CrashScreen>{app}</CrashScreen>,
});
