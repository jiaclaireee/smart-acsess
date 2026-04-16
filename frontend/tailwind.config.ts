import type { Config } from "tailwindcss"

export default <Partial<Config>>{
  content: [
    "./components/**/*.{vue,js,ts}",
    "./layouts/**/*.vue",
    "./pages/**/*.vue",
    "./app.vue"
  ],
  theme: {
    extend: {
      colors: {
        uplbGreen: "#00563F",
        uplbRed: "#8D1436",
        uplbYellow: "#FFB71C"
      },
      fontFamily: {
        sans: ["Poppins", "system-ui", "sans-serif"],
        futura: ["Futura", "Futura PT", "Poppins", "system-ui", "sans-serif"]
      },
      boxShadow: {
        soft: "0 8px 24px rgba(15, 23, 42, 0.08)"
      }
    }
  },
  plugins: []
}
