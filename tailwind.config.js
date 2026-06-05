/** @type {import('tailwindcss').Config} */
module.exports = {
  // Scan every server-rendered template so no used utility is purged.
  content: [
    './*.php',
    './crm/**/*.php',
    './includes/**/*.php',
    './data/**/*.php',
    './config/**/*.php',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        sch: {
          ink: '#0e1a28',
          muted: '#56697b',
          line: '#e3eaf1',
          page: '#f3f7fb',
          blue: '#0666b3',
          cyan: '#1fa6d8',
          green: '#15a34a',
          graphite: '#0b1521',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        display: ['"Space Grotesk"', 'sans-serif'],
      },
    },
  },
  // Belt-and-suspenders for classes built dynamically in PHP (status/priority
  // chips, KPI tones, brand utilities) so the build never drops them.
  safelist: [
    'ring-1',
    'underline',
    { pattern: /^(bg|text|ring|border)-(amber|emerald|blue|red|slate|green|sky|rose|orange)-(50|100|200|300|400|500|600|700|800)$/ },
    { pattern: /^(bg|text|border|ring)-sch-(ink|muted|line|page|blue|cyan|green|graphite)$/ },
  ],
  corePlugins: {
    preflight: true,
  },
};
