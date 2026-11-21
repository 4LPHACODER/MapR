<script setup>
import { inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import mapboxgl from 'mapbox-gl';
import MapIcon from '../../../shared/components/MapIcon.vue';

const props = defineProps({
    boundaries: { type: Object, default: () => ({ type: 'FeatureCollection', features: [] }) },
    markers: { type: Object, default: () => ({ type: 'FeatureCollection', features: [] }) },
    height: { type: String, default: '' },
    interactive: { type: Boolean, default: true },
    selectedBarangayId: { type: [Number, String, null], default: null },
    showExpand: { type: Boolean, default: false },
});

const emit = defineEmits(['select-barangay', 'select-assessment', 'expand']);
const mapboxToken = inject('mapboxToken', '');
const mapboxStyle = inject('mapboxStyle', '');
const shell = ref(null);
const host = ref(null);
let map = null;
let resizeObserver = null;
let resizeTimer = null;

function hasGeometry() {
    return (props.boundaries?.features?.length || 0) > 0 || (props.markers?.features?.length || 0) > 0;
}

function boundsFrom(collection) {
    const bounds = new mapboxgl.LngLatBounds();
    let found = false;
    for (const feature of collection?.features || []) {
        const geometry = feature.geometry;
        if (!geometry) continue;
        if (geometry.type === 'Point') {
            bounds.extend(geometry.coordinates);
            found = true;
        } else if (geometry.type === 'Polygon') {
            geometry.coordinates[0]?.forEach((coord) => {
                bounds.extend(coord);
                found = true;
            });
        } else if (geometry.type === 'MultiPolygon') {
            geometry.coordinates.forEach((poly) => {
                poly[0]?.forEach((coord) => {
                    bounds.extend(coord);
                    found = true;
                });
            });
        }
    }
    return found ? bounds : null;
}

function combinedBounds() {
    const boundaryBounds = boundsFrom(props.boundaries);
    const markerBounds = boundsFrom(props.markers);
    if (boundaryBounds && markerBounds) {
        return boundaryBounds.extend(markerBounds);
    }
    return boundaryBounds || markerBounds;
}

function fitViewport(duration = 0) {
    if (!map) return;
    const bounds = combinedBounds();
    if (!bounds) return;
    map.fitBounds(bounds, {
        padding: props.interactive
            ? { top: 40, bottom: 40, left: 40, right: 40 }
            : { top: 32, bottom: 32, left: 32, right: 32 },
        maxZoom: props.interactive ? 14 : 15,
        duration,
    });
}

function paint(duration = 0) {
    if (!map) return;
    const boundarySource = map.getSource('field-boundaries');
    const markerSource = map.getSource('field-markers');
    if (boundarySource) boundarySource.setData(props.boundaries || { type: 'FeatureCollection', features: [] });
    if (markerSource) markerSource.setData(props.markers || { type: 'FeatureCollection', features: [] });
    fitViewport(duration);
}

function scheduleResizeAndFit() {
    if (!map) return;
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        if (!map) return;
        map.resize();
        fitViewport(0);
    }, 50);
}

function observeResize() {
    if (!shell.value || typeof ResizeObserver === 'undefined') return;
    resizeObserver?.disconnect();
    resizeObserver = new ResizeObserver(() => scheduleResizeAndFit());
    resizeObserver.observe(shell.value);
}

