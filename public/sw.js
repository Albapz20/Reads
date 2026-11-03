/* Reads PWA service worker.
 * Do not cache authenticated PHP pages or API responses; they may contain private data.
 * This keeps the app network-backed and avoids showing stale/private content offline.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});
