import { defineNuxtConfig } from "nuxt/config"

export default defineNuxtConfig({
  ssr: false,
  buildDir: process.env.NUXT_BUILD_DIR || ".nuxt",
  experimental: {
    appManifest: false,
  },
  css: ["~/assets/css/main.css"],
  postcss: {
    plugins: {
      tailwindcss: {},
      autoprefixer: {},
    },
  },
  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || "http://127.0.0.1:8001",
      backendBase: process.env.NUXT_PUBLIC_BACKEND_BASE || process.env.NUXT_PUBLIC_API_BASE || "http://127.0.0.1:8001",
    },
  },
  compatibilityDate: "2026-03-01",
})
