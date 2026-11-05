import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['tests/Js/**/*.test.js'],
        globals: false,
        clearMocks: true,
        restoreMocks: true,
    },
});
