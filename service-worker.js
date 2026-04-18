/**
 * EUMW VLE - Progressive Web App Service Worker
 * Provides offline caching, fast loading, and app-like experience
 */

const CACHE_NAME = 'eumw-vle-cache-v2';
const OFFLINE_URL = '/offline.html';

// Core assets to pre-cache for offline/fast loading
const PRECACHE_ASSETS = [
  '/',
  '/login.php',
  '/offline.html',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/img/Logo.png',
  '/assets/css/global-theme.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js',
  'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'
];

// Install: pre-cache core assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(PRECACHE_ASSETS).catch(err => {
        console.warn('Some assets failed to cache:', err);
        // Cache what we can, don't fail install
        return Promise.allSettled(
          PRECACHE_ASSETS.map(url => cache.add(url).catch(() => {}))
        );
      });
    })
  );
  self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      );
    })
  );
  self.clients.claim();
});

// Fetch strategy: Network First for HTML (always fresh), Cache First for assets
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  // Skip non-GET requests and API/AJAX calls
  if (event.request.method !== 'GET') return;
  if (url.searchParams.has('ajax')) return;
  if (url.pathname.startsWith('/api/')) return;
  
  // For HTML pages: Network First (try server, fall back to cache, then offline page)
  if (event.request.headers.get('accept')?.includes('text/html')) {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          // Cache successful HTML responses
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
          return response;
        })
        .catch(() => {
          return caches.match(event.request).then(cached => {
            return cached || caches.match(OFFLINE_URL);
          });
        })
    );
    return;
  }
  
  // For static assets (CSS, JS, images, fonts): Cache First
  if (url.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico)$/) ||
      url.hostname === 'cdn.jsdelivr.net' ||
      url.hostname === 'fonts.googleapis.com' ||
      url.hostname === 'fonts.gstatic.com') {
    event.respondWith(
      caches.match(event.request).then(cached => {
        if (cached) return cached;
        return fetch(event.request).then(response => {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
          return response;
        });
      })
    );
    return;
  }
  
  // Default: Network with cache fallback
  event.respondWith(
    fetch(event.request)
      .then(response => {
        const clone = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
