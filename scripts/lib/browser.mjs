/**
 * One way to start the browser for a browser check.
 *
 * In CI, `npx playwright install --with-deps chromium` provides the browser and
 * nothing needs configuring. Some machines cannot install that build (no
 * network to the download host, a sandbox without the system libraries); there
 * a Chromium that is already on disk can be named instead:
 *
 *   E2E_CHROMIUM_PATH=/path/to/chromium        the executable to launch
 *   E2E_CHROMIUM_ARGS="--no-sandbox --foo"     extra arguments, space-separated
 *
 * A browser that cannot start is a SKIP on a developer machine and an ERROR
 * when E2E_REQUIRED=1 (CI), so a gate can never pass by not running.
 */
export const browserRequired = process.env.E2E_REQUIRED === "1";

export function unavailable(message) {
  console.log(`${browserRequired ? "ERROR" : "SKIP"}: ${message}`);
  process.exit(browserRequired ? 1 : 0);
}

/** Options for `chromium.launch()`: empty unless E2E_CHROMIUM_PATH / E2E_CHROMIUM_ARGS are set. */
export function launchOptions() {
  const executablePath = process.env.E2E_CHROMIUM_PATH || undefined;
  const args = (process.env.E2E_CHROMIUM_ARGS ?? "").split(/\s+/).filter(Boolean);
  return { ...(executablePath ? { executablePath } : {}), ...(args.length ? { args } : {}) };
}

export async function launchBrowser() {
  let chromium;
  try { ({ chromium } = await import("playwright")); }
  catch { unavailable("Playwright is unavailable (npm i -D playwright)."); }
  try {
    return await chromium.launch(launchOptions());
  } catch (error) {
    unavailable("no browser binary — run `npx playwright install chromium`, or set E2E_CHROMIUM_PATH. ("
      + String(error.message).split("\n")[0] + ")");
  }
}
