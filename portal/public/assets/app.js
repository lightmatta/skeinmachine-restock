/* HouseDye Portal — shared client script (no external dependencies). */
(function () {
  "use strict";

  const CSRF = window.__CSRF__ || "";

  const hd = (window.hd = {
    csrf: CSRF,

    async post(route, body) {
      const res = await fetch("index.php?r=" + encodeURIComponent(route), {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF },
        body: JSON.stringify(body || {}),
      });
      return res.json();
    },

    async get(route, params) {
      const qs = new URLSearchParams(Object.assign({ r: route }, params || {}));
      const res = await fetch("index.php?" + qs.toString(), {
        headers: { "X-Requested-With": "fetch" },
      });
      return res.json();
    },

    toast(msg, kind) {
      const el = document.createElement("div");
      el.className = "flash " + (kind || "ok");
      el.style.position = "fixed";
      el.style.right = "22px";
      el.style.top = "72px";
      el.style.zIndex = "80";
      el.style.boxShadow = "0 10px 30px rgba(0,0,0,.15)";
      el.textContent = msg;
      document.body.appendChild(el);
      setTimeout(() => el.remove(), 2600);
    },

    money(cents) {
      const code = window.__CURRENCY__ || "AUD";
      const n = (Number(cents || 0) / 100).toFixed(2);
      return "$" + n + " " + code;
    },

    /**
     * Snap a qty input up to its min attribute. Returns the enforced qty.
     * When toastOnClamp is true, show a notice if the typed value was too low.
     */
    clampQty(input, toastOnClamp) {
      if (!input) return 1;
      const min = Math.max(1, parseInt(input.getAttribute("min") || "1", 10) || 1);
      let qty = parseInt(input.value || String(min), 10);
      if (!Number.isFinite(qty) || qty < 1) {
        qty = min;
      }
      if (qty < min) {
        input.value = String(min);
        if (toastOnClamp) {
          hd.toast("Minimum quantity is " + min, "error");
        }
        return min;
      }
      input.value = String(qty);
      return qty;
    },

    setCartCount(n) {
      const el = document.getElementById("navCartCount");
      if (!el) return;
      const c = Number(n || 0);
      el.textContent = String(c);
      el.hidden = c <= 0;
      el.classList.toggle("is-empty", c <= 0);
    },

    escape(s) {
      const d = document.createElement("div");
      d.textContent = s == null ? "" : String(s);
      return d.innerHTML;
    },
  });

  /* ---------------- Chatbox (role-aware, persistent) ---------------- */
  function initChat() {
    const fab = document.getElementById("chatFab");
    const panel = document.getElementById("chatPanel");
    if (!fab || !panel) return;

    const role = panel.dataset.role; // 'admin' | 'client' | 'staff'
    const log = panel.querySelector(".chat-log");
    const input = panel.querySelector(".chat-input input");
    const inputWrap = panel.querySelector(".chat-input");
    const presence = panel.querySelector(".presence");
    const sendBtn = panel.querySelector(".chat-send");
    const closeBtn = panel.querySelector(".chat-close");
    const expandBtn = panel.querySelector(".chat-expand");
    const tabsEl = panel.querySelector(".chat-tabs");

    const OPEN_KEY = "hd_chat_open", FS_KEY = "hd_chat_fs", THREAD_KEY = "hd_chat_thread";
    let lastId = 0, activeThread = null, pollTimer = null, threadsCache = [];

    const toSel = document.getElementById("chatTo");
    let staffTo = 0;

    function appendMsg(m, mineClass, themClass, metaLabel) {
      if (m.id <= lastId) return;
      lastId = m.id;
      const div = document.createElement("div");
      const mine = (m.sender_user_id != null && window.__USER_ID__)
        ? Number(m.sender_user_id) === Number(window.__USER_ID__)
        : m.sender === mineClass;
      div.className = "msg " + (m.sender === "system" ? "system" : mine ? "me" : "them");
      div.innerHTML = hd.escape(m.body) + '<span class="meta">' + hd.escape(metaLabel(m)) + " · " + hd.escape(m.time) + "</span>";
      log.appendChild(div);
      log.scrollTop = log.scrollHeight;
    }

    function updatePresence(online) {
      if (!presence || role === "admin") return;
      presence.innerHTML = online ? '<span class="dot-on">● Support online</span>' : '<span class="dot-off">● Support offline</span>';
    }

    /* ----- client (guest + wholesale): single thread ----- */
    /* ----- staff: all-admins by default, or a chosen admin/staff ----- */
    async function clientRefresh(reset) {
      try {
        if (reset) { log.innerHTML = ""; lastId = 0; }
        const params = { after: lastId };
        if (role === "staff") params.to = staffTo;
        if (panel.classList.contains("open")) params.mark = 1;
        const data = await hd.get("chat.poll", params);
        updatePresence(data.admin_online);
        (data.messages || []).forEach((m) => appendMsg(m, role === "staff" ? "staff" : "client", "admin",
          (x) => (x.sender === "admin" ? "Support" : x.sender === "system" ? "Auto-reply" : x.sender === "staff" ? "Staff" : "You")));
      } catch (e) {}
    }
    async function clientSend() {
      const text = input.value.trim(); if (!text) return; input.value = "";
      const payload = { body: text };
      if (role === "staff" && staffTo) payload.to_user_id = staffTo;
      const data = await hd.post("chat.send", payload);
      (data.messages || []).forEach((m) => appendMsg(m, role === "staff" ? "staff" : "client", "admin",
        (x) => (x.sender === "admin" ? "Support" : x.sender === "system" ? "Auto-reply" : x.sender === "staff" ? "You" : "You")));
    }

    async function loadStaffRecipients() {
      if (role !== "staff" || !toSel) return;
      try {
        const data = await hd.get("chat.recipients");
        const keep = String(staffTo);
        toSel.innerHTML = '<option value="0">All admins</option>';
        (data.recipients || []).forEach((u) => {
          const opt = document.createElement("option");
          opt.value = String(u.id);
          opt.textContent = u.name + " (" + u.role + ")";
          toSel.appendChild(opt);
        });
        toSel.value = keep;
      } catch (e) {}
    }
    if (toSel) {
      toSel.addEventListener("change", () => {
        staffTo = parseInt(toSel.value || "0", 10) || 0;
        clientRefresh(true);
      });
    }

    /* ----- admin: many concurrent client chats (shared across admins) ----- */
    async function adminLoadThreads() {
      try {
        const data = await hd.post("admin/api", { entity: "messages", op: "threads" });
        threadsCache = data.threads || [];
        const totalUnread = threadsCache.reduce((n, t) => n + (parseInt(t.unread, 10) || 0), 0);
        fab.classList.toggle("has-unread", totalUnread > 0);
        if (panel.classList.contains("open")) renderTabs();
      } catch (e) {}
    }
    function renderTabs() {
      if (!tabsEl) return;
      if (activeThread == null && threadsCache.length) {
        const saved = parseInt(localStorage.getItem(THREAD_KEY) || "0", 10);
        activeThread = threadsCache.some((t) => t.id === saved) ? saved : threadsCache[0].id;
        adminLoadThread(activeThread, false);
      }
      tabsEl.innerHTML = "";
      if (!threadsCache.length) {
        tabsEl.innerHTML = '<span class="muted" style="padding:6px 8px">No customer chats yet.</span>';
        if (inputWrap) inputWrap.classList.add("disabled");
        return;
      }
      threadsCache.forEach((t) => {
        const name = ((t.first_name || "") + " " + (t.last_name || "")).trim() || t.email;
        const tab = document.createElement("button");
        tab.className = "chat-tab" + (t.id === activeThread ? " active" : "");
        tab.innerHTML = hd.escape(name) + (t.unread > 0 ? ' <span class="badge hl">' + t.unread + "</span>" : "");
        tab.addEventListener("click", () => {
          activeThread = t.id; localStorage.setItem(THREAD_KEY, String(t.id));
          adminLoadThread(t.id, false); renderTabs();
          if (inputWrap) inputWrap.classList.remove("disabled");
        });
        tabsEl.appendChild(tab);
      });
      if (inputWrap) inputWrap.classList.toggle("disabled", activeThread == null);
    }
    async function adminLoadThread(id, incremental) {
      try {
        if (!incremental) { log.innerHTML = ""; lastId = 0; }
        const data = await hd.post("admin/api", { entity: "messages", op: "thread", id: id });
        (data.messages || []).forEach((m) => appendMsg(m, "admin", "client",
          (x) => (x.sender === "admin" ? "You" : x.sender === "system" ? "Auto-reply" : x.sender === "staff" ? "Staff" : "Customer")));
      } catch (e) {}
    }
    async function adminSend() {
      const text = input.value.trim(); if (!text || activeThread == null) return; input.value = "";
      await hd.post("admin/api", { entity: "messages", op: "reply", id: activeThread, body: text });
      adminLoadThread(activeThread, true);
      adminLoadThreads();
    }

    function tick() {
      if (role === "admin") { adminLoadThreads(); if (activeThread != null) adminLoadThread(activeThread, true); }
      else clientRefresh(false);
    }
    function setOpen(open) {
      panel.classList.toggle("open", open);
      localStorage.setItem(OPEN_KEY, open ? "1" : "0");
      if (open) {
        const banner = document.getElementById("chatAlert");
        if (banner) { banner.classList.remove("is-on"); banner.hidden = true; }
        if (role === "admin") { adminLoadThreads(); }
        else { if (role === "staff") loadStaffRecipients(); clientRefresh(true); input.focus(); }
        if (!pollTimer) pollTimer = setInterval(tick, 2000);
      } else if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    }
    function send() { role === "admin" ? adminSend() : clientSend(); }

    fab.addEventListener("click", () => setOpen(!panel.classList.contains("open")));
    if (closeBtn) closeBtn.addEventListener("click", () => setOpen(false));
    if (sendBtn) sendBtn.addEventListener("click", send);
    input.addEventListener("keydown", (e) => { if (e.key === "Enter") send(); });
    // Clicking the main-page backdrop (anything outside the chat window and FAB)
    // defocuses the chat and minimizes it back to the launcher.
    document.addEventListener("pointerdown", (e) => {
      if (!panel.classList.contains("open")) return;
      const t = e.target;
      if (!(t instanceof Node)) return;
      if (panel.contains(t) || fab.contains(t)) return;
      setOpen(false);
    });
    if (expandBtn) expandBtn.addEventListener("click", () => {
      const fs = panel.classList.toggle("fullscreen");
      localStorage.setItem(FS_KEY, fs ? "1" : "0");
      renderTabs();
    });

    // Restore persisted UI state so navigating pages doesn't interrupt chats.
    if (role === "admin" && localStorage.getItem(FS_KEY) === "1") panel.classList.add("fullscreen");
    if (localStorage.getItem(OPEN_KEY) === "1") setOpen(true);

    initLiveAlerts(function openChat() {
      if (!panel.classList.contains("open")) setOpen(true);
    });
  }

  function beep() {
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      const ctx = new Ctx();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = "sine";
      osc.frequency.value = 880;
      gain.gain.setValueAtTime(0.14, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.22);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.24);
      osc.onended = function () { ctx.close(); };
    } catch (e) {}
  }

  function isStandalonePwa() {
    return (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches)
      || window.navigator.standalone === true;
  }

  function notifyUrl() {
    return "index.php?r=dashboard";
  }

  /* Service-worker showNotification lands in the phone OS shade; page
     Notification is a desktop fallback when no SW is available. */
  function browserNotify(title, body, meta) {
    if (!("Notification" in window) || Notification.permission !== "granted") return;
    const opts = {
      body: body || "",
      icon: "assets/icon.svg",
      badge: "assets/icon.svg",
      tag: (meta && meta.tag) || "hd-alert",
      renotify: true,
      vibrate: [80, 40, 80],
      data: { url: (meta && meta.url) || notifyUrl() },
    };
    function pageFallback() {
      try { new Notification(title, opts); } catch (e) {}
    }
    if (!("serviceWorker" in navigator)) {
      pageFallback();
      return;
    }
    navigator.serviceWorker.ready.then(function (reg) {
      if (reg && typeof reg.showNotification === "function") {
        return reg.showNotification(title, opts);
      }
      pageFallback();
    }).catch(pageFallback);
  }

  function hideNotifyPrompt() {
    const el = document.getElementById("notifyPrompt");
    if (!el) return;
    el.hidden = true;
    el.classList.remove("is-on");
  }

  function showNotifyPrompt(wanted) {
    const el = document.getElementById("notifyPrompt");
    if (!el || !wanted || !("Notification" in window)) return;
    if (Notification.permission !== "default") return;
    if (sessionStorage.getItem("hd_notify_dismissed") === "1") return;
    const body = el.querySelector(".notify-prompt-body");
    const ios = /iPad|iPhone|iPod/.test(navigator.userAgent);
    if (body && ios && !isStandalonePwa()) {
      body.textContent = "On iPhone, add this portal to your Home Screen, open it from there, then tap Allow so alerts appear in the notification shade.";
    }
    el.hidden = false;
    el.classList.add("is-on");
  }

  function requestNotifyPermission() {
    if (!("Notification" in window) || Notification.permission !== "default") {
      return Promise.resolve(("Notification" in window) ? Notification.permission : "denied");
    }
    return Notification.requestPermission().then(function (p) {
      if (p === "granted") hideNotifyPrompt();
      return p;
    }).catch(function () { return "denied"; });
  }

  function armPermissionOnGesture(wanted) {
    if (!wanted || !("Notification" in window) || Notification.permission !== "default") return;
    function once() {
      document.removeEventListener("pointerdown", once, true);
      requestNotifyPermission();
    }
    document.addEventListener("pointerdown", once, true);
  }

  function initNotifyPrompt(wanted) {
    const el = document.getElementById("notifyPrompt");
    const allow = document.getElementById("notifyAllow");
    const dismiss = document.getElementById("notifyDismiss");
    if (allow) {
      allow.addEventListener("click", function (e) {
        e.preventDefault();
        requestNotifyPermission();
      });
    }
    if (dismiss) {
      dismiss.addEventListener("click", function (e) {
        e.preventDefault();
        sessionStorage.setItem("hd_notify_dismissed", "1");
        hideNotifyPrompt();
      });
    }
    if (wanted) {
      showNotifyPrompt(true);
      armPermissionOnGesture(true);
    } else if (el) {
      hideNotifyPrompt();
    }
  }

  function ensureNotifyPermission(wanted) {
    if (!wanted || !("Notification" in window)) return;
    if (Notification.permission === "granted") {
      hideNotifyPrompt();
      return;
    }
    if (Notification.permission === "default") {
      showNotifyPrompt(true);
      armPermissionOnGesture(true);
    }
  }

  function initLiveAlerts(openChat) {
    const fab = document.getElementById("chatFab");
    const panel = document.getElementById("chatPanel");
    const banner = document.getElementById("chatAlert");
    if (!fab || !panel) return;

    const MSG_KEY = "hd_alert_msg";
    const EVT_KEY = "hd_alert_evt";
    const PRIMED_KEY = "hd_alert_primed";
    let lastMsgId = parseInt(localStorage.getItem(MSG_KEY) || "0", 10) || 0;
    let lastEventId = parseInt(localStorage.getItem(EVT_KEY) || "0", 10) || 0;
    let primed = localStorage.getItem(PRIMED_KEY) === "1";
    let imBrowser = false;

    function persist() {
      localStorage.setItem(MSG_KEY, String(lastMsgId));
      localStorage.setItem(EVT_KEY, String(lastEventId));
      localStorage.setItem(PRIMED_KEY, primed ? "1" : "0");
    }

    function hideBanner() {
      if (!banner) return;
      banner.classList.remove("is-on");
      banner.hidden = true;
    }

    function showBanner(title, body) {
      if (!banner || panel.classList.contains("open")) return;
      const t = banner.querySelector(".chat-alert-title");
      const b = banner.querySelector(".chat-alert-body");
      if (t) t.textContent = title || "New message";
      if (b) b.textContent = body || "";
      banner.hidden = false;
      banner.classList.add("is-on");
    }

    function applyUnread(n, previewTitle, previewBody) {
      const unread = (parseInt(n, 10) || 0) > 0;
      fab.classList.toggle("has-unread", unread);
      if (unread && !panel.classList.contains("open")) {
        showBanner(previewTitle || "New message", previewBody || "Click the chat box to read it");
      } else {
        hideBanner();
      }
    }

    if (banner) {
      banner.addEventListener("click", function () {
        hideBanner();
        if (typeof openChat === "function") openChat();
      });
    }

    async function tickAlerts() {
      try {
        const data = await hd.get("chat.alerts", { after: lastMsgId, after_event: lastEventId });
        imBrowser = !!data.im_browser_notifications;
        ensureNotifyPermission(imBrowser || !!data.admin_event_alerts);
        const msgs = data.messages || [];
        const events = data.events || [];
        const newest = msgs[0];
        if (!primed) {
          msgs.forEach(function (m) { if (m.id > lastMsgId) lastMsgId = m.id; });
          events.forEach(function (e) { if (e.id > lastEventId) lastEventId = e.id; });
          primed = true;
          persist();
          applyUnread(data.unread, newest ? ("New message from " + newest.sender_name) : "New message", newest ? newest.preview : "");
          return;
        }
        const freshMsgs = msgs.filter(function (m) { return m.id > lastMsgId; }).sort(function (a, b) { return a.id - b.id; });
        const freshEvents = events.filter(function (e) { return e.id > lastEventId; });
        freshMsgs.forEach(function (m) {
          if (m.id > lastMsgId) lastMsgId = m.id;
          beep();
          const title = "New message from " + (m.sender_name || "Support");
          applyUnread(Math.max(1, data.unread), title, m.preview);
          hd.toast(title + (m.preview ? ": " + m.preview : ""), "ok");
          if (imBrowser) browserNotify(title, m.preview || "", { tag: "hd-im-" + m.id, url: notifyUrl() });
        });
        freshEvents.forEach(function (e) {
          if (e.id > lastEventId) lastEventId = e.id;
          beep();
          const text = (e.title || "Client event") + (e.description ? " — " + e.description : "");
          hd.toast(text, "ok");
          if (data.admin_event_alerts) {
            browserNotify(e.title || "Client event", e.description || "", { tag: "hd-event-" + e.id, url: notifyUrl() });
          }
        });
        if (!freshMsgs.length) {
          applyUnread(data.unread, newest ? ("New message from " + newest.sender_name) : "New message", newest ? newest.preview : "");
        }
        persist();
      } catch (e) {}
    }

    initNotifyPrompt(!!window.__NOTIFY_WANTED__);
    tickAlerts();
    setInterval(tickAlerts, 2000);
  }

  /* ---------------- User menu + popover close ---------------- */
  function initPopovers() {
    const btn = document.getElementById("userMenuBtn");
    const menu = document.getElementById("userMenu");
    if (btn && menu) {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const open = menu.classList.toggle("open");
        btn.setAttribute("aria-expanded", open ? "true" : "false");
      });
    }
    // Close any open popover (user menu / column menu) on outside click.
    document.addEventListener("click", (e) => {
      if (menu && !menu.contains(e.target)) closeUserMenu();
      document.querySelectorAll(".col-menu.open").forEach((m) => {
        if (!m.contains(e.target)) m.classList.remove("open");
      });
    });
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      closeUserMenu();
      document.querySelectorAll(".col-menu.open").forEach((m) => m.classList.remove("open"));
    });

    function closeUserMenu() {
      if (!menu || !menu.classList.contains("open")) return;
      menu.classList.remove("open");
      if (btn) btn.setAttribute("aria-expanded", "false");
    }
  }

  /* ---------------- Spreadsheet grid ---------------- */
  class DataGrid {
    constructor(mount, opts) {
      this.mount = typeof mount === "string" ? document.getElementById(mount) : mount;
      this.entity = opts.entity;
      this.columns = opts.columns; // [{key,label,type,editable,options?}]
      this.readonly = !!opts.readonly;
      this.selectable = !!opts.selectable && !this.readonly;
      this.bulk = this.selectable ? (opts.bulk || []) : [];
      this.bulkNumber = this.selectable ? (opts.bulkNumber || []) : [];
      this.wholesalePercent = Number(opts.wholesalePercent || 65);
      this.expandOn = new Set(opts.expandOn || this.columns.filter((c) => c.expand).map((c) => c.key));
      this.onExpand = typeof opts.onExpand === "function" ? opts.onExpand : null;
      this.sortKey = null;
      this.sortDir = 1;
      this.search = "";
      this.filters = opts.filters || {};
      this.hidden = new Set(opts.hidden || []);
      this.persistHidden = opts.persistHidden || null;
      this.rowActions = Array.isArray(opts.rowActions) ? opts.rowActions : [];
      this.groupKey = opts.groupKey || null;
      this.rows = [];
      this.selected = new Set();
      this.expanded = new Set();
      this.render();
      this.reload();
    }

    async reload() {
      const data = await hd.post("admin/api", {
        entity: this.entity,
        op: "list",
        filters: this.filters,
      });
      this.rows = data.rows || [];
      this.paint();
    }

    setFilter(key, val) {
      if (val === "" || val == null) delete this.filters[key];
      else this.filters[key] = val;
      this.reload();
    }

    render() {
      const cols = this.columns;
      const items = cols
        .map(
          (c) =>
            `<label class="col-menu-item"><input type="checkbox" data-col="${c.key}" ${this.hidden.has(c.key) ? "" : "checked"}> ${hd.escape(c.label)}</label>`
        )
        .join("");
      const bulkHtml = this.bulk.length
        ? `<div class="grid-bulk">${this.bulk
            .map((b) => {
              const opts = (b.options || [])
                .map((o) => `<option value="${hd.escape(String(o.value))}">${hd.escape(o.label)}</option>`)
                .join("");
              return `<select data-bulk="${hd.escape(b.key)}" aria-label="${hd.escape(b.label)}"><option value="">${hd.escape(b.label)}</option>${opts}</select>`;
            })
            .join("")}</div>`
        : "";
      const bulkNumHtml = this.bulkNumber.length
        ? `<div class="grid-bulk-number">${this.bulkNumber
            .map((b) => {
              const min = b.min != null ? Number(b.min) : 0;
              const tip = hd.escape(b.tip || b.label);
              return `<label class="grid-bulk-number-item" title="${tip}"><span class="grid-bulk-number-label">${hd.escape(b.label)}</span>
                <input type="number" min="${min}" class="grid-bulk-number-input" data-bulk-num="${hd.escape(b.key)}" placeholder="0" title="${tip}">
                <button type="button" class="btn btn-sm btn-primary" data-bulk-num-apply="${hd.escape(b.key)}">Apply</button></label>`;
            })
            .join("")}</div>`
        : "";
      this.mount.innerHTML =
        `<div class="toolbar">
           <input type="search" placeholder="Search…" class="grid-search">
           ${bulkHtml}
           ${bulkNumHtml}
           <div class="spacer"></div>
           <div class="col-menu">
             <button class="btn btn-sm btn-ghost col-menu-btn" title="Show / hide columns" aria-haspopup="true" aria-expanded="false">${(window.__ICONS__ && window.__ICONS__.columns) || "cols"}</button>
             <div class="col-menu-drop"><div class="col-menu-title">Columns</div>${items}</div>
           </div>
           ${this.readonly ? "" : `<button class="btn btn-sm btn-primary grid-add">+ Add</button>`}
         </div>
         <div class="table-wrap" style="margin-top:10px"><table class="grid"><thead></thead><tbody></tbody></table></div>`;

      this.mount.querySelector(".grid-search").addEventListener("input", (e) => {
        this.search = e.target.value.toLowerCase();
        this.paint();
      });
      if (this.mount.querySelector(".grid-search") && this.entity === "vendor_products") {
        this.mount.querySelector(".grid-search").placeholder = "Filter product names…";
      }
      const addBtn = this.mount.querySelector(".grid-add");
      if (addBtn) addBtn.addEventListener("click", () => this.addRow());

      this.mount.querySelectorAll(".grid-bulk select").forEach((sel) => {
        sel.addEventListener("change", () => {
          if (!sel.value) return;
          this.applyBulk(sel.dataset.bulk, sel.value);
          sel.value = "";
        });
      });
      this.mount.querySelectorAll("[data-bulk-num-apply]").forEach((btn) => {
        btn.addEventListener("click", () => {
          const key = btn.getAttribute("data-bulk-num-apply");
          const inp = this.mount.querySelector('[data-bulk-num="' + key + '"]');
          if (!inp || inp.value === "") return;
          this.applyBulk(key, inp.value);
          inp.value = "";
        });
      });

      const menu = this.mount.querySelector(".col-menu");
      const menuBtn = menu.querySelector(".col-menu-btn");
      menuBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        const open = menu.classList.toggle("open");
        menuBtn.setAttribute("aria-expanded", open ? "true" : "false");
      });
      menu.querySelectorAll(".col-menu-item input").forEach((cb) => {
        cb.addEventListener("change", () => {
          const k = cb.dataset.col;
          if (cb.checked) this.hidden.delete(k);
          else this.hidden.add(k);
          this.paint();
          this.saveHidden();
        });
      });
    }

    saveHidden() {
      if (!this.persistHidden) return;
      hd.post("admin/api", {
        entity: "prefs",
        op: "set",
        key: this.persistHidden,
        value: Array.from(this.hidden),
      }).catch(function () {});
    }

    visibleCols() {
      return this.columns.filter((c) => !this.hidden.has(c.key));
    }

    filteredRows() {
      let rows = this.rows.slice();
      if (this.search) {
        rows = rows.filter((r) =>
          this.columns.some((c) => String(r[c.key] ?? "").toLowerCase().includes(this.search))
        );
      }
      if (this.sortKey) {
        rows.sort((a, b) => {
          const va = a[this.sortKey],
            vb = b[this.sortKey];
          const na = parseFloat(va),
            nb = parseFloat(vb);
          let cmp;
          if (!isNaN(na) && !isNaN(nb)) cmp = na - nb;
          else cmp = String(va ?? "").localeCompare(String(vb ?? ""));
          return cmp * this.sortDir;
        });
      }
      return rows;
    }

    paint() {
      const cols = this.visibleCols();
      const thead = this.mount.querySelector("thead");
      const tbody = this.mount.querySelector("tbody");
      const hasActions = !this.readonly || (this.rowActions && this.rowActions.length > 0);
      const extra = (hasActions ? 1 : 0) + (this.selectable ? 1 : 0);
      const checkHead = this.selectable
        ? `<th class="grid-check"><input type="checkbox" class="grid-check-all" aria-label="Select all"></th>`
        : "";
      thead.innerHTML =
        "<tr>" +
        checkHead +
        cols
          .map((c) => {
            const caret = this.sortKey === c.key ? (this.sortDir === 1 ? " ▲" : " ▼") : "";
            const tip = c.tip ? ` title="${hd.escape(c.tip)}"` : ` title="${hd.escape(c.label)}"`;
            return `<th data-key="${c.key}"${tip}>${hd.escape(c.label)}<span class="sortcaret">${caret}</span></th>`;
          })
          .join("") +
        (hasActions ? "<th>Actions</th>" : "") +
        "</tr>";
      thead.querySelectorAll("th[data-key]").forEach((th) => {
        th.addEventListener("click", () => {
          const k = th.dataset.key;
          if (this.sortKey === k) this.sortDir *= -1;
          else {
            this.sortKey = k;
            this.sortDir = 1;
          }
          this.paint();
        });
      });
      const allCb = thead.querySelector(".grid-check-all");
      if (allCb) {
        allCb.addEventListener("click", (e) => e.stopPropagation());
        allCb.addEventListener("change", () => {
          const rows = this.filteredRows();
          if (allCb.checked) rows.forEach((r) => this.selected.add(r.id));
          else rows.forEach((r) => this.selected.delete(r.id));
          this.paint();
        });
      }

      this.syncBulkBar();

      const rows = this.filteredRows();
      if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="${cols.length + extra}" class="empty">No records.</td></tr>`;
        return;
      }
      tbody.innerHTML = "";
      let lastGroup = null;
      rows.forEach((r) => {
        if (this.groupKey) {
          const g = r[this.groupKey] || "Ungrouped";
          if (g !== lastGroup) {
            lastGroup = g;
            const gh = document.createElement("tr");
            gh.className = "grid-group";
            const gtd = document.createElement("td");
            gtd.colSpan = cols.length + extra;
            gtd.textContent = g;
            gh.appendChild(gtd);
            tbody.appendChild(gh);
          }
        }
        const tr = document.createElement("tr");
        if (this.expanded.has(r.id)) tr.classList.add("is-expanded");
        if (this.selectable) {
          const td = document.createElement("td");
          td.className = "grid-check";
          const cb = document.createElement("input");
          cb.type = "checkbox";
          cb.checked = this.selected.has(r.id);
          cb.setAttribute("aria-label", "Select row " + r.id);
          cb.addEventListener("change", () => {
            if (cb.checked) this.selected.add(r.id);
            else this.selected.delete(r.id);
            this.syncBulkBar();
            const head = this.mount.querySelector(".grid-check-all");
            if (head) {
              const visible = this.filteredRows();
              head.checked = visible.length > 0 && visible.every((row) => this.selected.has(row.id));
            }
          });
          td.appendChild(cb);
          tr.appendChild(td);
        }
        cols.forEach((c) => {
          const td = document.createElement("td");
          td.dataset.key = c.key;
          td.innerHTML = this.formatCell(c, r[c.key], r);
          if (c.editable && !this.readonly) {
            td.classList.add("editable");
            td.addEventListener("dblclick", () => this.editCell(td, c, r));
          }
          if (this.expandOn.has(c.key) && this.onExpand) {
            td.classList.add("expand-cell");
            td.addEventListener("click", (e) => {
              if (e.target.closest("input,select,button")) return;
              this.toggleExpand(r);
            });
          }
          tr.appendChild(td);
        });
        if (hasActions) {
          const td = document.createElement("td");
          td.className = "no-print";
          const icons = window.__ICONS__ || {};
          const extras = (this.rowActions || [])
            .map((a) => {
              const icon = (a.icon && icons[a.icon]) || "";
              const title = a.title || a.op || "Action";
              return `<button type="button" class="btn btn-sm btn-ghost act-custom" data-op="${hd.escape(a.op)}" title="${hd.escape(title)}">${icon || hd.escape(title)}</button>`;
            })
            .join("");
          const mutate = this.readonly
            ? ""
            : `<button class="btn btn-sm btn-ghost act-archive" title="Archive">${icons.archive || ""}</button>
               <button class="btn btn-sm btn-danger act-del" title="Delete">${icons.trash || ""}</button>`;
          td.innerHTML = `<span class="row-actions">${extras}${mutate}</span>`;
          td.querySelectorAll(".act-custom").forEach((btn) => {
            btn.addEventListener("click", () => this.customAction(r, btn.dataset.op, btn.getAttribute("title")));
          });
          const del = td.querySelector(".act-del");
          const arch = td.querySelector(".act-archive");
          if (del) del.addEventListener("click", () => this.deleteRow(r));
          if (arch) arch.addEventListener("click", () => this.archiveRow(r));
          tr.appendChild(td);
        }
        tbody.appendChild(tr);
        if (this.expanded.has(r.id) && r.__expandHtml) {
          const ex = document.createElement("tr");
          ex.className = "expand-row";
          const td = document.createElement("td");
          td.colSpan = cols.length + extra;
          td.innerHTML = r.__expandHtml;
          ex.appendChild(td);
          tbody.appendChild(ex);
        }
      });
    }

    syncBulkBar() {
      const on = this.selected.size > 1;
      const bar = this.mount.querySelector(".grid-bulk");
      if (bar) bar.classList.toggle("is-on", on);
      const num = this.mount.querySelector(".grid-bulk-number");
      if (num) {
        num.classList.toggle("is-on", on);
        num.querySelectorAll(".grid-bulk-number-label").forEach((el) => {
          const base = el.getAttribute("data-base") || el.textContent.replace(/\s*\(\d+ selected\)$/, "");
          el.setAttribute("data-base", base);
          el.textContent = on ? base + " (" + this.selected.size + " selected)" : base;
        });
      }
    }

    async applyBulk(key, value) {
      const ids = Array.from(this.selected);
      if (ids.length < 2) return;
      const data = await hd.post("admin/api", {
        entity: this.entity,
        op: "bulk_update",
        ids,
        changes: { [key]: value },
      });
      if (data.error) {
        hd.toast(data.message || "Bulk update failed", "error");
        return;
      }
      hd.toast("Updated " + (data.updated || ids.length) + " products");
      this.selected.clear();
      this.reload();
    }

    async toggleExpand(row) {
      if (this.expanded.has(row.id)) {
        this.expanded.delete(row.id);
        this.paint();
        return;
      }
      this.expanded.add(row.id);
      if (!row.__expandHtml) {
        try {
          row.__expandHtml = await this.onExpand(row);
        } catch (e) {
          row.__expandHtml = '<p class="muted" style="margin:0">Could not load details.</p>';
        }
      }
      this.paint();
    }

    formatCell(col, val, row) {
      if (col.options && col.key === "vendor_id") {
        const hit = (col.options || []).find((o) => String(o && typeof o === "object" ? o.value : o) === String(val ?? 0));
        const label = hit && typeof hit === "object" ? (hit.label ?? hit.value) : (hit || row.vendor_name || "—");
        return hd.escape(String(label || "—"));
      }
      if (col.type === "money") return hd.money(val);
      if (col.type === "badge") return `<span class="badge ${hd.escape(String(val))}">${hd.escape(String(val))}</span>`;
      if (col.type === "bool") {
        const yes = col.yes || "Yes";
        const no = col.no || "No";
        return val == 1 ? yes : no;
      }
      if (col.type === "password") {
        return row && Number(row.has_password) === 1
          ? '<span class="muted">••••••••</span>'
          : '<span class="muted">Not set</span>';
      }
      return hd.escape(val == null ? "" : String(val));
    }

    editCell(td, col, row) {
      if (td.querySelector("input,select")) return;
      const current = row[col.key] ?? "";
      let field;
      if (col.options) {
        field = document.createElement("select");
        col.options.forEach((o) => {
          const opt = document.createElement("option");
          const value = o && typeof o === "object" ? String(o.value) : String(o);
          const label = o && typeof o === "object" ? String(o.label ?? o.value) : String(o);
          opt.value = value;
          opt.textContent = label;
          if (value === String(current)) opt.selected = true;
          field.appendChild(opt);
        });
      } else {
        field = document.createElement("input");
        field.className = "cell-edit";
        if (col.type === "password") {
          field.type = "text";
          field.autocomplete = "new-password";
          field.placeholder = "Type new password";
          field.value = "";
        } else {
          field.value = col.type === "money" ? (Number(current) / 100).toFixed(2) : current;
        }
      }
      td.innerHTML = "";
      td.appendChild(field);
      field.focus();
      const restore = () => {
        td.innerHTML = this.formatCell(col, row[col.key], row);
      };
      let cancelled = false;
      const commit = async () => {
        if (cancelled) return;
        let value = field.value;
        if (col.type === "password") {
          if (!value) {
            restore();
            return;
          }
          if (value.length < 8) {
            hd.toast("Password must be at least 8 characters.", "error");
            restore();
            return;
          }
        }
        if (col.type === "money") value = Math.round(parseFloat(value || "0") * 100);
        const data = await this.saveCell(row, col.key, value);
        if (col.type === "password") {
          if (!data || data.error) {
            restore();
            return;
          }
          row.has_password = 1;
          row.password = "";
          td.innerHTML = this.formatCell(col, "", row);
          return;
        }
        row[col.key] = value;
        if (col.key === "price_cents") {
          row.wholesale_cents = Math.round(Number(value || 0) * this.wholesalePercent / 100);
        }
        td.innerHTML = this.formatCell(col, value, row);
        if (col.key === "price_cents") {
          const wtd = td.parentElement && td.parentElement.querySelector('td[data-key="wholesale_cents"]');
          if (wtd) wtd.innerHTML = this.formatCell({ type: "money" }, row.wholesale_cents, row);
        }
      };
      field.addEventListener("blur", commit, { once: true });
      field.addEventListener("keydown", (e) => {
        if (e.key === "Enter") field.blur();
        if (e.key === "Escape") {
          cancelled = true;
          restore();
        }
      });
    }

    async saveCell(row, key, value) {
      const data = await hd.post("admin/api", {
        entity: this.entity,
        op: "update",
        id: row.id,
        changes: { [key]: value },
      });
      if (data.error) hd.toast(data.message || "Save failed", "error");
      else hd.toast("Saved");
      return data;
    }

    async addRow() {
      const data = await hd.post("admin/api", { entity: this.entity, op: "create" });
      if (data.error) return hd.toast(data.message || "Create failed", "error");
      hd.toast("Row added");
      this.reload();
    }

    async deleteRow(row) {
      if (!confirm("Delete this record permanently?")) return;
      await hd.post("admin/api", { entity: this.entity, op: "delete", id: row.id });
      this.reload();
    }

    async archiveRow(row) {
      await hd.post("admin/api", { entity: this.entity, op: "archive", id: row.id });
      hd.toast("Archived");
      this.reload();
    }

    async customAction(row, op, title) {
      const data = await hd.post("admin/api", {
        entity: this.entity,
        op,
        id: row.id,
      });
      if (data.error) {
        hd.toast(data.message || ((title || "Action") + " failed"), "error");
        return data;
      }
      hd.toast(data.message || (title || "Done"));
      if (op === "scrape" || op === "sync") this.reload();
      return data;
    }
  }
  hd.DataGrid = DataGrid;

  /* ---------------- PWA ---------------- */
  function initPWA() {
    if ("serviceWorker" in navigator) {
      navigator.serviceWorker.register("sw.js").catch(() => {});
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    initPopovers();
    initChat();
    initPWA();
  });
})();
