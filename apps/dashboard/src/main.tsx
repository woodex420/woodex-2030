import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "./index.css";
import App from "@/App";
import { installFetchPatch, restoreSession } from "@/lib/auth";

installFetchPatch();
void restoreSession();

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <App />
  </StrictMode>
);
