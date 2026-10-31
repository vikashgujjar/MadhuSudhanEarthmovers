/** Tailwind is compiled with the standalone CLI (no Node.js needed). See README. */
module.exports = {
  content: [
    "./templates/**/*.html",
    "./apps/**/templates/**/*.html",
    "./apps/**/*.py",
    "./static/js/**/*.js",
  ],
  theme: {
    extend: {
      colors: {
        navy: {
          DEFAULT: "#0B1F33",
          50: "#EEF2F7", 100: "#D6DFEA", 200: "#AFC0D4", 300: "#7F98B6",
          400: "#4F6E93", 500: "#2E4E72", 600: "#1C3A5B", 700: "#132C47",
          800: "#0B1F33", 900: "#071523", 950: "#040D17",
        },
        brand: {
          DEFAULT: "#FF6B00",
          50: "#FFF4EC", 100: "#FFE4CF", 200: "#FFC79E", 300: "#FFA35F",
          400: "#FF8529", 500: "#FF6B00", 600: "#E05A00", 700: "#B84900",
          800: "#8F3A05", 900: "#73300A",
        },
        surface: "#F5F7FA",
      },
      fontFamily: {
        sans: ["Inter", "Segoe UI", "system-ui", "-apple-system", "Roboto", "Helvetica Neue", "Arial", "sans-serif"],
        display: ["Barlow Condensed", "Inter", "Segoe UI", "sans-serif"],
      },
      boxShadow: {
        card: "0 1px 2px rgba(11,31,51,.04), 0 4px 16px rgba(11,31,51,.06)",
      },
    },
  },
  plugins: [],
};
