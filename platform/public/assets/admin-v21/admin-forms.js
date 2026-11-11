/* Woodex OS — the Forms screen, ported from the v2.1 admin (docs/audit/16.8).
 *
 * v2.1 owned the enquiry forms: it set a per-form alert email and WhatsApp number, an auto-reply, and showed
 * what each form had received. Submissions here land in Enquiries (the OS `leads` table) from the same v1
 * pages, so the counts, the last submission and the field names come from real rows.
 *
 * There is deliberately no per-form alert editor: the OS routes every new enquiry through one rule (the
 * owner and the sales managers, in-app + Telegram, email for staff without Telegram), set in Settings and
 * Automation. Writing a second, per-form routing store would need a table the OS does not have — that is a
 * decision for the owner, not something this screen should quietly grow (docs/audit/16.13).
 */
(function () {
  "use strict";

  var W = window.WXA, api = W.api, esc = W.esc, ic = W.ic, $ = W.$, head = W.head;

  var LEADS = "/admin/leads";
  var SETTINGS = "/admin/settings";
  var AUTOMATIONS = "/admin/automations";

  function when(value) {
    if (!value) return "—";
    var d = new Date(String(value).replace(" ", "T") + "Z");
    if (isNaN(d.getTime())) return String(value);
    var days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if (days <= 0) return "today";
    if (days === 1) return "yesterday";
    if (days < 31) return days + " days ago";
    return d.toLocaleDateString();
  }

  W.VIEWS.forms = function (el) {
    el.innerHTML = head("Forms", "Content",
        '<a class="btn" href="' + LEADS + '">' + ic("inbox") + 'View enquiries</a>') +
      '<div class="banner">' + ic("info") + '<span>Every enquiry from these forms lands in <a href="' + LEADS + '">Enquiries</a>. ' +
        'Alerts are routed once for all of them — set the roles in <a href="' + SETTINGS + '">Settings</a> and the switch in ' +
        '<a href="' + AUTOMATIONS + '">Automations</a>.</span></div>' +
      '<div class="card" id="fm-alerts"><div class="card-h"><h3>' + ic("bell") + ' Where a new enquiry goes</h3></div>' +
        '<div class="card-b muted">Reading the alert routing…</div></div>' +
      '<div class="card"><div class="card-h"><h3>Forms on the website</h3></div>' +
        '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
        '<th>Form</th><th>In 30 days</th><th>Total</th><th>Last enquiry</th><th>Fields received</th><th></th>' +
        '</tr></thead><tbody id="fm-rows"><tr><td colspan="6" class="muted">Reading the forms…</td></tr></tbody></table></div></div>';

    W.fillIcons(el);

    api("cms_forms").then(function (r) {
      if (!r.ok) {
        $("#fm-rows").innerHTML = '<tr><td colspan="6"><b style="color:#d92d20">Could not read the forms:</b> ' +
          esc(r.error || "no answer") + '</td></tr>';
        return;
      }

      $("#fm-rows").innerHTML = (r.forms || []).map(function (f) {
        var fields = f.fields.length
          ? f.fields.map(function (x) { return "<span>" + esc(x) + "</span>"; }).join("")
          : '<span class="muted">nothing received yet</span>';

        return '<tr data-k="' + esc(f.key) + '">' +
          '<td><b>' + esc(f.label) + '</b><div class="muted" style="font-size:12px">' + esc(f.key) + '</div></td>' +
          '<td>' + f.month + '</td>' +
          '<td>' + f.total + '</td>' +
          '<td class="muted">' + esc(when(f.last)) + '</td>' +
          '<td><div class="fm-chips">' + fields + '</div></td>' +
          '<td class="r"><a class="btn sm" href="' + LEADS + '">Enquiries</a></td></tr>';
      }).join("") || '<tr><td colspan="6" class="muted">No form has been used yet.</td></tr>';

      var a = r.alerts || {};
      var rows = [];

      rows.push('<div><b>Alert switch</b>: ' + (a.automation === null
        ? '<span class="muted">not configured</span>'
        : (a.automation ? '<span class="badge ok">on</span>' : '<span class="badge warn">off</span>')) +
        ' <span class="muted">— <a href="' + AUTOMATIONS + '">Automations · New lead alert</a></span></div>');

      rows.push('<div style="margin-top:8px"><b>Who hears about it</b>: ' + ((a.roles || []).length
        ? a.roles.map(function (role) { return "<span class=\"badge\">" + esc(role) + "</span>"; }).join(" ")
        : '<span class="muted">no roles set</span>') +
        ' <span class="muted">— in the app and on Telegram; staff without Telegram get an email. Change in <a href="' + SETTINGS + '">Settings · Notifications</a>.</span></div>');

      if (a.quiet) {
        rows.push('<div style="margin-top:8px"><b>Quiet hours</b>: ' + esc(a.quiet[0]) + ' – ' + esc(a.quiet[1]) + '</div>');
      }

      // Query once: stripping the class off the box makes a second lookup return null.
      var box = $("#fm-alerts .card-b");
      if (box) box.innerHTML = rows.join("");
    }).catch(function (e) {
      $("#fm-rows").innerHTML = '<tr><td colspan="6"><b style="color:#d92d20">Could not read the forms:</b> ' + esc(e.message) + '</td></tr>';
    });
  };
})();
