/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.{ts,tsx}',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
        mono: ['JetBrains Mono Variable', 'JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
        serif: ['-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
      },
      colors: {
        // Single blue accent ramp in the Helicon family (hue 257).
        accent: {
          50: 'oklch(0.95 0.03 257 / <alpha-value>)',
          100: 'oklch(0.90 0.06 257 / <alpha-value>)',
          200: 'oklch(0.82 0.10 257 / <alpha-value>)',
          300: 'oklch(0.74 0.13 257 / <alpha-value>)',
          400: 'oklch(0.66 0.16 257 / <alpha-value>)',
          500: 'oklch(0.60 0.18 257 / <alpha-value>)',
          600: 'oklch(0.55 0.19 257 / <alpha-value>)',
          700: 'oklch(0.48 0.18 257 / <alpha-value>)',
          800: 'oklch(0.42 0.16 257 / <alpha-value>)',
          900: 'oklch(0.36 0.14 257 / <alpha-value>)',
          950: 'oklch(0.28 0.12 257 / <alpha-value>)',
        },
      },
    },
  },
  plugins: [],
};
