const CACHE = 'gaugeiq-v42';

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE).then(cache => cache.addAll([
            './',
            './manifest.json',
            './branding/gaugeiq-logo-dark-background-original.png',
            './branding/gaugeiq-app-icon-1024.png',
            './branding/apple-touch-icon.png',
            './branding/icon-192.png',
            './branding/icon-512.png',
            './weather-icons/clear-day.svg',
            './weather-icons/clear-night.svg',
            './weather-icons/partly-cloudy-day.svg',
            './weather-icons/partly-cloudy-night.svg',
            './weather-icons/overcast.svg',
            './weather-icons/fog.svg',
            './weather-icons/rain.svg',
            './weather-icons/snow.svg',
            './weather-icons/thunderstorm.svg',
            './css/app.css',
            './js/local-weather.js',
            './js/app.js'
        ]))
    );
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.filter(key => key.startsWith('gaugeiq-') && key !== CACHE).map(key => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('push', event => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : 'GaugeIQ alert' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'GaugeIQ', {
            body: data.body || 'Atmospheric pressure has changed.',
            data: { url: data.url || './' },
            tag: 'gaugeiq-pressure',
            renotify: true,
            silent: false
        })
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();

    const target = event.notification.data?.url || './';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
            for (const client of windowClients) {
                if ('focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }

            return clients.openWindow ? clients.openWindow(target) : undefined;
        })
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    if (url.pathname.endsWith('/service-worker.js') || url.pathname.endsWith('/manifest.json')) {
        event.respondWith(fetch(event.request));
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request, { ignoreSearch: true }))
    );
});
