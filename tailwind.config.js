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
      // Mismas familias que los tokens de app.css: Aptos y Segoe UI vienen
      // instaladas en los equipos del equipo, así que no hay descarga remota
      // que bloquee el primer pintado. La jerarquía la dan peso y tracking.
      fontFamily: {
        sans: ['Aptos', '"Segoe UI Variable Text"', '"Segoe UI"', 'system-ui', 'Arial', 'sans-serif'],
        display: ['Aptos', '"Segoe UI Variable Display"', '"Segoe UI"', 'system-ui', 'Arial', 'sans-serif'],
        mono: ['"Cascadia Mono"', 'Consolas', '"Segoe UI Mono"', 'ui-monospace', 'monospace'],
      },
    },
  },
  // Belt-and-suspenders for classes built dynamically in PHP (status/priority
  // chips, KPI tones, brand utilities) so the build never drops them.
  safelist: [
    'ring-1',
    'underline',
    { pattern: /^(bg|text|ring|border)-(amber|emerald|blue|red|slate|green|sky|rose|orange)-(50|100|200|300|400|500|600|700|800|900)$/ },
    { pattern: /^(bg|text|border|ring)-sch-(ink|muted|line|page|blue|cyan|green|graphite)$/ },
  ],
  corePlugins: {
    preflight: true,
  },
};
