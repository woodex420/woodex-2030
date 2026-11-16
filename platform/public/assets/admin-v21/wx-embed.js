/* Woodex OS ↔ the ported v2.1 admin — the adapter (docs/audit/16.7, 16.8).
 *
 * Loaded BEFORE admin.js so that everything their shell does at boot already talks to Woodex OS:
 *   · the screen comes from the OS URL, not from their sidebar;
 *   · their two API endpoints are rewritten to the OS routes (same `{action}` protocol);
 *   · every request carries the OS CSRF token, and the session cookie is the only authentication;
 *   · their sign-in screen is never reached — the OS session is the session.
 *
 * Nothing here patches a module: the modules are copied unchanged, and every action they call resolves
 * (or honestly refuses) on the server.
 */
(function () {
  "use strict";

  var C = window.WX_STUDIO || {};

  // Marks the environment for the copied modules (like the flag read in admin-content.js publish()): the OS
  // renders pages from their blocks, so there is no static HTML to build or write on publish.
  C.staticPublish = false;
  window.WX_STUDIO = C;
  var screen = C.screen || "dashboard";

  /* ---- Only the screens the OS has are reachable ------------------------------------------------
     The copied v2.1 app also carries the screens of the CMS it was written for — Service pages, City
     pages, Page templates, FAQs, Testimonials, Team, Client logos, Header & footer, Section library,
     Hero slides — plus its own dashboard, and every one of them edits rows. The website is its page
     files (docs/audit/19, 20), so a screen that edits rows changes nothing a visitor can see: exactly
     the "claims a result it cannot produce" trap the owner has been closing screen by screen.

     Their sidebar is hidden, so the URL hash and their internal links are the only ways in; both are
     stopped here, before their router sees them, and the person is told where the work actually lives. */
  var ALLOWED = ["blog", "portfolio", "forms", "estimator"];
  /* The screen the four above already are, per page — so a refused hash goes back to what the person was doing,
     not to Blog. Kept here as well as in the Blade so the list has one home the tests can read. */
  var ALIAS = { post: "blog", study: "portfolio" };
  var LABELS = {
    dashboard: "Dashboard", services: "Service pages", cities: "City pages", pagetpl: "Page templates",
    faqs: "FAQs", testimonials: "Testimonials", team: "Team", logos: "Client logos",
    global: "Header & footer", library: "Section library", heroes: "Hero slides", pages: "All pages",
    builder: "Page builder", redirects: "Redirects", media: "Media", seo: "SEO", speed: "Speed",
    health: "Site health", theme: "Theme", profile: "My profile", settings: "Integrations"
  };

  function screenOf(hash) {
    var first = String(hash || "").replace(/^#\/?/, "").split("/")[0];
    return ALIAS[first] || first;
  }

  function announce(blocked) {
    var label = LABELS[blocked] || blocked;
    var bar = document.getElementById("wx21-blocked");

    if (!bar) {
      bar = document.createElement("div");
      bar.id = "wx21-blocked";
      bar.className = "wx21-blocked";
      bar.setAttribute("role", "status");
      var strip = document.querySelector(".wx21-strip");
      if (strip && strip.parentNode) strip.parentNode.insertBefore(bar, strip.nextSibling);
      else document.body.insertBefore(bar, document.body.firstChild);
    }

    bar.textContent = "“" + label + "” is the old admin’s screen: its edits would not reach the website, which is "
      + "its page files. What it did lives in All pages and Page builder in Woodex OS.";
    bar.hidden = false;
    document.body.classList.add("wx21-has-note");

    // The message takes its own line: the screen is pushed down by exactly its height, so nothing is covered.
    document.body.style.setProperty("--note", bar.getBoundingClientRect().height + "px");
    clearTimeout(announce.timer);
    announce.timer = setTimeout(function () {
      bar.hidden = true;
      document.body.classList.remove("wx21-has-note");
      document.body.style.removeProperty("--note");
    }, 9000);
  }

  function fallback() {
    var path = location.pathname.replace(/\/+$/, "");
    var here = path.split("/").pop();

    return ALLOWED.indexOf(here) > -1 ? here : "blog";
  }

  function keepInOs(name) {
    try { history.replaceState(null, "", location.pathname + "#/" + name); } catch (e) { /* nothing to do */ }
  }

  /* Their router listens for hashchange inside admin.js. Wrap the registration (this file loads first) so a
     hash pointing at a screen the OS does not have is put back before their router can render it. */
  var realAddEventListener = window.addEventListener.bind(window);

  window.addEventListener = function (type, listener, options) {
    if (type === "hashchange" && typeof listener === "function" && !listener.__wxGuarded) {
      var guarded = function (event) {
        var wanted = screenOf(location.hash);

        if (wanted !== "" && ALLOWED.indexOf(wanted) === -1) {
          keepInOs(fallback());
          announce(wanted);
          return;
        }

        return listener.call(this, event);
      };

      guarded.__wxGuarded = true;

      return realAddEventListener(type, guarded, options);
    }

    return realAddEventListener(type, listener, options);
  };

  /* And the first load: a bookmark that names another screen boots on this screen instead. */
  try {
    if (!/^#\//.test(location.hash)) {
      history.replaceState(null, "", location.pathname + "#/" + screen);
    } else if (ALLOWED.indexOf(screenOf(location.hash)) === -1) {
      var refused = screenOf(location.hash);
      history.replaceState(null, "", location.pathname + "#/" + fallback());
      document.addEventListener("DOMContentLoaded", function () { announce(refused); });
    }
  } catch (e) { /* file:// or a blocked history API: the default screen still renders */ }

  /* Their shell restores a token from sessionStorage and boots with it. There is no token any more — the
     cookie decides — so we hand it a marker and let the server answer `status` and `me` from the session. */
  try { sessionStorage.setItem("wxaTok", "os-session"); } catch (e) {}

  var API = C.api || "/admin/website/studio/api";
  var BAPI = C.builderApi || "/admin/website/studio/builder-api";

  var realFetch = window.fetch.bind(window);

  window.fetch = function (input, init) {
    if (typeof input === "string") {
      if (input === "/api/admin.php") input = API;
      else if (input === "/api/builder.php") input = BAPI;
    }

    init = init || {};
    var headers = {};
    var source = init.headers || {};

    if (typeof source.forEach === "function" && typeof source.get === "function") {
      source.forEach(function (value, key) { headers[key] = value; });
    } else {
      Object.keys(source).forEach(function (key) { headers[key] = source[key]; });
    }

    // The builder endpoint and the admin endpoint share one server route here, so the action is tagged:
    // `load` on /api/builder.php becomes `bapi_load`, while `load` on /api/admin.php stays `load`.
    var wasBuilder = false;
    if (typeof init.body === "string") {
      try {
        var body = JSON.parse(init.body);
        wasBuilder = (input === BAPI) || !!body.__builder || typeof body.html === "string";
        if (body && typeof body.action === "string" && (input === BAPI)) {
          body.action = "bapi_" + body.action;
          init.body = JSON.stringify(body);
        }
      } catch (e) { /* not JSON: leave it alone, the server will say so */ }
    }

    // Laravel's CSRF check, on the session's own token — not theirs.
    headers["X-CSRF-TOKEN"] = C.csrf || "";
    headers["X-Requested-With"] = "XMLHttpRequest";
    init.headers = headers;
    init.credentials = init.credentials || "same-origin";

    return realFetch(input, init);
  };

  /* The OS strip is the only navigation these screens carry; keep the hash when leaving so a reload of the
     OS page does not try to open a screen that no longer exists. */
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !document.querySelector("#modal:not([hidden])")) {
      var exit = document.querySelector(".wx21-exit");
      if (exit) exit.focus();
    }
  });
})();
