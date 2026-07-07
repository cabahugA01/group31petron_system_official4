const CACHE_NAME = 'petron-pos-cache-v1';
const OFFLINE_URL = 'offline.html';

const ASSETS_TO_CACHE = [
  OFFLINE_URL,
  'assets/css/style.css',
  'assets/css/manager_table_design.css',
  'assets/css/manager_customer_management.css',
  'assets/vendor/fontawesome/css/all.min.css',
  'assets/img/logo.png',
  'manifest.json'
];

// Install Event
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[Service Worker] Pre-caching offline assets (resilient)');
      // Cache assets individually and ignore failures so install does not fail
      return Promise.all(ASSETS_TO_CACHE.map(function(asset){
        return fetch(asset, {cache: 'no-store'}).then(function(resp){
          if(!resp || !resp.ok){
            console.warn('[Service Worker] Asset not cached (missing or bad):', asset, resp && resp.status);
            return null;
          }
          return cache.put(asset, resp.clone());
        }).catch(function(err){
          console.warn('[Service Worker] Asset fetch error:', asset, err);
          return null;
        });
      }));
    }).then(() => self.skipWaiting())
  );
});

// Activate Event
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            console.log('[Service Worker] Removing old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch Event
self.addEventListener('fetch', (event) => {
  // Only handle GET requests
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // If requesting a static pre-cached resource, use cache-first strategy
  if (ASSETS_TO_CACHE.some(asset => url.pathname.includes(asset))) {
    event.respondWith(
      caches.match(event.request).then((cachedResponse) => {
        if (cachedResponse) return cachedResponse;
        return fetch(event.request).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const responseCopy = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(event.request, responseCopy);
            });
          }
          return networkResponse;
        }).catch(() => {
          // Silent catch
        });
      })
    );
    return;
  }

  // HTML / Page requests: Network-first, fallback to cache, then offline.html
  event.respondWith(
    fetch(event.request)
      .then((networkResponse) => {
        // Dynamically cache successful page loads so we have offline access to recently viewed pages
        if (networkResponse && networkResponse.status === 200 && event.request.mode === 'navigate') {
          const responseCopy = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseCopy);
          });
        }
        return networkResponse;
      })
      .catch(() => {
        console.log('[Service Worker] Fetch failed, serving cached fallback...');
        return caches.match(event.request).then((cachedResponse) => {
          if (cachedResponse) return cachedResponse;
          
          if (event.request.mode === 'navigate') {
            return caches.match(OFFLINE_URL);
          }
          return null;
        });
      })
  );
});
