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
        sans: ['Inter Variable', 'Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['JetBrains Mono Variable', 'JetBrains Mono', 'Menlo', 'Monaco', 'Courier New', 'monospace'],
        display: ['Newsreader Variable', 'Newsreader', 'Iowan Old Style', 'Georgia', 'serif'],
      },
      colors: {
        // Single blue accent ramp in the Helicon family (hue 257).
        accent: {
          50: 'oklch(0.95 0.03 257)',
          100: 'oklch(0.90 0.06 257)',
          200: 'oklch(0.82 0.10 257)',
          300: 'oklch(0.74 0.13 257)',
          400: 'oklch(0.66 0.16 257)',
          500: 'oklch(0.60 0.18 257)',
          600: 'oklch(0.55 0.19 257)',
          700: 'oklch(0.48 0.18 257)',
          800: 'oklch(0.42 0.16 257)',
          900: 'oklch(0.36 0.14 257)',
          950: 'oklch(0.28 0.12 257)',
        },
      },
    },
  },
  plugins: [],
};
