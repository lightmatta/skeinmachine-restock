/* HouseDye Portal service worker — offline app shell + system notifications. */
const CACHE = "hd-portal-v2";
const SHELL = ["assets/app.css", "assets/app.js", "assets/icon.svg", "assets/manifest.webmanifest"];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).catch(() => {}));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const req = event.request;
  // Network-first for dynamic PHP routes; cache-first for static assets.
  if (req.method !== "GET" || req.url.includes("index.php")) return;
  event.respondWith(
    caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
      return res;
    }).catch(() => hit))
  );
});

function notifyOptions(data) {
  const opts = data.options || {};
  return {
    body: opts.body || data.body || "New activity in your portal.",
    icon: opts.icon || "assets/icon.svg",
    badge: opts.badge || "assets/icon.svg",
    tag: opts.tag || "hd-alert",
    renotify: opts.renotify !== false,
    vibrate: opts.vibrate || [80, 40, 80],
    data: opts.data || { url: data.url || "index.php?r=dashboard" },
  };
}

// Display a push notification (payload optional). Used when a real push
// server with VAPID keys is configured; see mobile/README.md.
self.addEventListener("push", (event) => {
  let data = { title: "HouseDye", body: "You have a new message." };
  try { if (event.data) data = event.data.json(); } catch (e) {}
  event.waitUntil(
    self.registration.showNotification(data.title || "HouseDye", notifyOptions(data))
  );
});

// Page-triggered alerts (chat poll) so phones put them in the OS shade.
self.addEventListener("message", (event) => {
  const data = event.data || {};
  if (data.type !== "notify") return;
  event.waitUntil(
    self.registration.showNotification(data.title || "HouseDye", notifyOptions(data))
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || "index.php?r=dashboard";
  event.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if ("focus" in c) {
          c.focus();
          if (c.navigate && url) {
            try { c.navigate(url); } catch (e) {}
          }
          return c;
        }
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});
