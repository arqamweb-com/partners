/**
 * نقطة دخول التطبيق (SPA).
 *
 * كان المشروع يعمل بـ SSR عبر TanStack Start ويحتاج سيرفر Node.
 * الآن التطبيق يُبنى كملفات ثابتة يقدّمها أباتشي، والبيانات تأتي من /api.
 */

import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { RouterProvider } from "@tanstack/react-router";

import { getRouter } from "./router";
import "./styles.css";

const router = getRouter();

const rootElement = document.getElementById("root");
if (!rootElement) {
  throw new Error("عنصر #root غير موجود في index.html");
}

createRoot(rootElement).render(
  <StrictMode>
    <RouterProvider router={router} />
  </StrictMode>,
);
