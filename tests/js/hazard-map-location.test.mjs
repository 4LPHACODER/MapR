import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { computed, nextTick, ref } from 'vue';

const filename = new URL('../../resources/js/resident/pages/HazardMapPage.vue', import.meta.url);
const { descriptor } = parse(readFileSync(filename, 'utf8'));
const { scriptSetupAst } = compileScript(descriptor, { id: 'hazard-map-location-test' });
let script = descriptor.scriptSetup.content;
for (const node of [...scriptSetupAst].reverse()) {
    if (node.type === 'ImportDeclaration') script = script.slice(0, node.start) + script.slice(node.end);
}

// Run the actual page logic with browser/map boundaries stubbed; no map tiles or GPS required.
function page() {
    const cameras = [];
    const markers = [];
    const accuracy = [];
    const watches = [];
    const cleared = [];
    const timers = new Map();
    let timerId = 0;
    let unmount;
    let success;
    let failure;
    const context = vm.createContext({
        computed, nextTick, ref,
        inject: () => null,
        onMounted: () => {},
        onBeforeUnmount: (callback) => { unmount = callback; },
        watch: () => {},
        setTimeout: (callback) => { timers.set(++timerId, callback); return timerId; },
        clearTimeout: (id) => timers.delete(id),
        window: { matchMedia: () => ({ matches: false }), removeEventListener: () => {} },
        navigator: { geolocation: {
            getCurrentPosition: (resolve, reject) => { success = resolve; failure = reject; },
            watchPosition: (resolve, reject, options) => {
                success = resolve;
                failure = reject;
                watches.push({ resolve, reject, options });
                return watches.length - 1;
            },
            clearWatch: (id) => cleared.push(id),
        } },
        mapStub: {
            getZoom: () => 11,
            easeTo: (options) => cameras.push(options),
            fitBounds: (bounds, options) => cameras.push({ bounds, ...options }),
            getSource: () => ({ setData: (data) => accuracy.push(data) }),
            remove: () => {},
        },
        markerStub: { setLngLat(coordinates) { markers.push(coordinates); return this; }, addTo() { return this; }, remove() {} },
    });
    vm.runInContext(script, context);
    vm.runInContext(`
        map = mapStub;
        marker = markerStub;
        catalog.value = { initial_bounds: [[126.1, 9.0], [126.3, 9.2]], layers: [] };
    `, context);
    return {
        run: (code) => vm.runInContext(code, context),
        locate: (longitude, latitude, meters = 20, timestamp = Date.now()) => success({ coords: { longitude, latitude, accuracy: meters }, timestamp }),
        fail: (code) => failure({ code }),
        expire: () => { for (const callback of [...timers.values()]) callback(); },
        unmount: () => unmount(),
        cameras, markers, accuracy, watches, cleared, timers,
    };
}

test('GPS outside hazard coverage updates the marker, position, accuracy and camera', async () => {
    const map = page();
    const pending = map.run('ensureLocation()');
    assert.equal(map.run('locating.value'), true);
    map.locate(125.6, 7.1);
    const location = await pending;
    assert.equal(location?.lng, 125.6);
    assert.equal(location?.lat, 7.1);
    assert.deepEqual(Array.from(map.markers.at(-1)), [125.6, 7.1]);
    assert.deepEqual(Array.from(map.cameras.at(-1).center), [125.6, 7.1]);
    assert.equal(map.accuracy.at(-1).features.length, 1);
    assert.equal(map.run('locating.value'), false);
    assert.match(map.run('locationMessage.value'), /outside.*coverage/i);
});

test('a new outside position replaces an earlier in-coverage position without moving the routing camera', async () => {
    const map = page();
    let pending = map.run('ensureLocation(false)');
    map.locate(126.2, 9.1);
    await pending;
    pending = map.run('ensureLocation(false)');
    map.locate(125.6, 7.1, 25);
    assert.equal((await pending)?.lng, 125.6);
    assert.equal(map.markers.length, 2);
    assert.equal(map.cameras.length, 0);
    assert.match(map.run('locationMessage.value'), /outside.*coverage/i);
    assert.match(map.run('locationMessage.value'), /25 meters/);
});

test('a subsequent in-coverage fix clears the coverage warning and retains accuracy feedback', async () => {
    const map = page();
    let pending = map.run('ensureLocation()');
    map.locate(125.6, 7.1);
    await pending;
    pending = map.run('ensureLocation()');
    map.locate(126.2, 9.1, 50);
    assert.equal((await pending).lng, 126.2);
    assert.doesNotMatch(map.run('locationMessage.value'), /outside/i);
    assert.match(map.run('locationMessage.value'), /50 meters/);
});

test('location works when catalog bounds are unavailable', async () => {
    const map = page();
    map.run('catalog.value = null');
    const pending = map.run('ensureLocation()');
    map.locate(125.6, 7.1);
    assert.equal((await pending).lng, 125.6);
    assert.match(map.run('locationMessage.value'), /20 meters/);
});

test('hazard lookup still reports missing coverage for an outside position', async () => {
    const map = page();
    map.run(`
        catalog.value.layers = [{ id: 'flood', name: 'Flood', bounds: catalog.value.initial_bounds }];
        activeId.value = 'flood';
        loading.value = false;
        loadLayer.cache.set('flood', { type: 'FeatureCollection', features: [] });
    `);
    const pending = map.run('ensureLocation()');
    map.locate(125.6, 7.1);
    await pending;
    map.run('checkHazard()');
    assert.match(map.run('hazardMessage.value'), /outside.*Flood.*coverage/i);
});

