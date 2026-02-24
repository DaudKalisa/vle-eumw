self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open('vle-attendance-cache-v1').then(function(cache) {
      return cache.addAll([
        '/student/attendance_confirm.php',
        '/assets/icons/icon-192.png',
        '/assets/icons/icon-512.png',
        'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css',
        'https://unpkg.com/html5-qrcode@2.3.8/minified/html5-qrcode.min.js'
      ]);
    })
  );
});

self.addEventListener('fetch', function(event) {
  event.respondWith(
    caches.match(event.request).then(function(response) {
      return response || fetch(event.request);
    })
  );
});
