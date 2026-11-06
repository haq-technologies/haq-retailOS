import inertia from '@inertiajs/vite';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// In the development stack Vite runs in a container; the browser on the computer reaches it at localhost:5173.
const inContainer = process.env.VITE_POLL === '1';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react(),
        tailwindcss(),
    ],
    server: {
        host: inContainer ? '0.0.0.0' : 'localhost',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        hmr: { host: 'localhost' },
        watch: {
            // Colima passes no file-change events into its VM, so the container polls.
            usePolling: inContainer,
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
