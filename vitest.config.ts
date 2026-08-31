import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // The tool layer touches document, CustomEvent and navigator.
    environment: 'happy-dom',
    include: ['tests/js/**/*.test.ts'],
  },
});
