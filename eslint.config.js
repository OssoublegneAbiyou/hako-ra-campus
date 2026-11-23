import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import hooks from 'eslint-plugin-react-hooks';
import refresh from 'eslint-plugin-react-refresh';

export default tseslint.config(
    { ignores: ['vendor/**', 'node_modules/**', 'public/build/**'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: { globals: globals.browser },
        plugins: { 'react-hooks': hooks, 'react-refresh': refresh },
        rules: {
            ...hooks.configs.recommended.rules,
            'react-refresh/only-export-components': ['error', { allowConstantExport: true }],
        },
    },
);
