const shades = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts,js}'],
  theme: {
    extend: {
      colors: {
        // Themeable brand palette: the values live in CSS variables set by
        // src/theme/themes.ts (RGB triplets), so `brand-600/50` keeps working.
        brand: Object.fromEntries(
          shades.map((shade) => [shade, `rgb(var(--brand-${shade}) / <alpha-value>)`]),
        ),
      },
      fontFamily: {
        sans: [
          'Inter',
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          'Segoe UI',
          'Roboto',
          'sans-serif',
        ],
      },
    },
  },
  plugins: [],
};
