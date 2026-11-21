const cachePrefix = 'mapr-pwa-v';
const cacheName = `${cachePrefix}1`;
const offlinePage = '/offline.html';
const publicFiles = [
    offlinePage,
    '/images/mapr-icon-180.png',
    '/images/mapr-icon-192.png',
    '/images/mapr-icon-512.png',
];

self.addEventListener('install', event => {
    const requests = publicFiles.map(path => new Request(new URL(path, self.location.origin), {
        cache: 'reload',
        credentials: 'omit',
        redirect: 'error',
    }));
    event.waitUntil(caches.open(cacheName).then(cache => cache.addAll(requests)));
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(names => Promise.all(names
        .filter(name => name.startsWith(cachePrefix) && name !== cacheName)
        .map(name => caches.delete(name)))));
});

self.addEventListener('fetch', event => {
    const { request } = event;
    if (request.method !== 'GET' || request.mode !== 'navigate' || new URL(request.url).origin !== self.location.origin) {
        return;
    }

    event.respondWith(fetch(request, { cache: 'no-store' }).catch(async () => {
        const cache = await caches.open(cacheName);
        return (await cache.match(offlinePage)) ?? Response.error();
    }));
});
