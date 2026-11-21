import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const origin = 'https://mapr.test';
const manifest = JSON.parse(readFileSync(new URL('../../public/manifest.webmanifest', import.meta.url)));

function worker(fetch = () => Promise.reject(new Error('Offline'))) {
    const handlers = {};
    const stored = new Map();
    const deleted = [];
    const offline = new Response('Offline page');
    const caches = {
        async open(name) {
            return {
                async addAll(requests) { stored.set(name, Array.from(requests)); },
                async match(path) { return stored.get(name)?.some(request => request.url === origin + path) ? offline : undefined; },
            };
        },
        async keys() { return ['mapr-pwa-v0', 'other-app', ...stored.keys()]; },
        async delete(name) { deleted.push(name); },
    };
    runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), {
        self: { location: { origin }, addEventListener(type, handler) { handlers[type] = handler; } },
        caches, fetch, URL, Request, Response,
    });

    return {
        stored, deleted, offline,
        lifecycle(type) {
            let completion;
            handlers[type]({ waitUntil(promise) { completion = promise; } });
            return completion;
        },
        navigate(overrides = {}) {
            let response;
            const request = { url: origin + '/resident/reports', method: 'GET', mode: 'navigate', ...overrides };
            handlers.fetch({ request, respondWith(promise) { response = promise; } });
            return response;
        },
    };
}

test('precaches only the offline page and public icons without credentials or redirects', async () => {
    const app = worker();
    await app.lifecycle('install');
    const [requests] = app.stored.values();
    assert.deepEqual(requests.map(request => new URL(request.url).pathname).sort(), [
        '/offline.html', '/images/mapr-icon-180.png', ...manifest.icons.map(icon => icon.src),
    ].sort());
    for (const request of requests) {
        assert.equal(request.credentials, 'omit');
        assert.equal(request.redirect, 'error');
    }
});

test('serves the offline page when a same-origin GET navigation fails', async () => {
    const app = worker();
    await app.lifecycle('install');
    assert.equal(await app.navigate(), app.offline);
});

test('passes through successful responses, redirects, and HTTP errors without caching them', async () => {
    for (const status of [200, 302, 403, 503]) {
        const result = new Response(null, { status });
        const app = worker(() => Promise.resolve(result));
        assert.equal(await app.navigate(), result);
        assert.equal(app.stored.size, 0);
    }
});

test('ignores POST, subresource, API, and cross-origin requests', () => {
    const app = worker();
    assert.equal(app.navigate({ method: 'POST' }), undefined);
    assert.equal(app.navigate({ mode: 'cors', url: origin + '/resident/api/reports' }), undefined);
    assert.equal(app.navigate({ mode: 'no-cors', url: origin + '/build/assets/app.js' }), undefined);
    assert.equal(app.navigate({ url: 'https://other.test/' }), undefined);
});

test('activation deletes older MapR caches and preserves the current and unrelated caches', async () => {
    const app = worker();
    await app.lifecycle('install');
    await app.lifecycle('activate');
    assert.deepEqual(app.deleted, ['mapr-pwa-v0']);
});
