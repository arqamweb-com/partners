import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import tsConfigPaths from "vite-tsconfig-paths";
import { tanstackRouter } from "@tanstack/router-plugin/vite";

/**
 * بناء SPA ثابت.
 *
 * كان المشروع يُبنى بـ TanStack Start (SSR على Node/Cloudflare) وهو ما لا
 * تشغّله الاستضافة المشتركة. الآن الناتج في dist/ ملفات ثابتة يقدّمها أباتشي،
 * والبيانات كلها تأتي من /api (PHP + MySQL). Node مطلوب وقت البناء فقط.
 */
export default defineConfig({
  plugins: [
    // لازم يسبق react() حتى يولّد routeTree.gen.ts
    tanstackRouter({ target: "react", autoCodeSplitting: true }),
    react(),
    tailwindcss(),
    tsConfigPaths(),
  ],

  server: {
    host: "127.0.0.1",
    port: 5173,
    /*
     * أثناء التطوير: طلبات /api تُمرَّر لخادم لارافيل (php artisan serve).
     * changeOrigin: false مقصود — الكوكي والـ CSRF مربوطان بأصل الطلب،
     * وتغييره يكسر الجلسة.
     */
    proxy: {
      "/api": {
        target: process.env["VITE_API_TARGET"] || "http://127.0.0.1:8000",
        changeOrigin: false,
      },
    },
  },

  build: {
    outDir: "dist",
    sourcemap: false,
  },
});
