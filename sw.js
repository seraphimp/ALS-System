// sw.js — ALS Push Notification Service Worker
// ⚠️  Place this file at your web ROOT: /sw.js  (NOT inside /admin/)

self.addEventListener('push', function(event) {
    const data = event.data ? event.data.json() : {};
    const title = data.title || 'ALS Admin';
    const options = {
        body:    data.body  || 'You have a new notification.',
        icon:    data.icon  || '/logo/als-logo-removebg-preview.png',
        badge:   data.badge || '/logo/als-logo-removebg-preview.png',
        tag:     data.tag   || 'als-notification',
        data:    { url: data.url || '/admin-web/preregistrations.php' },
        vibrate: [200, 100, 200],
        renotify: true,
        requireInteraction: false
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url)
        ? event.notification.data.url
        : '/admin-web/preregistrations.php';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            for (const client of list) {
                if (client.url.includes('/admin-web/') && 'focus' in client) {
                    client.focus();
                    return client.navigate(url);
                }
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});

self.addEventListener('install',  () => self.skipWaiting());
self.addEventListener('activate', e  => e.waitUntil(clients.claim()));