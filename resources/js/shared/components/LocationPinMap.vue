<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MapIcon from './MapIcon.vue';

const props = defineProps({
    token: { type: String, default: '' },
    styleUrl: { type: String, default: '' },
    latitude: { type: Number, default: null },
    longitude: { type: Number, default: null },
    centerLatitude: { type: Number, default: null },
    centerLongitude: { type: Number, default: null },
});
const emit = defineEmits(['confirm', 'clear']);

const mapEl = ref(null);
const expandButton = ref(null);
const pending = ref(null);
const notice = ref('');
const wide = ref(false);
let map = null;
let marker = null;
let observer = null;

const hasToken = props.token !== '';
const confirmed = computed(() => pair(props.latitude, props.longitude));
const status = computed(() => (confirmed.value && !pending.value ? 'Pin confirmed.' : 'Confirm the incident location'));

function pair(latitude, longitude) {
    if (latitude == null || longitude == null || Number.isNaN(Number(latitude)) || Number.isNaN(Number(longitude))) {
        return null;
    }
    return { latitude: Number(latitude), longitude: Number(longitude) };
}

function withinBounds(point) {
    return point.latitude >= 4 && point.latitude <= 15 && point.longitude >= 115 && point.longitude <= 130;
}

function centerPoint() {
    return pair(props.centerLatitude, props.centerLongitude);
}

function activePoint() {
    return pending.value || confirmed.value || centerPoint();
}

function placeMarker(point, zoom, animate) {
    if (!map || !marker || !point) return;
    const longitude = Number(point.longitude);
    const latitude = Number(point.latitude);
    if (!Number.isFinite(longitude) || !Number.isFinite(latitude)) return;
    marker.setLngLat([longitude, latitude]);
    if (!marker.getElement().isConnected) marker.addTo(map);
    if (!zoom) return;
    const view = { center: [longitude, latitude], zoom };
    if (animate) map.flyTo(view);
    else map.jumpTo(view);
}

function setPending(latitude, longitude) {
    const point = pair(latitude, longitude);
    if (!point) return;
    pending.value = point;
    emit('clear');
    placeMarker(point, 16, true);
    notice.value = '';
}

function confirmPending() {
    const point = activePoint();
    if (!point || !withinBounds(point)) {
        notice.value = 'Choose a point inside the mapped area.';
        return;
    }
    pending.value = null;
    emit('confirm', point);
    notice.value = '';
}

function useMyLocation() {
    if (!navigator.geolocation) {
        notice.value = 'Location is unavailable on this device. Tap the map to place the pin.';
        return;
    }
    notice.value = 'Finding your location…';
    navigator.geolocation.getCurrentPosition((position) => {
        setPending(position.coords.latitude, position.coords.longitude);
    }, () => {
        notice.value = 'Location was denied. Tap the map to place the pin.';
    }, { enableHighAccuracy: true, timeout: 10000 });
}

function showSavedPin() {
    const saved = confirmed.value;
    if (saved && !pending.value) {
        placeMarker(saved, 16, false);
        return;
    }
    if (pending.value) {
        placeMarker(pending.value, null, false);
        return;
    }
    const center = centerPoint();
    if (center) placeMarker(center, 16, false);
}

function fitMap() {
    if (!map || !mapEl.value) return;
    const box = mapEl.value.getBoundingClientRect();
    if (box.width < 1 || box.height < 1) return;
    map.resize();
    showSavedPin();
}

function toggleWide() {
    wide.value = !wide.value;
    nextTick(() => fitMap());
}

function onKeydown(event) {
    if (event.key !== 'Escape' || !wide.value) return;
    wide.value = false;
    nextTick(() => {
        fitMap();
        expandButton.value?.focus();
    });
}

onMounted(async () => {
    if (!hasToken || !mapEl.value) return;
    const mapboxgl = (await import('mapbox-gl')).default;
    await import('mapbox-gl/dist/mapbox-gl.css');
    mapboxgl.accessToken = props.token;
    const start = confirmed.value || centerPoint();
    map = new mapboxgl.Map({
        container: mapEl.value,
        style: props.styleUrl || 'mapbox://styles/mapbox/streets-v12',
        center: start ? [start.longitude, start.latitude] : [126.17, 9.08],
        zoom: start ? 16 : 11,
        attributionControl: false,
    });
    map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(new mapboxgl.AttributionControl({ compact: true }), 'bottom-right');
    marker = new mapboxgl.Marker({ color: '#123b70' });
    map.on('click', (event) => setPending(event.lngLat.lat, event.lngLat.lng));
    map.on('load', fitMap);
    observer = new ResizeObserver(() => fitMap());
    observer.observe(mapEl.value);
    if (mapEl.value.parentElement) observer.observe(mapEl.value.parentElement);
    showSavedPin();
    window.addEventListener('keydown', onKeydown);
});

watch(() => [props.latitude, props.longitude, props.centerLatitude, props.centerLongitude], showSavedPin);

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    observer?.disconnect();
    observer = null;
    map?.remove();
    map = null;
});
</script>

<template>
    <div class="pin-map" :class="{ 'is-wide': wide }">
        <div v-if="hasToken" class="pin-stage">
            <div ref="mapEl" class="pin-canvas" role="application" aria-label="Incident location map"></div>
            <button
                ref="expandButton"
                type="button"
                class="pin-expand"
                :aria-pressed="wide"
                :aria-label="wide ? 'Restore map' : 'Expand map'"
                :title="wide ? 'Restore map' : 'Expand map'"
                @click="toggleWide"
            >
                <MapIcon name="collapse" :class="{ 'is-restore': wide }" />
            </button>
        </div>
        <p v-else class="pin-fallback">The map is unavailable. Use My Location, then confirm the pin.</p>
        <div class="pin-actions">
            <button type="button" @click="useMyLocation"><MapIcon name="focus" /> Use My Location</button>
            <button type="button" class="is-primary" @click="confirmPending"><MapIcon name="pin-check" /> Confirm Pin</button>
        </div>
        <p class="pin-message" role="status">
            <MapIcon v-if="confirmed && !pending" name="circle-check" />
            {{ status }}
        </p>
        <p v-if="notice" class="pin-message">{{ notice }}</p>
    </div>
</template>
