import { createRoot } from "react-dom/client";
import App from "./App.tsx";
import { WithRuntimeSync, startRuntimeSync } from "./lib/runtime";
import { bootTheme } from "./lib/theme";
import "./index.css";

bootTheme();
startRuntimeSync();

createRoot(document.getElementById("root")!).render(
  <WithRuntimeSync render={() => <App />} />
);
