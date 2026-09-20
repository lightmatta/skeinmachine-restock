# HouseDye mobile clients (Android & iPhone)

The portal ships with two complementary mobile paths that share the **same
secure JSON API** (`index.php?r=api`) and therefore always stay in sync with
the web portal's functionality (orders, tracking, catalog, and realtime chat).

## 1. Installable PWA (ready to use today)

The portal is a Progressive Web App:

- `public/assets/manifest.webmanifest` + `public/sw.js` make it installable.
- On **Android/Chrome**: visit the portal → "Add to Home screen" → it launches
  standalone. Web Push notifications are supported.
- On **iPhone/Safari (iOS 16.4+)**: Share → "Add to Home Screen". Installed PWAs
  support Web Push and local notifications.
- The service worker caches the app shell for speed and resilience, and the
  client polls for new support replies to raise notifications.

This is the fastest way to put the portal "in your pocket" with no app-store
process.

## 2. Native wrapper via Capacitor (App Store / Play Store)

To ship true native binaries that wrap the same web UI + API and add native
push, use [Capacitor](https://capacitorjs.com). This keeps one codebase while
producing native `.apk`/`.aab` and `.ipa` artifacts.

```bash
# From a machine with Node + Android Studio (Android) and Xcode (iOS):
npm init -y
npm install @capacitor/core @capacitor/cli @capacitor/push-notifications
npx cap init "HouseDye" "com.housedye.portal" --web-dir=www

# Point the app at your deployed portal (see capacitor.config.json in this dir),
# then add platforms and open the native IDEs:
npx cap add android
npx cap add ios
npx cap open android   # build .apk / .aab in Android Studio
npx cap open ios       # build .ipa in Xcode
```

- `capacitor.config.json` in this folder is a ready starting point — set
  `server.url` to your HTTPS portal URL so the native shell loads the live app.
- For native push, register the device token with your portal and store it
  against the user (extend `api_tokens`/add a `device_tokens` table), then send
  pushes through FCM (Android) / APNs (iOS). The included service worker already
  implements the `push`/`notificationclick` display handlers for the PWA path.

### Why not prebuilt binaries here?
Compiling native Android/iOS binaries requires the Android SDK and Xcode
toolchains (and Apple signing). Those aren't part of this PHP deployment bundle,
so the repo provides the production-ready **API + PWA** plus this reproducible
Capacitor recipe rather than unsigned binaries.

## API quick reference

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `?r=api&a=login` | `{email,password}` → `{token, user}` |
| GET | `?r=api&a=me` | Current user (Bearer token) |
| GET | `?r=api&a=products` | Catalog (prices/stock for wholesale) |
| GET | `?r=api&a=orders` | Own orders |
| GET | `?r=api&a=order&id=..` | One order + items |
| GET | `?r=api&a=messages&after=..` | Poll chat messages |
| POST | `?r=api&a=send_message` | `{body}` send a chat message |
| GET | `?r=api&a=notifications` | Unread message count (badge) |
| POST | `?r=api&a=logout` | Revoke the token |

All authenticated calls require `Authorization: Bearer <token>`. Tokens are
stored only as salted SHA-256 hashes server-side.
