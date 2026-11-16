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

/**
 * Sign in to the dashboard through its own form, as a person does.
 *
 * The form asks the API whether the demo account exists, and fills it in when
 * the answer arrives. A script types within milliseconds of the page loading —
 * while that answer is still on its way — and its typing then raced the fill:
 * measured, one sign-in in 120 was refused with "wrong email or password", the
 * password field holding "adminadmin". The form now keeps whatever has been
 * typed, and it says when it has heard from the API (aria-busy="false").
 * Every journey waits for that and then types, so that none of them depends on
 * which side of a race wins.
 */
export async function signInToDashboard(page, { user = "admin", password = "admin" } = {}) {
  await page.locator('form[aria-busy="false"]').first().waitFor();
  await page.getByPlaceholder("admin").first().fill(user);
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole("button", { name: /^sign in$/i }).click();
}
