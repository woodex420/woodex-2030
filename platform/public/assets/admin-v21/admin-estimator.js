/* Woodex OS — the Estimator screen, ported from the v2.1 admin (docs/audit/16.14).
 *
 * `/estimator/` is the v1 page and it reads `assets/js/estimator-rates.js`, not the database. So this screen
 * edits the rate book the page actually loads, and saving also mirrors the rates into `estimator_rates` —
 * the table the bot and the staff estimate builder price from. One save, one set of prices.
 *
 * The 22 catalogue add-ons live in the same file and the OS has no concept of them (its `catalog_items` is the
 * quotation catalogue, a different list at a different granularity), so they are written back exactly as they
 * are and the screen says so instead of offering an editor for them.
 */
(function () {
  "use strict";

  var W = window.WXA, api = W.api, esc = W.esc, ic = W.ic, $ = W.$, $$ = W.$$, head = W.head, toast = W.toast;

  var FIN = ["essential", "standard", "premium"];
  var FIN_LABEL = { essential: "Essential", standard: "Standard", premium: "Premium" };

  // The ready-made services v2.1 shipped with. Adding one is a starting point, not a published price.
  var LIB = [
    ["Kitchen", "Cabinets, counter, hardware and fitting", "sqft", "Kitchen area (sq ft)", 3500, 5500, 9000],
    ["Wardrobes & storage", "Built-in wardrobes, per sq ft of front", "sqft", "Wardrobe front (sq ft)", 2200, 3500, 5500],
    ["Bedroom interior", "Bed wall, wardrobe, ceiling and lighting", "sqft", "Room area (sq ft)", 1800, 3000, 5000],
    ["Living & lounge", "TV wall, ceiling, lighting, loose furniture", "sqft", "Area (sq ft)", 2000, 3200, 5200],
    ["Complete home interior", "All rooms, turnkey", "sqft", "Covered area (sq ft)", 2400, 3800, 6200],
    ["Restaurant / café fit-out", "Kitchen-ready shell to opening day", "sqft", "Area (sq ft)", 3500, 5500, 8500],
    ["Salon / clinic fit-out", "Stations, plumbing, lighting, branding", "sqft", "Area (sq ft)", 3000, 4800, 7500],
    ["Retail shop fit-out", "Display, lighting, signage, flooring", "sqft", "Area (sq ft)", 2600, 4200, 6800],
    ["Front elevation", "Facade design and execution", "sqft", "Facade area (sq ft)", 900, 1600, 2800],
    ["Grey structure", "Construction up to grey structure", "sqft", "Covered area (sq ft)", 2600, 3000, 3600],
    ["House construction (complete)", "Grey structure + finishing", "sqft", "Covered area (sq ft)", 5200, 6800, 9500],
    ["False ceiling", "Gypsum / board ceiling with cove", "sqft", "Ceiling area (sq ft)", 180, 280, 450],
    ["Flooring", "Tiles, wood or marble, laid", "sqft", "Floor area (sq ft)", 350, 700, 1400],
    ["3D renders", "Photo-real 3D views", "views", "Number of views", 8000, 15000, 25000]
  ];

  var B = null;        // the book as edited
  var T = [];          // saved rate books
  var PAGE = "/estimator/";
  var pv = { s: 0, f: "standard", q: 1000 };

  function money(n) { return "PKR " + (Math.round(n) || 0).toLocaleString("en-PK"); }
  function findFinish(key) {
    var f = (B.finishes || []).filter(function (x) { return x.key === key; })[0];
    return f || { key: key, label: FIN_LABEL[key], hint: "" };
  }
  function climbing(s) { return s.rates.essential <= s.rates.standard && s.rates.standard <= s.rates.premium; }

  W.VIEWS.estimator = function (el) {
    el.innerHTML = head("Estimator", "Content",
        '<a class="btn" href="' + PAGE + '" target="_blank" rel="noopener">' + ic("external-link") + 'Open estimator page</a>' +
        '<button class="btn pri" id="es-save">' + ic("save") + 'Save &amp; publish</button>') +
      '<div class="banner">' + ic("info") + '<span>Saving writes the rate book the public estimator reads, and the same rates for the ' +
        'bot and staff estimates. Visitors see “starting range — final quote after a site visit”.</span></div>' +
      '<div id="es-body"><div class="empty">Loading the rate book…</div></div>';

    W.fillIcons(el);

    api("cms_estimator").then(function (r) {
      if (!r.ok) {
        el.querySelector("#es-body").innerHTML = '<div class="card"><div class="card-b"><b style="color:#d92d20">Could not read the rate book:</b> ' + esc(r.error || "no answer") + '</div></div>';
        return;
      }
      B = r.book;
      T = r.templates || [];
      if (r.page) PAGE = r.page;
      draw(el);
    });

    $("#es-save").onclick = function () {
      var b = this;
      if (!B) return;
      b.disabled = true;
      api("cms_estimator_save", { book: { services: B.services, finishes: B.finishes, terms: B.terms } }).then(function (r) {
        b.disabled = false;
        if (!r.ok) return toast(r.error || "The rate book was not saved", true);
        B = r.book; T = r.templates || [];
        draw(el);
        toast(r.saved || "Saved");
      });
    };

    el.addEventListener("input", function (e) {
      var t = e.target;
      if (!B) return;
      var row = t.closest("[data-i]");
      if (row && t.dataset.k) {
        var s = B.services[+row.dataset.i];
        if (s) s[t.dataset.k] = t.value;
      }
      if (row && t.dataset.r) {
        var s2 = B.services[+row.dataset.i];
        if (s2) s2.rates[t.dataset.r] = Math.max(0, parseInt(t.value, 10) || 0);
      }
      if (t.dataset.fk) {
        var f = B.finishes.filter(function (x) { return x.key === t.dataset.fk; })[0];
        if (f) f[t.dataset.ff] = t.value;
      }
      if (t.id === "es-terms") B.terms = t.value.split("\n").map(function (x) { return x.trim(); }).filter(Boolean);
      if (t.id === "pv-q") pv.q = Math.max(0, parseInt(t.value, 10) || 0);
      if (t.dataset.fk) drawFinishes(el);
      preview(el);
    });

    el.addEventListener("change", function (e) {
      var t = e.target;
      if (!B) return;
      if (t.id === "pv-s") pv.s = +t.value;
      if (t.id === "pv-f") pv.f = t.value;
      preview(el);
    });

    el.addEventListener("click", function (e) {
      var b = e.target.closest("button");
      if (!b || !B) return;
      var i = b.dataset.i === undefined ? -1 : +b.dataset.i;

      if (b.dataset.lib !== undefined) {
        var t = LIB[+b.dataset.lib];
        B.services.push({ key: "", label: t[0], hint: t[1], unit: t[2], unitLabel: t[3], rates: { essential: t[4], standard: t[5], premium: t[6] } });
        pv.s = B.services.length - 1;
        draw(el);
        toast(t[0] + " added — check the rates, then Save & publish");
        return;
      }

      if (b.id === "es-add") {
        B.services.push({ key: "", label: "New service", hint: "", unit: "sqft", unitLabel: "Area (sq ft)", rates: { essential: 1000, standard: 1500, premium: 2500 } });
        pv.s = B.services.length - 1;
        draw(el);
        var inputs = $$("[data-i]", el);
        if (inputs.length) inputs[inputs.length - 1].scrollIntoView({ block: "center" });
        return;
      }

      if (b.dataset.rm !== undefined) {
        if (B.services.length < 2) return toast("Keep at least one service", true);
        B.services.splice(i, 1);
        pv.s = 0;
        draw(el);
        return;
      }

      if (b.dataset.mv !== undefined) {
        var to = i + +b.dataset.mv;
        if (to < 0 || to >= B.services.length) return;
        B.services.splice(to, 0, B.services.splice(i, 1)[0]);
        draw(el);
        return;
      }

      if (b.id === "es-padj") {
        var pct = parseFloat(($("#es-pct") || {}).value) || 0;
        if (!pct) return toast("Type a percentage first", true);
        B.services.forEach(function (s) {
          FIN.forEach(function (k) {
            s.rates[k] = Math.max(10, Math.round(s.rates[k] * (1 + pct / 100) / 10) * 10);
          });
        });
        draw(el);
        toast("Rates " + (pct > 0 ? "+" : "") + pct + "%. Press Save & publish to make it live");
        return;
      }

      if (b.id === "es-tpl-save") {
        var name = ($("#es-tpl-name") || {}).value || "";
        name = name.trim();
        if (!name) return toast("Type a rate-book name first", true);
        api("cms_estimator_tpl", { op: "save", name: name }).then(function (r) {
          if (!r.ok) return toast(r.error || "Not saved", true);
          T = r.templates || [];
          draw(el);
          toast(r.note || "Saved");
        });
        return;
      }

      if (b.dataset.tpl) {
        api("cms_estimator_tpl", { op: "apply", name: b.dataset.tpl }).then(function (r) {
          if (!r.ok) return toast(r.error || "Not loaded", true);
          B = r.book;
          draw(el);
          toast(r.note || "Loaded");
        });
        return;
      }

      if (b.dataset.tplDel) {
        if (!confirm("Delete the saved rate book “" + b.dataset.tplDel + "”?")) return;
        api("cms_estimator_tpl", { op: "delete", name: b.dataset.tplDel }).then(function (r) {
          if (!r.ok) return toast(r.error || "Not deleted", true);
          T = r.templates || [];
          draw(el);
          toast(r.note || "Deleted");
        });
      }
    });
  };

  function draw(el) {
    var have = B.services.map(function (s) { return s.label.toLowerCase(); });
    var body = el.querySelector("#es-body");
    if (!body) return;

    body.innerHTML =
      '<div class="card" style="margin-bottom:16px"><div class="card-h"><h3>' + ic("layers") + ' Saved rate books</h3>' +
        '<small class="muted">a snapshot of the prices, kept in the OS settings</small></div><div class="card-b">' +
        '<div class="g2" style="align-items:start">' +
          '<div><b>Add a ready-made service</b><div class="fm-chips" style="margin-top:8px">' +
            LIB.map(function (t, i) {
              var has = have.indexOf(t[0].toLowerCase()) !== -1;
              return '<button class="btn sm' + (has ? " ghost" : "") + '" data-lib="' + i + '"' + (has ? " disabled title=\"Already in the list\"" : "") + '>' + (has ? "✓ " : "+ ") + esc(t[0]) + '</button>';
            }).join("") + '</div></div>' +
          '<div><b>Saved books</b><div class="fm-chips" style="margin-top:8px">' +
            (T.length ? T.map(function (t) {
              return '<span><button class="btn sm" data-tpl="' + esc(t.name) + '" title="Saved ' + esc(String(t.at).slice(0, 16)) + '">' + esc(t.name) + " · " + t.services + '</button>' +
                '<button class="btn sm ghost" data-tpl-del="' + esc(t.name) + '" title="Delete">✕</button></span>';
            }).join("") : '<span class="muted">None yet. Save the current rates before a big change.</span>') +
            '</div><div class="g2" style="margin-top:10px"><input id="es-tpl-name" placeholder="Name, e.g. 2026 standard"><button class="btn sm" id="es-tpl-save">' + ic("save") + 'Save current as a rate book</button></div></div>' +
        '</div>' +
        '<div class="es-pct"><label><small class="muted">Adjust every rate by %</small>' +
          '<span class="es-inl"><input id="es-pct" type="number" step="1" value="8"><button class="btn sm" id="es-padj">Apply to all</button></span></label>' +
          '<p class="muted">8 = yearly increase, −5 = a promotion. Rounded to the nearest 10.</p></div>' +
      '</div></div>' +

      '<div class="card" style="margin-bottom:16px"><div class="card-h"><h3>Services &amp; rates (PKR)</h3>' +
        '<button class="btn sm" id="es-add">' + ic("plus") + 'Service</button></div><div class="card-b">' +
        B.services.map(function (s, i) {
          var bad = !climbing(s);
          return '<div class="es-svc" data-i="' + i + '"' + (bad ? ' style="border-color:#f0b4ae"' : '') + '>' +
            '<div class="es-row">' +
              '<label><small>Service name</small><input data-k="label" value="' + esc(s.label) + '"></label>' +
              '<label><small>Short description</small><input data-k="hint" value="' + esc(s.hint || "") + '"></label>' +
              '<label><small>Priced per</small><select data-k="unit">' +
                '<option value="sqft"' + (s.unit !== "views" ? " selected" : "") + '>sq ft</option>' +
                '<option value="views"' + (s.unit === "views" ? " selected" : "") + '>view (3D)</option></select></label>' +
              '<label><small>Quantity label</small><input data-k="unitLabel" value="' + esc(s.unitLabel || "") + '"></label>' +
            '</div>' +
            '<div class="es-row">' +
              FIN.map(function (k) {
                return '<label><small>' + esc(findFinish(k).label) + ' rate</small><input type="number" min="0" step="10" data-r="' + k + '" value="' + (s.rates[k] || 0) + '"></label>';
              }).join("") +
              '<span style="display:flex;gap:4px;justify-content:flex-end">' +
                '<button class="btn sm" data-i="' + i + '" data-mv="-1" title="Move up">' + ic("arrow-up") + '</button>' +
                '<button class="btn sm" data-i="' + i + '" data-mv="1" title="Move down">' + ic("arrow-down") + '</button>' +
                '<button class="btn sm danger" data-i="' + i + '" data-rm="1" title="Remove">' + ic("trash") + '</button>' +
              '</span>' +
            '</div>' +
            (bad ? '<p class="err" style="margin:8px 0 0">Rates have to climb: Essential ≤ Standard ≤ Premium.</p>' : "") +
          '</div>';
        }).join("") + '</div></div>' +

      '<div class="card" style="margin-bottom:16px"><div class="card-h"><h3>Finish levels</h3></div><div class="card-b">' +
        '<table class="tbl"><thead><tr><th>Level</th><th>Name shown</th><th>Description</th></tr></thead><tbody>' +
        FIN.map(function (k) {
          var f = findFinish(k);
          return '<tr><td><b>' + k + '</b></td>' +
            '<td><input data-fk="' + k + '" data-ff="label" value="' + esc(f.label) + '"></td>' +
            '<td><input data-fk="' + k + '" data-ff="hint" value="' + esc(f.hint || "") + '"></td></tr>';
        }).join("") + '</tbody></table></div></div>' +

      '<div class="card" style="margin-bottom:16px"><div class="card-h"><h3>Quotation terms</h3>' +
        '<small class="muted">used by the quotation builder · one per line</small></div><div class="card-b">' +
        '<textarea id="es-terms" rows="4">' + esc((B.terms || []).join("\n")) + '</textarea>' +
        '<p class="muted" style="margin:8px 0 0;font-size:12.5px">' + B.catalog_count + ' line items in the quotation catalogue are kept as they are.</p></div></div>' +

      '<div class="card"><div class="card-h"><h3>Formula &amp; live preview</h3></div><div class="card-b">' +
        '<div class="muted">From = <b>Essential</b> × quantity · To = <b>Premium</b> × quantity · Selection = <b>chosen level</b> × quantity</div>' +
        '<div class="g2" style="margin-top:12px">' +
          '<label>Service<select id="pv-s">' + B.services.map(function (s, i) {
            return '<option value="' + i + '"' + (i === pv.s ? " selected" : "") + '>' + esc(s.label) + '</option>';
          }).join("") + '</select></label>' +
          '<label>Quantity<input id="pv-q" type="number" min="1" value="' + pv.q + '"></label>' +
          '<label>Finish<select id="pv-f">' + FIN.map(function (k) {
            return '<option value="' + k + '"' + (k === pv.f ? " selected" : "") + '>' + esc(findFinish(k).label) + '</option>';
          }).join("") + '</select></label>' +
        '</div>' +
        '<div id="pv-out" style="margin-top:14px"></div>' +
      '</div></div>';

    W.fillIcons(body);
    preview(el);
  }

  function drawFinishes(el) {
    // The finish names appear as rate labels and in the preview; redrawing the whole screen on every keystroke
    // would take the caret with it, so only the labels that are not being typed in are refreshed.
    FIN.forEach(function (k) {
      $$("[data-r]").forEach(function (input) {
        if (input.dataset.r === k) {
          var label = input.closest("label");
          var small = label ? label.querySelector("small") : null;
          if (small) small.textContent = findFinish(k).label + " rate";
        }
      });
    });
  }

  function preview(el) {
    var out = el.querySelector("#pv-out");
    if (!out || !B.services.length) return;
    var s = B.services[Math.min(pv.s, B.services.length - 1)];
    var q = +pv.q || 0;
    var chosen = s.rates[pv.f] || 0;
    out.innerHTML = '<div class="g2">' +
        '<div><small class="muted">From</small><div><b>' + money(s.rates.essential * q) + '</b></div></div>' +
        '<div><small class="muted">To</small><div><b>' + money(s.rates.premium * q) + '</b></div></div>' +
      '</div>' +
      '<div style="margin-top:10px"><small class="muted">Selection · ' + esc(s.label) + " · " + q.toLocaleString("en-PK") +
      (s.unit === "views" ? " views" : " sq ft") + '</small><div style="font-size:18px"><b>' + money(chosen * q) + '</b></div></div>';
  }
})();