async function mountMap() {
    if (map || !host.value || !mapboxToken || !hasGeometry()) return;
    await nextTick();
    if (!host.value || map) return;

    mapboxgl.accessToken = mapboxToken;
    map = new mapboxgl.Map({
        container: host.value,
        style: mapboxStyle || 'mapbox://styles/mapbox/streets-v12',
        center: [126.23, 9.08],
        zoom: 11,
        interactive: props.interactive,
        attributionControl: false,
    });

    if (props.interactive) {
        map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-right');
    }

    map.on('load', () => {
        map.addSource('field-boundaries', { type: 'geojson', data: props.boundaries || { type: 'FeatureCollection', features: [] } });
        map.addLayer({
            id: 'field-boundary-fill',
            type: 'fill',
            source: 'field-boundaries',
            paint: { 'fill-color': '#0ea5e9', 'fill-opacity': 0.12 },
        });
        map.addLayer({
            id: 'field-boundary-line',
            type: 'line',
            source: 'field-boundaries',
            paint: { 'line-color': '#0284c7', 'line-width': 2.5 },
        });
        map.addSource('field-markers', { type: 'geojson', data: props.markers || { type: 'FeatureCollection', features: [] } });
        map.addLayer({
            id: 'field-marker-circle',
            type: 'circle',
            source: 'field-markers',
            paint: {
                'circle-radius': 5,
                'circle-color': '#b45309',
                'circle-stroke-width': 1,
                'circle-stroke-color': '#fff',
            },
        });
        map.resize();
        paint(0);
        observeResize();

        if (props.interactive) {
            map.on('click', 'field-boundary-fill', (event) => {
                const id = event.features?.[0]?.properties?.barangay_id;
                if (id) emit('select-barangay', Number(id));
            });
            map.on('click', 'field-marker-circle', (event) => {
                const id = event.features?.[0]?.properties?.field_assessment_id;
                if (id) emit('select-assessment', Number(id));
            });
            map.on('mouseenter', 'field-marker-circle', () => { map.getCanvas().style.cursor = 'pointer'; });
            map.on('mouseleave', 'field-marker-circle', () => { map.getCanvas().style.cursor = ''; });
        }
    });
}

onMounted(() => {
    mountMap();
});

watch(() => [props.boundaries, props.markers], async () => {
    if (!map && hasGeometry()) {
        await mountMap();
    } else {
        paint(props.interactive ? 400 : 0);
    }
}, { deep: true });

watch(() => props.selectedBarangayId, () => paint(props.interactive ? 400 : 0));

onBeforeUnmount(() => {
    clearTimeout(resizeTimer);
    resizeObserver?.disconnect();
    resizeObserver = null;
    map?.remove();
    map = null;
});

defineExpose({
    resetView() {
        scheduleResizeAndFit();
    },
});
</script>

<template>
    <div
        ref="shell"
        class="field-map-shell"
        :class="{ 'field-map-shell--sized': !!height }"
        :style="height ? { minHeight: height, height } : undefined"
    >
        <div v-if="!mapboxToken" class="field-map-unavailable">
            <strong>Map unavailable</strong>
            <span>Mapbox is not configured for this workspace.</span>
        </div>
        <div v-else-if="!hasGeometry()" class="field-map-unavailable">
            <strong>Geometry unavailable</strong>
            <span>Verified barangay geometry is not available for this selection.</span>
        </div>
        <template v-else>
            <div ref="host" class="field-map-canvas" />
            <button
                v-if="showExpand"
                type="button"
                class="field-map-expand"
                title="Expand map"
                aria-label="Expand map"
                @click.stop="emit('expand')"
            >
                <MapIcon name="external" />
            </button>
        </template>
    </div>
</template>

<style scoped>
.field-map-shell {
    position: relative;
    width: 100%;
    height: 100%;
    min-height: inherit;
    border-radius: 0.75rem;
    overflow: hidden;
    background: #e2e8f0;
}

.field-map-shell--sized {
    min-height: 0;
}

.field-map-canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.field-map-canvas :deep(.mapboxgl-canvas),
.field-map-canvas :deep(.mapboxgl-canvas-container) {
    width: 100% !important;
    height: 100% !important;
}

.field-map-unavailable {
    display: grid;
    place-items: center;
    justify-items: center;
    gap: 0.35rem;
    position: absolute;
    inset: 0;
    padding: 1rem;
    color: #64748b;
    text-align: center;
    font-size: 0.8125rem;
    background: linear-gradient(180deg, #f8fafc, #eef2f7);
}

.field-map-unavailable strong {
    color: #334155;
    font-size: 0.85rem;
}

.field-map-unavailable span {
    max-width: 18rem;
    line-height: 1.35;
}

.field-map-expand {
    position: absolute;
    top: 0.55rem;
    right: 0.55rem;
    z-index: 2;
    display: inline-grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border: 1px solid rgba(15, 23, 42, 0.12);
    border-radius: 0.5rem;
    background: rgba(255, 255, 255, 0.92);
    color: #123b70;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
}

.field-map-expand:hover {
    background: #fff;
}

.field-map-expand :deep(.cdrrmo-icon) {
    width: 1rem;
    height: 1rem;
}
</style>
