import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['vendor/**', 'node_modules/**', 'public/**', 'storage/**', 'bootstrap/**'] },
    js.configs.recommended,
    ...tseslint.configs.strictTypeChecked,
    reactHooks.configs.flat.recommended,
    {
        languageOptions: {
            globals: { ...globals.browser },
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
    },
    {
        files: ['eslint.config.js', 'vite.config.ts', 'vitest.config.ts'],
        languageOptions: { globals: { ...globals.node } },
    },
    {
        // Plain JavaScript files are outside the TypeScript project, so type-aware rules skip them.
        files: ['**/*.js'],
        ...tseslint.configs.disableTypeChecked,
    },
);
