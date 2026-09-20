/* HouseDye work-order Gantt: order bands, group move, collapse, match lines, A3 print. */
(function () {
  "use strict";
  const data = window.__GANTT__;
  if (!data || !document.getElementById("ganttWrap")) return;

  const DAY_MS = 86400000;
  const LABEL_W = 240;
  const ROW_H = 36;
  const HEAD_H = 44;
  const DAY_W = 34;
  const PAD = 8;
  const LABEL_MAX = 30;
  const ORDER_HUES = [12, 210, 145, 280, 32, 190, 350, 80, 240, 20, 170, 300];

  function parseDay(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ""));
    if (!m) return null;
    return new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
  }
  function fmt(d) {
    const y = d.getUTCFullYear();
    const mo = String(d.getUTCMonth() + 1).padStart(2, "0");
    const da = String(d.getUTCDate()).padStart(2, "0");
    return y + "-" + mo + "-" + da;
  }
  function addDays(d, n) {
    const x = new Date(d.getTime());
    x.setUTCDate(x.getUTCDate() + n);
    return x;
  }
  function daysBetween(a, b) {
    return Math.round((b.getTime() - a.getTime()) / DAY_MS);
  }
  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }
  function cropLabel(s, full) {
    const t = String(s || "");
    if (full || t.length <= LABEL_MAX) return t;
    return t.slice(0, LABEL_MAX) + "…";
  }
  function productKey(row) {
    return row.product_id ? "p:" + row.product_id : "t:" + (row.title || "");
  }
  function orderIds() {
    return (data.orders || []).map((o) => Number(o.order_id));
  }
  function orderColor(orderId) {
    const ids = orderIds().slice().sort((a, b) => a - b);
    const i = Math.max(0, ids.indexOf(Number(orderId)));
    const hue = ORDER_HUES[i % ORDER_HUES.length];
    return { fill: "hsl(" + hue + " 62% 56%)", stroke: "hsl(" + hue + " 55% 34%)" };
  }
  function clientName(orderId) {
    const o = (data.orders || []).find((x) => Number(x.order_id) === Number(orderId));
    return ((o && (o.client_name || o.client_email)) || "Client").trim();
  }

  const parents = [];
  (data.rows || []).forEach((r) => {
    const parent = Object.assign({ kind: "parent" }, r);
    parent.trays = (r.trays || []).map((t) =>
      Object.assign({ kind: "tray", parent_id: r.id, parent_title: r.title }, t)
    );
    parents.push(parent);
  });

  function itemsForOrder(orderId) {
    const oid = Number(orderId);
    const out = [];
    parents.forEach((p) => {
      if (Number(p.order_id) !== oid) return;
      out.push(p);
      (p.trays || []).forEach((t) => out.push(t));
    });
    return out;
  }
  function persistable(row) {
    return row && row.kind !== "order" && Number(row.id) > 0;
  }
  function isDoneStatus(status) {
    return status === "complete" || status === "filled_from_stock";
  }
  function workUnits() {
    const out = [];
    parents.forEach((p) => {
      const trays = p.trays || [];
      const units = trays.length ? trays : [p];
      units.forEach((u) => {
        if (isDoneStatus(u.status)) return;
        out.push(u);
      });
    });
    return out;
  }
  function staffNameFor(sid) {
    const u = workUnits().find((x) => Number(x.staff_user_id) === Number(sid));
    const name = u && String(u.staff_name || "").trim();
    return name || (u && u.staff_email) || "";
  }
  function conflictInfo() {
    const rates = data.staffRates || {};
    const load = {};
    workUnits().forEach((u) => {
      const sid = Number(u.staff_user_id || 0);
      if (!sid) return;
      const dates = barDates(u);
      let d = dates.start;
      while (d <= dates.end) {
        const k = sid + "|" + fmt(d);
        if (!load[k]) load[k] = { sid: sid, day: fmt(d), ids: [] };
        load[k].ids.push(Number(u.id));
        d = addDays(d, 1);
      }
    });
    const ids = new Set();
    const msgs = [];
    Object.keys(load).forEach((k) => {
      const row = load[k];
      const rate = Math.max(1, Number(rates[row.sid] || 10));
      if (row.ids.length > rate) {
        row.ids.forEach((id) => ids.add(id));
        msgs.push({
          sid: row.sid,
          staff_name: staffNameFor(row.sid),
          day: row.day,
          count: row.ids.length,
          rate: rate,
          ids: row.ids
        });
      }
    });
    return { ids: ids, msgs: msgs };
  }
  let conflictCache = { ids: new Set(), msgs: [] };
  function isConflictRow(row) {
    if (!row || row.kind === "order") return false;
    if (conflictCache.ids.has(Number(row.id))) return true;
    if (row.kind === "parent" && (row.trays || []).some((t) => conflictCache.ids.has(Number(t.id)))) return true;
    return false;
  }
  function conflictKey(info) {
    return (info.msgs || []).map((m) => m.sid + "|" + m.day + "|" + (m.ids || []).slice().sort().join(",")).sort().join(";");
  }
  function offerResolveConflicts() {
    const info = conflictInfo();
    conflictCache = info;
    if (!info.msgs.length) {
      lastOfferedKey = "";
      hideConflictModal();
      return;
    }
    if (overrideRate || !data.isAdmin) return;
    const key = conflictKey(info);
    if (key && key === lastOfferedKey) return;
    const first = info.msgs[0];
    const who = first.staff_name || staffNameFor(first.sid) || "A staff member";
    const text = who +
      " is over their tray rate (" + first.count + " trays on " + first.day +
      ", limit " + first.rate + "). Conflicting work stays highlighted until the schedule is under the limit.";
    showConflictModal(text);
    lastOfferedKey = key;
  }
  function barDates(row) {
    if (row._start && row._end) return { start: row._start, end: row._end };
    if (row.kind === "order") return spanForOrder(row.order_id);
    const start = parseDay(row.starts_at) || from;
    let end = parseDay(row.ends_at) || addDays(start, 1);
    if (end < start) end = start;
    return { start, end };
  }
  function spanForOrder(orderId) {
    const kids = itemsForOrder(orderId);
    let start = null;
    let end = null;
    kids.forEach((k) => {
      const d = barDates(k);
      if (!start || d.start < start) start = d.start;
      if (!end || d.end > end) end = d.end;
    });
    if (!start) start = from;
    if (!end) end = start;
    return { start, end };
  }

  const saved = data.view || {};
  let from = parseDay(data.from) || parseDay(saved.from) || new Date();
  let to = parseDay(data.to) || parseDay(saved.to) || addDays(from, 21);
  const allOrderIds = orderIds();
  let selectedOrders = new Set(allOrderIds);
  if (Array.isArray(saved.visible_orders)) {
    selectedOrders = new Set(saved.visible_orders.map(Number));
    const known = new Set((saved.known_orders || saved.visible_orders).map(Number));
    allOrderIds.forEach((id) => {
      if (!known.has(id)) selectedOrders.add(id);
    });
  }
  const collapsedOrders = new Set((saved.collapsed_orders || []).map(Number));
  const collapsedParents = new Set((saved.collapsed_parents || []).map(Number));
  const selectedMove = new Set();
  let matchLinesOn = !!saved.match_lines;
  let overrideRate = !!saved.override_rate;
  let printMode = false;
  let highlightKey = null;
  let lastOfferedKey = "";
  let undoSnapshot = null;

  const wrap = document.getElementById("ganttWrap");
  const fromEl = document.getElementById("ganttFrom");
  const toEl = document.getElementById("ganttTo");
  const ordersEl = document.getElementById("ganttOrders");
  let matchLayer = null;
  let displayList = [];

  function visibleBars() {
    return displayRows();
  }

  function displayRows() {
    const out = [];
    (data.orders || []).forEach((o) => {
      const oid = Number(o.order_id);
      if (!selectedOrders.has(oid)) return;
      const span = spanForOrder(oid);
      out.push({
        kind: "order",
        id: "o:" + oid,
        order_id: oid,
        title: clientName(oid),
        starts_at: fmt(span.start),
        ends_at: fmt(span.end),
      });
      if (collapsedOrders.has(oid)) return;
      parents.forEach((p) => {
        if (Number(p.order_id) !== oid) return;
        out.push(p);
        if (collapsedParents.has(Number(p.id))) return;
        (p.trays || []).forEach((t) => out.push(t));
      });
    });
    return out;
  }

  function persistView() {
    const payload = {
      visible_orders: Array.from(selectedOrders),
      known_orders: allOrderIds,
      collapsed_orders: Array.from(collapsedOrders),
      collapsed_parents: Array.from(collapsedParents),
      match_lines: matchLinesOn,
      override_rate: overrideRate,
      from: fmt(from),
      to: fmt(to),
    };
    if (!data.prefKey || !window.hd) return;
    hd.post("admin/api", { entity: "prefs", op: "set", key: data.prefKey, value: payload }).catch(function () {});
  }

  function ensureRange(start, end) {
    if (start < from) from = start;
    if (end > to) to = end;
    if (fromEl) fromEl.value = fmt(from);
    if (toEl) toEl.value = fmt(to);
  }

  function renderFilters() {
    if (!ordersEl) return;
    if (!(data.orders || []).length) {
      ordersEl.innerHTML = '<span class="muted">No provisioning orders to schedule.</span>';
      return;
    }
    const items = (data.orders || []).map((o) => {
      const name = (o.client_name || o.client_email || "Client").trim();
      const id = Number(o.order_id);
      const col = orderColor(id);
      const sel = selectedMove.has(id) ? " is-selected" : "";
      return '<span class="wo-filter-item">' +
        '<input type="checkbox" data-order="' + id + '"' + (selectedOrders.has(id) ? " checked" : "") +
        ' title="Show or hide this customer order">' +
        '<button type="button" class="wo-filter-name' + sel + '" data-order="' + id + '" title="Select this order and all of its work for a group move">' +
        '<i class="gantt-swatch-dot" style="background:' + col.fill + ';border-color:' + col.stroke + '"></i>' +
        "#" + id + " · " + escapeHtml(name) + "</button></span>";
    }).join("");
    ordersEl.innerHTML = '<span class="wo-filter-label">Orders</span>' + items +
      '<label class="wo-filter-item"><input type="checkbox" id="ganttMatchLines"' +
      (matchLinesOn ? " checked" : "") + "> Product Matching Lines</label>";
    ordersEl.querySelectorAll("input[data-order]").forEach((cb) => {
      cb.addEventListener("change", () => {
        const id = Number(cb.getAttribute("data-order"));
        if (cb.checked) selectedOrders.add(id);
        else selectedOrders.delete(id);
        persistView();
        paint();
      });
    });
    ordersEl.querySelectorAll(".wo-filter-name").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = Number(btn.getAttribute("data-order"));
        if (selectedMove.has(id)) selectedMove.delete(id);
        else selectedMove.add(id);
        renderFilters();
        paint();
      });
    });
    const matchCb = document.getElementById("ganttMatchLines");
    if (matchCb) {
      matchCb.addEventListener("change", () => {
        matchLinesOn = !!matchCb.checked;
        persistView();
        syncMatchLines();
        if (!matchLinesOn && matchLayer) matchLayer.innerHTML = "";
        if (matchLinesOn) paint();
      });
    }
  }

  function fullLabel(row) {
    if (row.kind === "order") return "#" + row.order_id + " " + (row.title || clientName(row.order_id));
    if (row.kind === "tray") return (row.title || "Tray") + " · " + row.qty;
    return (row.title || "") + " · #" + row.order_id;
  }
  function screenLabel(row) {
    return cropLabel(fullLabel(row), printMode);
  }

  function applyGeom(row) {
    const dates = barDates(row);
    const startOff = daysBetween(from, dates.start);
    const dur = Math.max(1, daysBetween(dates.start, dates.end) + 1);
    const x = LABEL_W + startOff * DAY_W + 2;
    const w = Math.max(10, dur * DAY_W - 4);
    const y = row._y;
    const bodyH = row.kind === "order" ? 10 : ROW_H - 16;
    const bodyY = row.kind === "order" ? y + (ROW_H - bodyH) / 2 : y + 8;
    if (row._rect) {
      row._rect.setAttribute("x", String(x));
      row._rect.setAttribute("y", String(bodyY));
      row._rect.setAttribute("width", String(w));
      row._rect.setAttribute("height", String(bodyH));
    }
    if (row._qty) row._qty.setAttribute("x", String(x + 8));
    if (row._west) {
      row._west.setAttribute("x", String(x));
      row._west.setAttribute("y", String(bodyY));
      row._west.setAttribute("height", String(bodyH));
    }
    if (row._east) {
      row._east.setAttribute("x", String(x + w - 6));
      row._east.setAttribute("y", String(bodyY));
      row._east.setAttribute("height", String(bodyH));
    }
    row._left = x;
    row._midY = bodyY + bodyH / 2;
  }

  function syncOrderBands() {
    displayList.forEach((row) => {
      if (row.kind === "order") {
        delete row._start;
        delete row._end;
        applyGeom(row);
      }
    });
  }

  function matchGroups() {
    const map = new Map();
    displayList.forEach((row) => {
      if (row.kind !== "parent") return;
      const k = productKey(row);
      if (!map.has(k)) map.set(k, []);
      map.get(k).push(row);
    });
    return Array.from(map.values()).filter((g) => {
      const oids = new Set(g.map((r) => Number(r.order_id)));
      return oids.size >= 2;
    });
  }

  function syncMatchLines() {
    if (!matchLayer) return;
    matchLayer.innerHTML = "";
    if (!matchLinesOn) return;
    const ns = "http://www.w3.org/2000/svg";
    matchGroups().forEach((group) => {
      const pts = group.slice().sort((a, b) => (a._midY || 0) - (b._midY || 0));
      if (pts.length < 2) return;
      const d = pts.map((r, i) => {
        const x = r._left != null ? r._left : LABEL_W;
        const y = r._midY != null ? r._midY : 0;
        return (i === 0 ? "M" : "L") + x + " " + y;
      }).join(" ");
      const path = document.createElementNS(ns, "path");
      path.setAttribute("d", d);
      path.setAttribute("class", "gantt-match-line");
      matchLayer.appendChild(path);
      pts.forEach((r) => {
        const dot = document.createElementNS(ns, "circle");
        dot.setAttribute("cx", String(r._left != null ? r._left : LABEL_W));
        dot.setAttribute("cy", String(r._midY != null ? r._midY : 0));
        dot.setAttribute("r", "3.2");
        dot.setAttribute("class", "gantt-match-dot");
        matchLayer.appendChild(dot);
      });
    });
  }

  function paint() {
    conflictCache = conflictInfo();
    displayList = displayRows();
    const list = displayList;
    const span = Math.max(1, daysBetween(from, to) + 1);
    const width = LABEL_W + span * DAY_W + PAD * 2;
    const height = HEAD_H + Math.max(1, list.length) * ROW_H + PAD * 2;
    const ns = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(ns, "svg");
    svg.setAttribute("class", "gantt-svg");
    svg.setAttribute("viewBox", "0 0 " + width + " " + height);
    svg.setAttribute("width", String(width));
    svg.setAttribute("height", String(height));
    svg.setAttribute("role", "img");
    svg.setAttribute("aria-label", "Work order schedule");

    const bg = document.createElementNS(ns, "rect");
    bg.setAttribute("width", String(width));
    bg.setAttribute("height", String(height));
    bg.setAttribute("fill", "#fff");
    svg.appendChild(bg);

    for (let i = 0; i < span; i++) {
      const d = addDays(from, i);
      const x = LABEL_W + i * DAY_W;
      const weekend = d.getUTCDay() === 0 || d.getUTCDay() === 6;
      if (weekend) {
        const r = document.createElementNS(ns, "rect");
        r.setAttribute("x", String(x));
        r.setAttribute("y", String(HEAD_H));
        r.setAttribute("width", String(DAY_W));
        r.setAttribute("height", String(height - HEAD_H));
        r.setAttribute("fill", "#f6f6f6");
        svg.appendChild(r);
      }
      const line = document.createElementNS(ns, "line");
      line.setAttribute("x1", String(x));
      line.setAttribute("x2", String(x));
      line.setAttribute("y1", "0");
      line.setAttribute("y2", String(height));
      line.setAttribute("stroke", "#eee");
      svg.appendChild(line);
      const lab = document.createElementNS(ns, "text");
      lab.setAttribute("x", String(x + DAY_W / 2));
      lab.setAttribute("y", "18");
      lab.setAttribute("text-anchor", "middle");
      lab.setAttribute("class", "gantt-tick");
      lab.textContent = d.getUTCDate() === 1 || i === 0
        ? (d.getUTCMonth() + 1) + "/" + d.getUTCDate()
        : String(d.getUTCDate());
      svg.appendChild(lab);
      if (d.getUTCDate() === 1 || i === 0) {
        const mo = document.createElementNS(ns, "text");
        mo.setAttribute("x", String(x + 4));
        mo.setAttribute("y", "34");
        mo.setAttribute("class", "gantt-month");
        mo.textContent = d.toLocaleString("en-US", { month: "short", timeZone: "UTC" });
        svg.appendChild(mo);
      }
    }

    if (!list.length) {
      const empty = document.createElementNS(ns, "text");
      empty.setAttribute("x", String(LABEL_W + 16));
      empty.setAttribute("y", String(HEAD_H + 28));
      empty.setAttribute("class", "gantt-empty");
      empty.textContent = "Select one or more orders to show them on the timeline.";
      svg.appendChild(empty);
    }

    matchLayer = document.createElementNS(ns, "g");
    matchLayer.setAttribute("class", "gantt-match-layer");

    list.forEach((row, idx) => {
      const y = HEAD_H + idx * ROW_H;
      row._y = y;
      const sep = document.createElementNS(ns, "line");
      sep.setAttribute("x1", "0");
      sep.setAttribute("x2", String(width));
      sep.setAttribute("y1", String(y + ROW_H));
      sep.setAttribute("y2", String(y + ROW_H));
      sep.setAttribute("stroke", "#f0f0f0");
      svg.appendChild(sep);

      const col = orderColor(row.order_id);
      if (row.kind === "order") {
        const sw = document.createElementNS(ns, "rect");
        sw.setAttribute("x", String(PAD));
        sw.setAttribute("y", String(y + 12));
        sw.setAttribute("width", "10");
        sw.setAttribute("height", "10");
        sw.setAttribute("rx", "2");
        sw.setAttribute("fill", col.fill);
        sw.setAttribute("stroke", col.stroke);
        svg.appendChild(sw);
      }

      const label = document.createElementNS(ns, "text");
      label.setAttribute("x", String(row.kind === "order" ? PAD + 16 : (row.kind === "tray" ? PAD + 14 : PAD)));
      label.setAttribute("y", String(y + 23));
      label.setAttribute("class", "gantt-label" + (row.kind === "tray" ? " tray" : row.kind === "order" ? " order" : ""));
      label.textContent = screenLabel(row);
      const tip = document.createElementNS(ns, "title");
      tip.textContent = fullLabel(row);
      label.appendChild(tip);
      label.addEventListener("click", (ev) => {
        ev.stopPropagation();
        if (row.kind === "order") {
          const id = Number(row.order_id);
          if (selectedMove.has(id)) selectedMove.delete(id);
          else selectedMove.add(id);
          renderFilters();
          paint();
        }
      });
      label.addEventListener("dblclick", (ev) => {
        ev.stopPropagation();
        toggleCollapse(row);
      });
      svg.appendChild(label);

      const g = document.createElementNS(ns, "g");
      const like = highlightKey && row.kind === "parent" && highlightKey === productKey(row);
      const dim = highlightKey && row.kind === "parent" && highlightKey !== productKey(row);
      const selected = selectedMove.has(Number(row.order_id));
      g.setAttribute("class", "gantt-bar" +
        (row.kind === "order" ? " order" : "") +
        (dim ? " is-dim" : "") +
        (like ? " is-like" : "") +
        (selected ? " is-selected" : "") +
        (isConflictRow(row) ? " is-conflict" : ""));
      g.dataset.id = String(row.id);
      g.dataset.kind = row.kind;
      g.dataset.order = String(row.order_id);
      if (row.kind === "parent") g.dataset.key = productKey(row);

      const rect = document.createElementNS(ns, "rect");
      rect.setAttribute("rx", row.kind === "order" ? "5" : "7");
      rect.setAttribute("fill", col.fill);
      rect.setAttribute("stroke", col.stroke);
      rect.setAttribute("class", "gantt-body");
      g.appendChild(rect);
      row._rect = rect;

      if (row.kind !== "order") {
        const qty = document.createElementNS(ns, "text");
        qty.setAttribute("y", String(y + 23));
        qty.setAttribute("class", "gantt-bar-text");
        qty.textContent = String(row.qty);
        g.appendChild(qty);
        row._qty = qty;
      } else {
        row._qty = null;
      }

      const left = document.createElementNS(ns, "rect");
      left.setAttribute("width", "8");
      left.setAttribute("class", "gantt-handle west");
      left.setAttribute("fill", "transparent");
      g.appendChild(left);
      row._west = left;

      const right = document.createElementNS(ns, "rect");
      right.setAttribute("width", "8");
      right.setAttribute("class", "gantt-handle east");
      right.setAttribute("fill", "transparent");
      g.appendChild(right);
      row._east = right;

      applyGeom(row);
      bindDrag(g, row);
      g.addEventListener("dblclick", (ev) => {
        ev.stopPropagation();
        toggleCollapse(row);
      });
      svg.appendChild(g);
    });

    svg.appendChild(matchLayer);
    wrap.innerHTML = "";
    wrap.appendChild(svg);
    syncMatchLines();
  }

  function toggleCollapse(row) {
    if (row.kind === "order") {
      const id = Number(row.order_id);
      if (collapsedOrders.has(id)) collapsedOrders.delete(id);
      else collapsedOrders.add(id);
      persistView();
      paint();
      return;
    }
    if (row.kind === "parent") {
      const id = Number(row.id);
      if (collapsedParents.has(id)) collapsedParents.delete(id);
      else collapsedParents.add(id);
      persistView();
      paint();
    }
  }

  function moveTargets(row, mode) {
    if (mode !== "move") {
      if (row.kind === "order") return itemsForOrder(row.order_id).concat([row]);
      return [row];
    }
    const oid = Number(row.order_id);
    const group = (row.kind === "order" || selectedMove.has(oid))
      ? (selectedMove.has(oid) && selectedMove.size ? Array.from(selectedMove) : [oid])
      : [oid];
    if (row.kind === "order" || selectedMove.has(oid)) {
      const out = [];
      group.forEach((id) => {
        out.push({ kind: "order", order_id: id, id: "o:" + id });
        itemsForOrder(id).forEach((it) => out.push(it));
      });
      return out.map((it) => {
        if (it.kind === "order") {
          return displayList.find((d) => d.kind === "order" && Number(d.order_id) === Number(it.order_id)) || it;
        }
        return it;
      });
    }
    return [row];
  }

  function latestChild(orderId) {
    let best = null;
    itemsForOrder(orderId).forEach((k) => {
      const d = barDates(k);
      if (!best || d.end > best.end || (d.end.getTime() === best.end.getTime() && d.start > best.start)) {
        best = { row: k, start: d.start, end: d.end };
      }
    });
    return best;
  }
  function earliestChild(orderId) {
    let best = null;
    itemsForOrder(orderId).forEach((k) => {
      const d = barDates(k);
      if (!best || d.start < best.start) best = { row: k, start: d.start, end: d.end };
    });
    return best;
  }

  function bindDrag(g, row) {
    g.addEventListener("pointerdown", (ev) => {
      if (ev.button !== 0) return;
      ev.preventDefault();
      if (row.kind === "parent") highlightKey = productKey(row);
      wrap.querySelectorAll(".gantt-bar").forEach((el) => {
        if (!el.dataset.key) return;
        const like = highlightKey && el.dataset.key === highlightKey;
        el.classList.toggle("is-like", !!like);
        el.classList.toggle("is-dim", !!(highlightKey && !like));
      });
      const handle = ev.target.closest(".gantt-handle");
      const mode = handle && handle.classList.contains("west") ? "west"
        : handle && handle.classList.contains("east") ? "east" : "move";
      const startX = ev.clientX;
      let targets;
      if (row.kind === "order" && mode !== "move") {
        const edge = mode === "east" ? latestChild(row.order_id) : earliestChild(row.order_id);
        targets = edge ? [edge.row, row] : [row];
      } else {
        targets = moveTargets(row, mode);
      }
      const orig = targets.map((t) => {
        const d = barDates(t);
        return { row: t, start: d.start, end: d.end };
      });
      const onMove = (e) => {
        const days = Math.round((e.clientX - startX) / DAY_W);
        orig.forEach((o) => {
          let ns = o.start;
          let ne = o.end;
          if (mode === "move") {
            ns = addDays(o.start, days);
            ne = addDays(o.end, days);
          } else if (o.row.kind === "order") {
            return;
          } else if (mode === "west") {
            ns = addDays(o.start, days);
            if (ns > ne) ns = ne;
          } else {
            ne = addDays(o.end, days);
            if (ne < ns) ne = ns;
          }
          o.row._start = ns;
          o.row._end = ne;
          applyGeom(o.row);
        });
        syncOrderBands();
        syncMatchLines();
      };
      const onUp = async () => {
        window.removeEventListener("pointermove", onMove);
        window.removeEventListener("pointerup", onUp);
        const items = [];
        let changed = false;
        orig.forEach((o) => {
          if (!o.row._start || !o.row._end) return;
          const ns = fmt(o.row._start);
          const ne = fmt(o.row._end);
          if (ns !== fmt(o.start) || ne !== fmt(o.end)) changed = true;
          if (persistable(o.row)) {
            o.row.starts_at = ns;
            o.row.ends_at = ne;
            items.push({ id: o.row.id, starts_at: ns, ends_at: ne });
          }
          delete o.row._start;
          delete o.row._end;
        });
        if (!changed) {
          paint();
          return;
        }
        items.forEach((it) => {
          const s = parseDay(it.starts_at);
          const e = parseDay(it.ends_at);
          if (s && e) ensureRange(s, e);
        });
        persistView();
        if (items.length && window.hd) {
          const r = items.length === 1
            ? await hd.post("admin/api", { entity: "work_orders", op: "update", id: items[0].id, changes: { starts_at: items[0].starts_at, ends_at: items[0].ends_at } })
            : await hd.post("admin/api", { entity: "work_orders", op: "schedule_batch", items: items });
          if (r.error) hd.toast(r.message || "Could not save schedule", "error");
          else hd.toast(items.length > 1 ? "Order schedule saved" : "Schedule saved");
        }
        paint();
        offerResolveConflicts();
      };
      window.addEventListener("pointermove", onMove);
      window.addEventListener("pointerup", onUp, { once: true });
    });
  }

  function printA3() {
    printSchedule("A3");
  }
  function printA4() {
    printSchedule("A4");
  }
  function printSchedule(size) {
    const span = Math.max(1, daysBetween(from, to) + 1);
    const daysPerPage = size === "A4" ? 18 : 28;
    const pages = Math.max(1, Math.ceil(span / daysPerPage));
    const host = document.createElement("div");
    host.className = "gantt-print-root";
    const savedHtml = wrap.innerHTML;
    printMode = true;
    for (let p = 0; p < pages; p++) {
      const pageFrom = addDays(from, p * daysPerPage);
      const pageTo = addDays(from, Math.min(span - 1, (p + 1) * daysPerPage - 1));
      const page = document.createElement("section");
      page.className = "gantt-print-page gantt-print-" + size.toLowerCase();
      const head = document.createElement("header");
      head.innerHTML = "<strong>Work order schedule</strong> · " + fmt(pageFrom) + " – " + fmt(pageTo) +
        " · " + size + " landscape · page " + (p + 1) + " of " + pages;
      page.appendChild(head);
      const hold = document.createElement("div");
      hold.className = "gantt-print-chart";
      const prevFrom = from;
      const prevTo = to;
      from = pageFrom;
      to = pageTo;
      paint();
      const svg = wrap.querySelector("svg");
      if (svg) hold.appendChild(svg.cloneNode(true));
      from = prevFrom;
      to = prevTo;
      page.appendChild(hold);
      host.appendChild(page);
    }
    printMode = false;
    paint();
    let pageStyle = document.getElementById("gantt-page-style");
    if (!pageStyle) {
      pageStyle = document.createElement("style");
      pageStyle.id = "gantt-page-style";
      document.head.appendChild(pageStyle);
    }
    pageStyle.textContent = "@page { size: " + size + " landscape; margin: 8mm; }";
    document.body.appendChild(host);
    document.body.classList.add("gantt-printing", "gantt-print-" + size.toLowerCase());
    const cleanup = () => {
      document.body.classList.remove("gantt-printing", "gantt-print-a3", "gantt-print-a4");
      host.remove();
      window.removeEventListener("afterprint", cleanup);
    };
    window.addEventListener("afterprint", cleanup);
    window.print();
    setTimeout(cleanup, 1500);
    wrap.innerHTML = savedHtml;
    paint();
  }

  if (fromEl) {
    fromEl.addEventListener("change", () => {
      const d = parseDay(fromEl.value);
      if (d) { from = d; if (to < from) to = from; if (toEl) toEl.value = fmt(to); persistView(); paint(); }
    });
  }
  if (toEl) {
    toEl.addEventListener("change", () => {
      const d = parseDay(toEl.value);
      if (d) { to = d; if (to < from) from = to; if (fromEl) fromEl.value = fmt(from); persistView(); paint(); }
    });
  }
  const printBtn = document.getElementById("ganttPrint");
  if (printBtn) printBtn.addEventListener("click", printA3);
  const printA4Btn = document.getElementById("ganttPrintA4");
  if (printA4Btn) printA4Btn.addEventListener("click", printA4);
  const overrideEl = document.getElementById("ganttOverrideRate");
  if (overrideEl) {
    overrideEl.checked = overrideRate;
    overrideEl.addEventListener("change", () => {
      overrideRate = !!overrideEl.checked;
      persistView();
      paint();
      if (overrideRate) hideConflictModal();
      else offerResolveConflicts();
    });
  }

  function rowById(id) {
    let found = null;
    const want = Number(id);
    parents.forEach((p) => {
      if (Number(p.id) === want) found = p;
      (p.trays || []).forEach((t) => {
        if (Number(t.id) === want) found = t;
      });
    });
    return found;
  }
  function applyScheduleItems(items) {
    (items || []).forEach((it) => {
      const row = rowById(it.id);
      if (!row) return;
      row.starts_at = it.starts_at;
      row.ends_at = it.ends_at;
      const s = parseDay(it.starts_at);
      const e = parseDay(it.ends_at);
      if (s && e) ensureRange(s, e);
    });
    parents.forEach((p) => {
      if (!(p.trays || []).length) return;
      let start = null;
      let end = null;
      p.trays.forEach((t) => {
        const d = barDates(t);
        if (!start || d.start < start) start = d.start;
        if (!end || d.end > end) end = d.end;
      });
      if (start) {
        p.starts_at = fmt(start);
        p.ends_at = fmt(end);
      }
    });
  }
  function mergeUndoSnapshot(items) {
    if (!(items || []).length) return;
    if (!undoSnapshot) {
      undoSnapshot = items.slice();
      return;
    }
    const have = new Set(undoSnapshot.map((x) => Number(x.id)));
    items.forEach((it) => {
      if (!have.has(Number(it.id))) undoSnapshot.push(it);
    });
  }
  function setUndoMode(on) {
    const btn = document.getElementById("ganttAutoSchedule");
    if (!btn) return;
    btn.textContent = on ? "Undo auto-schedule" : "Auto-schedule";
    btn.setAttribute("data-undo", on ? "1" : "0");
  }
  const conflictModal = document.getElementById("ganttConflictModal");
  function showConflictModal(text) {
    if (!conflictModal) return;
    const el = document.getElementById("ganttConflictText");
    if (el) el.textContent = text;
    conflictModal.hidden = false;
  }
  function hideConflictModal() {
    if (conflictModal) conflictModal.hidden = true;
  }
  async function runAutoSchedule(payload, doneLabel) {
    if (!window.hd) return;
    const r = await hd.post("admin/api", Object.assign({ entity: "work_orders", op: "auto_schedule" }, payload));
    if (r.error) {
      hd.toast(r.message || "Could not auto-schedule", "error");
      return;
    }
    mergeUndoSnapshot(r.snapshot || []);
    applyScheduleItems(r.items || []);
    persistView();
    paint();
    setUndoMode(!!undoSnapshot);
    hd.toast(doneLabel || "Schedule packed to tray rates");
    lastOfferedKey = "";
    offerResolveConflicts();
  }
  const autoBtn = document.getElementById("ganttAutoSchedule");
  if (autoBtn) {
    autoBtn.addEventListener("click", async () => {
      if (autoBtn.getAttribute("data-undo") === "1" && undoSnapshot) {
        if (!window.hd) return;
        const r = await hd.post("admin/api", { entity: "work_orders", op: "restore_schedule", items: undoSnapshot });
        if (r.error) {
          hd.toast(r.message || "Could not undo", "error");
          return;
        }
        applyScheduleItems(undoSnapshot);
        undoSnapshot = null;
        setUndoMode(false);
        persistView();
        paint();
        offerResolveConflicts();
        hd.toast("Restored the previous schedule");
        return;
      }
      const ids = Array.from(selectedOrders);
      if (!ids.length) {
        if (window.hd) hd.toast("Select one or more orders to auto-schedule", "error");
        return;
      }
      await runAutoSchedule({ mode: "orders", order_ids: ids }, "Selected orders auto-scheduled");
    });
  }
  const resolveBtn = document.getElementById("ganttResolveConflicts");
  if (resolveBtn) {
    resolveBtn.addEventListener("click", async () => {
      hideConflictModal();
      const ids = Array.from(conflictCache.ids || []);
      await runAutoSchedule({ mode: "conflicts", unit_ids: ids }, "Conflicts resolved");
    });
  }
  const cancelBtn = document.getElementById("ganttConflictCancel");
  if (cancelBtn) cancelBtn.addEventListener("click", hideConflictModal);
  if (conflictModal) {
    conflictModal.addEventListener("click", (e) => {
      if (e.target === conflictModal) hideConflictModal();
    });
  }

  wrap.addEventListener("click", (e) => {
    if (!e.target.closest(".gantt-bar") && !e.target.closest(".gantt-label")) {
      highlightKey = null;
      paint();
    }
  });

  renderFilters();
  paint();
  offerResolveConflicts();
})();