for (const [code, message] of [[1, /denied/], [2, /unavailable/], [3, /timed out/]]) {
    test(`GPS failure ${code} stops loading and displays recovery feedback`, async () => {
        const map = page();
        const pending = map.run('ensureLocation()');
        map.fail(code);
        assert.equal(await pending, null);
        assert.equal(map.run('locating.value'), false);
        assert.match(map.run('locationMessage.value'), message);
        assert.equal(map.markers.length, 0);
    });
}

test('a broad Bongtod estimate does not place a pin; a later precise Quezon fix does', async () => {
    const map = page();
    const pending = map.run('ensureLocation()');
    // Sample points within the project boundary dataset, not the user's actual coordinates.
    map.locate(126.19304355, 9.0828768, 4000);
    assert.equal(map.run('locating.value'), true);
    assert.equal(map.run('position.value'), null);
    assert.equal(map.markers.length, 0);
    map.locate(126.16, 9.065, 25);
    assert.equal((await pending).lng, 126.16);
    assert.deepEqual(Array.from(map.markers.at(-1)), [126.16, 9.065]);
    assert.equal(map.markers.length, 1);
    assert.equal(map.run('locating.value'), false);
    assert.deepEqual(map.cleared, [0]);
    assert.equal(map.timers.size, 0);
});

test('a laptop that only supplies a broad estimate shows uncertainty without an exact pin or route origin', async () => {
    const map = page();
    const pending = map.run('ensureLocation()');
    map.locate(126.19304355, 9.0828768, 4000);
    map.expire();
    assert.equal(await pending, null);
    assert.equal(map.run('position.value'), null);
    assert.equal(map.markers.length, 0);
    assert.equal(map.accuracy.at(-1).features.length, 1);
    assert.ok(map.cameras.at(-1).bounds);
    assert.match(map.run('locationMessage.value'), /4000 meters/);
    assert.match(map.run('locationMessage.value'), /exact.*unavailable/i);
    assert.deepEqual(map.cleared, [0]);
});

test('timeout retains the most accurate estimate instead of the last, worse sample', async () => {
    const map = page();
    const pending = map.run('ensureLocation()');
    map.locate(126.16, 9.065, 500);
    map.locate(126.19304355, 9.0828768, 4000);
    map.expire();
    await pending;
    assert.match(map.run('locationMessage.value'), /500 meters/);
    assert.doesNotMatch(map.run('locationMessage.value'), /4000 meters/);
});

test('stale or malformed fixes cannot place a location marker', async () => {
    const map = page();
    const pending = map.run('ensureLocation()');
    map.locate(126.19304355, 9.0828768, 20, Date.now() - 60000);
    map.locate(NaN, 9.065, 20);
    map.locate(126.16, 9.065, 0);
    assert.equal(map.markers.length, 0);
    assert.equal(map.run('locating.value'), true);
    map.locate(126.16, 9.065, 25);
    assert.equal((await pending).lng, 126.16);
});

test('navigation cancels acquisition and ignores late location callbacks', async () => {
    const map = page();
    const pending = map.run('ensureLocation()');
    map.unmount();
    assert.equal(await pending, null);
    map.locate(126.16, 9.065, 25);
    assert.equal(map.markers.length, 0);
    assert.deepEqual(map.cleared, [0]);
    assert.equal(map.timers.size, 0);
});

test('a new request settles the old one and ignores its late callback', async () => {
    const map = page();
    const first = map.run('ensureLocation()');
    const second = map.run('ensureLocation()');
    assert.equal(await first, null);
    map.watches[0].resolve({ coords: { longitude: 126.19304355, latitude: 9.0828768, accuracy: 20 }, timestamp: Date.now() });
    assert.equal(map.markers.length, 0);
    map.locate(126.16, 9.065, 25);
    assert.equal((await second).lng, 126.16);
    assert.deepEqual(map.cleared, [0, 1]);
});

test('a transient timeout after a coarse estimate still allows a precise fix to arrive', async () => {
    const map = page();
    const pending = map.run('ensureLocation()');
    map.locate(126.19304355, 9.0828768, 4000);
    map.fail(3);
    assert.equal(map.run('locating.value'), true);
    map.locate(126.16, 9.065, 25);
    assert.equal((await pending).lng, 126.16);
});

test('acquisition with no callbacks still stops at the overall deadline', async () => {
    const map = page();
    const pending = map.run('ensureLocation()');
    map.expire();
    assert.equal(await pending, null);
    assert.equal(map.run('locating.value'), false);
    assert.match(map.run('locationMessage.value'), /timed out/);
    assert.deepEqual(map.cleared, [0]);
});

test('nearest-site search does not submit a coarse estimate as the origin', async () => {
    const map = page();
    map.run('window.axios = { post() { throw new Error("A coarse location must not reach the API"); } }');
    const pending = map.run('findNearest()');
    map.locate(126.19304355, 9.0828768, 4000);
    map.expire();
    await pending;
    assert.equal(map.run('finding.value'), false);
    assert.equal(map.run('routeError.value'), '');
    assert.equal(map.run('position.value'), null);
});
