import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Vitest has its own config, without the Laravel Vite plugin: that plugin is for serving and
// building the app, and it refuses to start a dev server in CI.
export default defineConfig({
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        include: ['resources/js/**/*.test.{ts,tsx}'],
        environment: 'node',
    },
});
