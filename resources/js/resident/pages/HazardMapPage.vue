<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Select from 'primevue/select';
import Slider from 'primevue/slider';
import ToggleSwitch from 'primevue/toggleswitch';
import MapIcon from '../../shared/components/MapIcon.vue';

const apiBase = inject('apiBase');
const mapboxToken = inject('mapboxToken');
const mapboxStyle = inject('mapboxStyle');
const mapEl = ref(null);
const mapStage = ref(null);
let stageAnimation = null;
const layersButton = ref(null);
const panelClose = ref(null);
const catalog = ref(null);
const activeId = ref('');
const opacity = ref(0.45);
const streetsStyle = 'mapbox://styles/mapbox/streets-v12';
const satelliteStyle = 'mapbox://styles/mapbox/satellite-streets-v12';
const satellite = ref((mapboxStyle || satelliteStyle).includes('satellite'));
const wide = ref(false);
const sheet = ref(window.matchMedia('(max-width: 767px)').matches);
const panelOpen = ref(!window.matchMedia('(max-width: 1023px)').matches);
const sheetTall = ref(false);
const loading = ref(true);
const locating = ref(false);
const layerError = ref('');
const pointsError = ref('');
const locationMessage = ref('');
const hazardMessage = ref('');
const selectedHazard = ref(null);
const selectedPlace = ref(null);
const nearestButton = ref(null);
const siteClose = ref(null);
const siteExpanded = ref(true);
const routing = ref(false);
const finding = ref(false);
const routeError = ref('');
const routeResult = ref(null);
const nearestSummary = ref('');
const straightLineMeters = ref(null);
const travelMode = ref('walking');
const sitesUnavailable = ref('');
const points = ref([]);
const boundaries = ref([]);
const rivers = ref(null);
const showPoints = ref(true);
const showBoundaries = ref(false);
const showRivers = ref(false);
const areaPsgc = ref('');
const severities = ref([]);
const sections = ref({ hazard: true, points: true, more: false, legend: false, about: false });
const pickerId = ref(null);
const sitePicker = ref(null);
const position = ref(null);
let map = null;
let marker = null;
let observer = null;
let accuracy = null;
let routeGeometry = null;
let started = false;
let layerToken = 0;
let routeToken = 0;
let locationRequest = 0;
let cancelLocation = null;
const locationAccuracyLimit = 100;

const activeLayer = computed(() => catalog.value?.layers?.find((layer) => layer.id === activeId.value) || null);
const note = computed(() => catalog.value?.note || 'These layers show mapped hazard areas, not live disaster conditions. Follow current CDRRMO advisories.');
const areas = computed(() => catalog.value?.areas || []);
const areaName = computed(() => areas.value.find((area) => area.psgc === areaPsgc.value)?.name || 'Tandag City');
const listedPoints = computed(() => points.value.filter((feature) => feature.properties?.category === 'evacuation'));
const areaPoints = computed(() => listedPoints.value.filter((feature) => pointInSelectedArea(feature)));
const siteAreas = computed(() => {
    const present = new Set();
    listedPoints.value.forEach((feature) => {
        const [lng, lat] = feature.geometry?.coordinates || [];
        const match = boundaries.value.find((boundary) => featureContains(boundary, lng, lat));
        if (match?.properties?.psgc) present.add(match.properties.psgc);
    });
    return areas.value.filter((area) => present.has(area.psgc));
});
const hazardLayerOptions = computed(() => (catalog.value?.layers || []).map((layer) => ({
    label: layer.name,
    value: layer.id,
})));
const areaOptions = computed(() => siteAreas.value.map((area) => ({
    label: area.name,
    value: area.psgc,
})));
const activeLayerSelection = computed({
    get: () => activeId.value || null,
    set: (value) => { activeId.value = value ?? ''; },
});
const areaSelection = computed({
    get: () => areaPsgc.value || null,
    set: (value) => { areaPsgc.value = value ?? ''; },
});
const filteredPoints = computed(() => (showPoints.value ? areaPoints.value : []));
const siteOptions = computed(() => areaPoints.value
    .map((feature) => ({
        id: String(feature.id || feature.properties?.id || ''),
        name: feature.properties?.name || 'Unnamed site',
        barangay: siteBarangayName(feature),
        feature,
    }))
    .filter((option) => option.id)
    .sort((left, right) => left.name.localeCompare(right.name, 'en', { sensitivity: 'base' })));
const siteBarangay = computed(() => {
    const coordinates = selectedPlace.value?.coordinates;
    if (!coordinates) return selectedPlace.value?.source_barangay || '';
    const match = boundaries.value.find((feature) => featureContains(feature, coordinates[0], coordinates[1]));
    return match?.properties?.name || selectedPlace.value?.source_barangay || '';
});
const externalDirections = computed(() => {
    const coordinates = selectedPlace.value?.coordinates;
    if (!position.value || !coordinates) return '';
    const mode = travelMode.value === 'driving' ? 'driving' : 'walking';
    return `https://www.google.com/maps/dir/?api=1&origin=${position.value.lat},${position.value.lng}&destination=${coordinates[1]},${coordinates[0]}&travelmode=${mode}`;
});
const siteSummary = computed(() => {
    const availability = selectedPlace.value?.availability || 'Availability not confirmed.';
    if (routeResult.value?.distance) return `${formatTrip(routeResult.value.distance, routeResult.value.duration)} · ${availability}`;
    if (straightLineMeters.value) return `Nearest by straight-line distance, about ${formatDistance(straightLineMeters.value)}. ${availability}`;
    return availability;
});
const statusAnnouncement = computed(() => [
    locating.value ? 'Finding your location…' : '',
    finding.value ? 'Finding the nearest listed evacuation site…' : '',
    routing.value ? 'Getting directions…' : '',
    locationMessage.value,
    nearestSummary.value,
    routeError.value,
].filter(Boolean).join(' '));
const activeCount = computed(() => {
    let count = activeId.value ? 1 : 0;
    if (showPoints.value) count += 1;
    if (areaPsgc.value) count += 1;
    if (showBoundaries.value) count += 1;
    if (showRivers.value) count += 1;
    return count;
});
const filtersChanged = computed(() => {
    const legend = (activeLayer.value?.legend || []).map((item) => item.value);
    const severityChanged = activeId.value !== '' && severities.value.slice().sort().join('|') !== legend.slice().sort().join('|');
    return areaPsgc.value !== ''
        || activeId.value !== ''
        || Number(opacity.value) !== 0.45
        || showPoints.value !== true
        || showBoundaries.value
        || showRivers.value
        || severityChanged;
});
const siteCountLabel = computed(() => {
    const count = areaPoints.value.length;
    return `${count} listed ${count === 1 ? 'site' : 'sites'}`;
});
const siteListMessage = computed(() => {
    if (sitesUnavailable.value) return sitesUnavailable.value;
    if (!showPoints.value) return 'Evacuation sites are hidden.';
    if (areaPoints.value.length) return '';
    if (areaPsgc.value) return `No listed evacuation sites in ${areaName.value}.`;
    return 'No listed evacuation sites are inside the available map coverage.';
});

function emptyCollection() {
    return { type: 'FeatureCollection', features: [] };
}

function formatDistance(meters) {
    if (!Number.isFinite(meters)) return '';
    if (meters < 1000) return `${Math.round(meters)} m`;
    return `${(meters / 1000).toFixed(1)} km`;
}

function formatDuration(seconds) {
    if (!Number.isFinite(seconds)) return '';
    const minutes = Math.round(seconds / 60);
    if (minutes < 1) return 'less than a minute';
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    return `${hours} hr ${minutes % 60} min`;
}

function formatTrip(meters, seconds) {
    return [formatDistance(meters), formatDuration(seconds)].filter(Boolean).join(' · ');
}

function circleSamples(lng, lat, meters) {
    if (!meters || meters < 1) return [];
    const latDelta = meters / 111320;
    const lngDelta = meters / (111320 * Math.cos((lat * Math.PI) / 180));
    return [[lng + lngDelta, lat], [lng - lngDelta, lat], [lng, lat + latDelta], [lng, lat - latDelta]];
}

function ringContains(ring, lng, lat) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        const intersects = ((yi > lat) !== (yj > lat)) && (lng < ((xj - xi) * (lat - yi)) / ((yj - yi) || 1e-12) + xi);
        if (intersects) inside = !inside;
    }
    return inside;
}

function featureContains(feature, lng, lat) {
    const polygons = feature.geometry?.type === 'Polygon'
        ? [feature.geometry.coordinates]
        : (feature.geometry?.type === 'MultiPolygon' ? feature.geometry.coordinates : []);
    return polygons.some((polygon) => ringContains(polygon[0] || [], lng, lat) && !polygon.slice(1).some((hole) => ringContains(hole, lng, lat)));
}

function categoriesAt(collection, lng, lat) {
    return [...new Set((collection?.features || []).filter((feature) => featureContains(feature, lng, lat)).map((feature) => feature.properties?.category || 'Unclassified'))];
}

function insideBounds(bounds, lng, lat) {
    if (!bounds) return false;
    return lng >= bounds[0][0] && lng <= bounds[1][0] && lat >= bounds[0][1] && lat <= bounds[1][1];
}

function siteBarangayName(feature) {
    const [lng, lat] = feature?.geometry?.coordinates || [];
    const match = boundaries.value.find((boundary) => featureContains(boundary, lng, lat));
    return match?.properties?.name || feature?.properties?.source_barangay || '';
}

function pointInSelectedArea(feature) {
    if (!areaPsgc.value) return true;
    const boundary = boundaries.value.find((item) => item.properties?.psgc === areaPsgc.value);
    if (!boundary) return true;
    const [lng, lat] = feature.geometry?.coordinates || [];
    return featureContains(boundary, lng, lat);
}

function fitPadding() {
    const panel = panelOpen.value && !sheet.value ? 390 : 48;
    let bottom = 56;
    if (sheet.value && panelOpen.value) bottom = Math.round(window.innerHeight * (sheetTall.value ? 0.62 : 0.42));
    else if (sheet.value && selectedPlace.value) bottom = siteExpanded.value ? Math.round(window.innerHeight * 0.52) : 148;
    else if (selectedPlace.value) bottom = siteExpanded.value ? 230 : 108;
    return { top: 88, left: 128, right: panel, bottom };
}

function markerImage(selected) {
    const canvas = document.createElement('canvas');
    canvas.width = selected ? 112 : 88;
    canvas.height = canvas.width;
    const context = canvas.getContext('2d');
    const center = canvas.width / 2;
    const radius = selected ? 46 : 36;
    context.shadowColor = 'rgba(15, 23, 42, 0.35)';
    context.shadowBlur = 8;
    context.shadowOffsetY = 2;
    context.fillStyle = selected ? '#9a3412' : '#b45309';
    context.beginPath();
    context.arc(center, center, radius, 0, Math.PI * 2);
    context.fill();
    context.shadowColor = 'transparent';
    context.lineWidth = 6;
    context.strokeStyle = '#fff';
    context.stroke();
    context.strokeStyle = '#fff';
    context.lineWidth = 3;
    context.lineJoin = 'round';
    context.strokeRect(center - 12, center - 4, 24, 20);
    context.beginPath();
    context.moveTo(center - 16, center - 4);
    context.lineTo(center, center - 16);
    context.lineTo(center + 16, center - 4);
    context.stroke();
    return context.getImageData(0, 0, canvas.width, canvas.height);
}

function addMarkerImages() {
    if (!map) return;
    if (!map.hasImage('evacuation')) map.addImage('evacuation', markerImage(false), { pixelRatio: 2 });
    if (!map.hasImage('evacuation-selected')) map.addImage('evacuation-selected', markerImage(true), { pixelRatio: 2 });
}

async function loadCatalog() {
    try {
        catalog.value = (await window.axios.get(`${apiBase}/map/hazards`)).data;
        if (activeId.value && !catalog.value.layers?.some((layer) => layer.id === activeId.value)) {
            activeId.value = '';
        }
        severities.value = (activeLayer.value?.legend || []).map((item) => item.value);
    } catch (exception) {
        catalog.value = { note: note.value, layers: [], initial_bounds: null, areas: [], critical_points: null };
        layerError.value = exception.response?.data?.message || 'Hazard layers are not available.';
    }
}

async function loadLayer(id) {
    if (!id) return null;
    if (loadLayer.cache.has(id)) return loadLayer.cache.get(id);
    const collection = (await window.axios.get(`${apiBase}/map/hazards/${id}`)).data;
    loadLayer.cache.set(id, collection);
    return collection;
}
loadLayer.cache = new Map();

async function loadCollection(path, tokenRef) {
    const token = ++tokenRef.current;
    const response = await window.axios.get(`${apiBase}${path}`);
    if (token !== tokenRef.current) return null;
    return response.data;
}
const placeRequest = { current: 0 };
const boundaryRequest = { current: 0 };
const riverRequest = { current: 0 };

function paint() {
    if (!map?.getLayer('hazard-fill')) return;
    const visible = activeId.value ? 'visible' : 'none';
    map.setLayoutProperty('hazard-fill', 'visibility', visible);
    map.setLayoutProperty('hazard-line', 'visibility', visible);
    const pairs = (activeLayer.value?.legend || []).flatMap((item) => [item.value, item.color]);
    const color = pairs.length ? ['match', ['get', 'category'], ...pairs, '#94a3b8'] : '#94a3b8';
    map.setPaintProperty('hazard-fill', 'fill-color', color);
    map.setPaintProperty('hazard-fill', 'fill-opacity', Number(opacity.value));
    map.setPaintProperty('hazard-line', 'line-color', color);
    const legend = activeLayer.value?.legend || [];
    const subset = severities.value.length > 0 && severities.value.length < legend.length;
    const filter = subset ? ['in', ['get', 'category'], ['literal', [...severities.value]]] : null;
    map.setFilter('hazard-fill', filter);
    map.setFilter('hazard-line', filter);
}

function applyReferenceVisibility() {
    if (!map?.getLayer('boundary-line')) return;
    const showSelected = Boolean(areaPsgc.value) && !showBoundaries.value;
    map.setLayoutProperty('boundary-line', 'visibility', (showBoundaries.value || showSelected) ? 'visible' : 'none');
    map.setFilter('boundary-line', showSelected ? ['==', ['get', 'psgc'], areaPsgc.value] : null);
    if (map.getLayer('river-fill')) {
        const riversVisible = showRivers.value ? 'visible' : 'none';
        map.setLayoutProperty('river-fill', 'visibility', riversVisible);
        map.setLayoutProperty('river-line', 'visibility', riversVisible);
    }
}

async function showActiveLayer() {
    if (!map?.getSource('hazard')) return;
    const token = ++layerToken;
    if (!activeId.value) {
        map.getSource('hazard').setData(emptyCollection());
        paint();
        loading.value = false;
        return;
    }
    layerError.value = '';
    loading.value = true;
    try {
        const collection = await loadLayer(activeId.value);
        if (token !== layerToken || !map?.getSource('hazard')) return;
        map.getSource('hazard').setData(collection);
        paint();
        selectedHazard.value = null;
    } catch (exception) {
        if (token !== layerToken || !map?.getSource('hazard')) return;
        map.getSource('hazard').setData(emptyCollection());
        layerError.value = exception.response?.data?.message || 'This hazard layer is not available.';
    } finally {
        if (token === layerToken) loading.value = false;
    }
}

async function showPlaces() {
    pointsError.value = '';
    sitesUnavailable.value = '';
    try {
        const collection = await loadCollection('/map/evacuation-sites', placeRequest);
        if (!collection) return;
        points.value = (collection.features || []).filter((feature) => feature.properties?.category === 'evacuation');
        sitesUnavailable.value = points.value.length ? '' : (collection.message || 'No verified evacuation-site list is available.');
    } catch (exception) {
        points.value = [];
        pointsError.value = exception.response?.data?.message || 'No verified evacuation-site list is available.';
    }
}

async function loadBoundaries() {
    try {
        const collection = await loadCollection('/map/boundaries', boundaryRequest);
        if (!collection) return;
        boundaries.value = collection.features || [];
        map?.getSource('boundaries')?.setData(collection);
        applyReferenceVisibility();
    } catch {
        boundaries.value = [];
    }
}

async function showRiverLayer() {
    try {
        const collection = await loadCollection('/map/rivers', riverRequest);
        if (!collection) return;
        rivers.value = collection;
        map?.getSource('rivers')?.setData(collection);
        applyReferenceVisibility();
    } catch {
        rivers.value = emptyCollection();
    }
}

function syncPlaces() {
    map?.getSource('places')?.setData({ type: 'FeatureCollection', features: filteredPoints.value });
}

function addMapLayers() {
    if (!map || map.getSource('hazard')) return;
    addMarkerImages();
    map.addSource('hazard', { type: 'geojson', data: emptyCollection() });
    map.addLayer({ id: 'hazard-fill', type: 'fill', source: 'hazard', paint: { 'fill-color': '#bfdbfe', 'fill-opacity': Number(opacity.value) } });
    map.addLayer({ id: 'hazard-line', type: 'line', source: 'hazard', paint: { 'line-color': '#1e3a8a', 'line-width': 1 } });
    map.addSource('boundaries', { type: 'geojson', data: emptyCollection() });
    map.addLayer({ id: 'boundary-line', type: 'line', source: 'boundaries', layout: { visibility: 'none' }, paint: { 'line-color': '#123b70', 'line-width': 1.5 } });
    map.addSource('rivers', { type: 'geojson', data: rivers.value || emptyCollection() });
    map.addLayer({ id: 'river-fill', type: 'fill', source: 'rivers', layout: { visibility: 'none' }, paint: { 'fill-color': '#38bdf8', 'fill-opacity': 0.35 } });
    map.addLayer({ id: 'river-line', type: 'line', source: 'rivers', layout: { visibility: 'none' }, paint: { 'line-color': '#0369a1', 'line-width': 1.25 } });
    map.addSource('route', { type: 'geojson', data: routeGeometry ? { type: 'Feature', geometry: routeGeometry, properties: {} } : emptyCollection() });
    map.addLayer({ id: 'route-casing', type: 'line', source: 'route', paint: { 'line-color': '#ffffff', 'line-width': 8 } });
    map.addLayer({ id: 'route-line', type: 'line', source: 'route', paint: { 'line-color': '#9a3412', 'line-width': 4 } });
    map.addSource('places', {
        type: 'geojson',
        data: { type: 'FeatureCollection', features: filteredPoints.value },
        cluster: true,
        clusterRadius: 50,
        clusterMaxZoom: 16,
    });
    map.addLayer({
        id: 'place-clusters',
        type: 'circle',
        source: 'places',
        filter: ['has', 'point_count'],
        paint: { 'circle-color': '#9a3412', 'circle-radius': 22, 'circle-stroke-width': 3, 'circle-stroke-color': '#fff' },
    });
    map.addLayer({
        id: 'place-count',
        type: 'symbol',
        source: 'places',
        filter: ['has', 'point_count'],
        layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-size': 13, 'text-font': ['DIN Pro Medium', 'Arial Unicode MS Regular'] },
        paint: { 'text-color': '#fff' },
    });
    map.addLayer({
        id: 'place-points',
        type: 'symbol',
        source: 'places',
        filter: ['!', ['has', 'point_count']],
        layout: {
            'icon-image': ['case', ['==', ['get', 'id'], selectedPlace.value?.id || ''], 'evacuation-selected', 'evacuation'],
            'icon-size': 1,
            'icon-allow-overlap': true,
        },
    });
    map.addLayer({
        id: 'place-labels',
        type: 'symbol',
        source: 'places',
        minzoom: 14,
        filter: ['all', ['!', ['has', 'point_count']], ['!=', ['get', 'id'], selectedPlace.value?.id || '']],
        layout: {
            'text-field': ['get', 'name'],
            'text-size': 12,
            'text-font': ['DIN Pro Medium', 'Arial Unicode MS Regular'],
            'text-offset': [0, 1.45],
            'text-anchor': 'top',
            'text-max-width': 10,
            'text-optional': true,
            'text-allow-overlap': false,
            'text-padding': 6,
        },
        paint: { 'text-color': '#1e293b', 'text-halo-color': '#fff', 'text-halo-width': 1.5 },
    });
    map.addLayer({
        id: 'place-label-selected',
        type: 'symbol',
        source: 'places',
        filter: ['==', ['get', 'id'], selectedPlace.value?.id || ''],
        layout: {
            'text-field': ['get', 'name'],
            'text-size': 13,
            'text-font': ['DIN Pro Medium', 'Arial Unicode MS Regular'],
            'text-offset': [0, 1.6],
            'text-anchor': 'top',
            'text-max-width': 12,
            'text-allow-overlap': true,
        },
        paint: { 'text-color': '#7c2d12', 'text-halo-color': '#fff', 'text-halo-width': 1.5 },
    });
    map.addSource('accuracy', { type: 'geojson', data: accuracy || emptyCollection() });
    map.addLayer({ id: 'accuracy-fill', type: 'fill', source: 'accuracy', paint: { 'fill-color': '#2563eb', 'fill-opacity': 0.15 } });
    map.addLayer({ id: 'accuracy-line', type: 'line', source: 'accuracy', paint: { 'line-color': '#2563eb', 'line-width': 1.5 } });
    paint();
    applyReferenceVisibility();
    const current = loadLayer.cache.get(activeId.value);
    if (current) map.getSource('hazard').setData(current);
    if (boundaries.value.length) map.getSource('boundaries').setData({ type: 'FeatureCollection', features: boundaries.value });
    if (position.value) marker?.addTo(map);
}

function accuracyPolygon(lng, lat, meters) {
    const steps = 64;
    const ring = [];
    for (let step = 0; step <= steps; step += 1) {
        const angle = (step / steps) * Math.PI * 2;
        const latDelta = (meters / 111320) * Math.sin(angle);
        const lngDelta = (meters / (111320 * Math.cos((lat * Math.PI) / 180))) * Math.cos(angle);
        ring.push([lng + lngDelta, lat + latDelta]);
    }
    return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [ring] }, properties: {} };
}

function checkHazard() {
    if (!position.value) {
        hazardMessage.value = 'Use your location before checking the hazard there.';
        return;
    }
    const { lng, lat, meters } = position.value;
    const layer = activeLayer.value;
    if (!activeId.value) {
        hazardMessage.value = 'Select a hazard layer before checking this location.';
        return;
    }
    if (loading.value) {
        hazardMessage.value = 'The hazard layer is still loading.';
        return;
    }
    if (layerError.value) {
        hazardMessage.value = 'This hazard layer is unavailable.';
        return;
    }
    const collection = loadLayer.cache.get(activeId.value);
    if (!layer || !collection) {
        hazardMessage.value = 'The hazard layer is still loading.';
        return;
    }
    if (!insideBounds(layer.bounds, lng, lat)) {
        hazardMessage.value = `This position is outside the available ${layer.name} dataset coverage.`;
        return;
    }
    const found = new Set(categoriesAt(collection, lng, lat));
    const nearby = circleSamples(lng, lat, meters).flatMap((point) => categoriesAt(collection, point[0], point[1]));
    const uncertain = nearby.some((category) => !found.has(category)) || (found.size === 0 && nearby.length > 0);
    const labels = [...found, ...nearby.filter((category) => !found.has(category))];
    if (labels.length === 0) {
        hazardMessage.value = `No ${layer.name} polygon in the loaded dataset covers this point. That does not mean the location is safe.`;
        return;
    }
    const list = labels.join(', ');
    hazardMessage.value = uncertain
        ? `The device accuracy crosses a ${layer.name} boundary, so this reading is approximate: ${list}.`
        : `${layer.name} at this approximate location: ${list}.`;
}

function locationElement() {
    const element = document.createElement('div');
    element.className = 'resident-map-location';
    element.setAttribute('aria-hidden', 'true');
    return element;
}

function applyLocation(result, moveCamera) {
    const { longitude, latitude, accuracy: meters } = result.coords;
    const precise = meters <= locationAccuracyLimit;
    // A broad laptop/network estimate is an area, not an exact location or route origin.
    position.value = precise ? { lng: longitude, lat: latitude, meters } : null;
    if (precise) marker?.setLngLat([longitude, latitude]).addTo(map);
    else marker?.remove();
    accuracy = { type: 'FeatureCollection', features: [accuracyPolygon(longitude, latitude, meters)] };
    map?.getSource('accuracy')?.setData(accuracy);
    if (moveCamera && map) {
        if (precise) map.easeTo({ center: [longitude, latitude], zoom: Math.max(map.getZoom(), 14), padding: fitPadding() });
        else {
            const ring = accuracy.features[0].geometry.coordinates[0];
            const longitudes = ring.map((point) => point[0]);
            const latitudes = ring.map((point) => point[1]);
            map.fitBounds([
                [Math.min(...longitudes), Math.min(...latitudes)],
                [Math.max(...longitudes), Math.max(...latitudes)],
            ], { padding: fitPadding(), duration: 500, maxZoom: 14 });
        }
    }
    const messages = [precise
        ? `Location accuracy: about ${Math.max(1, Math.round(meters))} meters.`
        : `Your browser can only estimate your location within about ${Math.round(meters)} meters. Your exact position is unavailable, so no location pin is shown. Enable precise location in your device settings or try a GPS-enabled phone.`];
    const coverage = catalog.value?.initial_bounds;
    if (coverage && !insideBounds(coverage, longitude, latitude)) {
        messages.push('This location is outside the available hazard map coverage. Hazard data is unavailable here.');
    }
    locationMessage.value = messages.join(' ');
    return precise;
}

function ensureLocation(moveCamera = true) {
    cancelLocation?.();
    locationMessage.value = '';
    hazardMessage.value = '';
    if (!navigator.geolocation) {
        locationMessage.value = 'Location is not available in this browser.';
        return Promise.resolve(null);
    }
    const token = ++locationRequest;
    locating.value = true;
    position.value = null;
    marker?.remove();
    accuracy = null;
    map?.getSource('accuracy')?.setData(emptyCollection());
    return new Promise((resolve) => {
        let watchId = null;
        let finished = false;
        let best = null;
        let timer = null;
        const finish = (result = null, message = '') => {
            if (finished) return;
            finished = true;
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            clearTimeout(timer);
            if (token === locationRequest) {
                cancelLocation = null;
                locating.value = false;
                locationMessage.value = message;
                if (result) {
                    resolve(applyLocation(result, moveCamera) ? position.value : null);
                    return;
                }
            }
            resolve(null);
        };
        cancelLocation = () => finish();
        // Give a coarse first fix time to improve, but bound the total wait even
        // when a provider keeps returning coarse readings or timeout callbacks.
        timer = setTimeout(() => finish(best, 'Location timed out. Enable precise location in your device settings and try again.'), 20000);
        try {
            watchId = navigator.geolocation.watchPosition((result) => {
                if (finished || token !== locationRequest) return;
                const { longitude, latitude, accuracy: meters } = result.coords || {};
                if (![longitude, latitude, meters, result.timestamp].every(Number.isFinite)
                    || Math.abs(longitude) > 180 || Math.abs(latitude) > 90 || meters <= 0
                    || Date.now() - result.timestamp > 30000 || result.timestamp > Date.now() + 1000) return;
                if (!best || meters < best.coords.accuracy) best = result;
                if (meters <= locationAccuracyLimit) finish(result);
                else locationMessage.value = `Location is only accurate to about ${Math.round(best.coords.accuracy)} meters. Waiting for a more precise location…`;
            }, (error) => {
                if (finished || token !== locationRequest) return;
                if (error.code === 1) finish(null, 'Location permission was denied. Allow precise location in your browser and device settings, then try again.');
                else if (!best) finish(null, error.code === 3
                    ? 'Location timed out. Enable precise location in your device settings and try again.'
                    : 'Your position is unavailable. Enable location services in your device settings and try again.');
            }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
            if (finished && watchId !== null) navigator.geolocation.clearWatch(watchId);
        } catch {
            finish(null, 'Location could not be requested. Check your browser and device location settings, then try again.');
        }
    });
}

function useLocation() {
    ensureLocation(true);
}

function drawRoute(geometry) {
    routeGeometry = geometry || null;
    const data = geometry
        ? { type: 'Feature', geometry, properties: {} }
        : emptyCollection();
    map?.getSource('route')?.setData(data);
}

function clearRouteLine() {
    routeGeometry = null;
    map?.getSource('route')?.setData(emptyCollection());
}

function fitRoute(geometry) {
    const coordinates = geometry?.coordinates || [];
    if (!map || coordinates.length < 2) return;
    const bounds = coordinates.reduce((current, point) => [
        [Math.min(current[0][0], point[0]), Math.min(current[0][1], point[1])],
        [Math.max(current[1][0], point[0]), Math.max(current[1][1], point[1])],
    ], [[coordinates[0][0], coordinates[0][1]], [coordinates[0][0], coordinates[0][1]]]);
    map.fitBounds(bounds, { padding: fitPadding(), duration: 500 });
}

function syncSelection() {
    if (!map?.getLayer('place-points')) return;
    const id = selectedPlace.value?.id || '';
    map.setLayoutProperty('place-points', 'icon-image', ['case', ['==', ['get', 'id'], id], 'evacuation-selected', 'evacuation']);
    if (map.getLayer('place-labels')) map.setFilter('place-labels', ['all', ['!', ['has', 'point_count']], ['!=', ['get', 'id'], id || '__none__']]);
    if (map.getLayer('place-label-selected')) map.setFilter('place-label-selected', ['==', ['get', 'id'], id || '__none__']);
}

async function getDirections() {
    if (!selectedPlace.value?.coordinates) return;
    const token = ++routeToken;
    finding.value = false;
    routeError.value = '';
    routing.value = true;
    const located = await ensureLocation(false);
    if (token !== routeToken) return;
    if (!located) {
        routing.value = false;
        return;
    }
    try {
        const { data } = await window.axios.post(`${apiBase}/map/routes`, {
            origin: [located.lng, located.lat],
            destination: selectedPlace.value.coordinates,
            profile: travelMode.value,
            client_token: token,
        });
        if (token !== routeToken || Number(data.client_token) !== token) return;
        routeResult.value = data;
        nearestSummary.value = '';
        straightLineMeters.value = null;
        if (data.geometry) {
            drawRoute(data.geometry);
            fitRoute(data.geometry);
        } else {
            clearRouteLine();
            routeError.value = data.message || 'No route was returned for this site.';
        }
    } catch (exception) {
        if (token !== routeToken) return;
        routeResult.value = null;
        clearRouteLine();
        routeError.value = exception.response?.data?.message || 'Directions are unavailable right now.';
    } finally {
        if (token === routeToken) routing.value = false;
    }
}

async function findNearest() {
    const token = ++routeToken;
    routing.value = false;
    finding.value = true;
    routeError.value = '';
    const restricted = Boolean(areaPsgc.value);
    const candidates = areaPoints.value;
    nearestSummary.value = restricted
        ? `Searching listed evacuation sites in ${areaName.value}.`
        : 'Searching listed evacuation sites in Tandag City.';
    const located = await ensureLocation(false);
    if (token !== routeToken) return;
    if (!located) {
        finding.value = false;
        nearestSummary.value = '';
        return;
    }
    if (restricted && candidates.length === 0) {
        finding.value = false;
        nearestSummary.value = '';
        routeError.value = `No listed evacuation sites are in ${areaName.value}.`;
        return;
    }
    try {
        const { data } = await window.axios.post(`${apiBase}/map/nearest-site`, {
            origin: [located.lng, located.lat],
            profile: travelMode.value,
            client_token: token,
            ...(restricted ? {
                site_ids: candidates.map((feature) => feature.id || feature.properties?.id).filter(Boolean),
                area_name: areaName.value,
            } : {}),
        });
        if (token !== routeToken || Number(data.client_token) !== token) return;
        const feature = data.site;
        selectedPlace.value = { ...feature.properties, id: feature.id, coordinates: feature.geometry.coordinates };
        siteExpanded.value = !sheet.value;
        const scope = data.restricted_to_barangay
            ? `Nearest-site search is limited to ${data.search_area}.`
            : `Search area: ${data.search_area}.`;
        nearestSummary.value = [data.summary, scope, data.closure_note].filter(Boolean).join(' ');
        straightLineMeters.value = data.method === 'straight-line' ? data.straight_line_meters : null;
        routeResult.value = data.route?.geometry ? data.route : null;
        routeError.value = data.route && !data.route.geometry ? (data.route.message || '') : '';
        if (data.route?.geometry) {
            drawRoute(data.route.geometry);
            fitRoute(data.route.geometry);
        } else {
            clearRouteLine();
            map?.easeTo({ center: feature.geometry.coordinates, zoom: Math.max(map.getZoom(), 15), padding: fitPadding() });
        }
        syncSelection();
        if (sheet.value) closePanel();
        await nextTick();
        if (!(sheet.value && panelOpen.value)) siteClose.value?.$el?.focus();
    } catch (exception) {
        if (token !== routeToken) return;
        nearestSummary.value = '';
        routeError.value = exception.response?.data?.message || 'The nearest listed site could not be found.';
    } finally {
        if (token === routeToken) finding.value = false;
    }
}

function clearRoute() {
    routeToken += 1;
    routing.value = false;
    finding.value = false;
    routeResult.value = null;
    routeError.value = '';
    clearRouteLine();
}

function setTravelMode(mode) {
    if (travelMode.value === mode) return;
    travelMode.value = mode;
    if (routeResult.value || routeError.value) getDirections();
}

function baseStyle() {
    return mapboxStyle && !String(mapboxStyle).includes('satellite') ? mapboxStyle : streetsStyle;
}

function activeStyle() {
    return satellite.value ? satelliteStyle : baseStyle();
}

function toggleStyle() {
    satellite.value = !satellite.value;
    map?.setStyle(activeStyle());
}

async function toggleWide() {
    const stage = mapStage.value;
    const before = stage?.getBoundingClientRect();
    stageAnimation?.cancel();
    wide.value = !wide.value;
    await nextTick();
    map?.resize();
    if (!stage || !before || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const after = stage.getBoundingClientRect();
    const styles = getComputedStyle(stage);
    stageAnimation = stage.animate([
        { transform: `translate(${before.left - after.left}px, ${before.top - after.top}px) scale(${before.width / after.width}, ${before.height / after.height})` },
        { transform: 'none' },
    ], {
        duration: parseFloat(styles.getPropertyValue('--resident-map-motion-duration')),
        easing: styles.getPropertyValue('--resident-map-motion-ease').trim(),
    });
}

function fitSelection(duration = 0) {
    const area = areas.value.find((item) => item.psgc === areaPsgc.value);
    const bounds = area?.bounds || catalog.value?.initial_bounds;
    if (bounds && map) map.fitBounds(bounds, { padding: fitPadding(), duration });
}

function chooseArea() {
    applyReferenceVisibility();
    fitSelection(500);
}

function toggleSeverity(value) {
    severities.value = severities.value.includes(value)
        ? severities.value.filter((item) => item !== value)
        : [...severities.value, value];
    paint();
}

function selectPlace(feature) {
    routeToken += 1;
    routing.value = false;
    finding.value = false;
    routeResult.value = null;
    routeError.value = '';
    nearestSummary.value = '';
    straightLineMeters.value = null;
    clearRouteLine();
    const coordinates = feature.geometry?.coordinates;
    selectedPlace.value = { ...feature.properties, id: feature.id || feature.properties?.id, coordinates };
    pickerId.value = String(feature.id || feature.properties?.id || '');
    sitePicker.value?.hide();
    siteExpanded.value = !sheet.value;
    syncSelection();
    if (coordinates) map?.easeTo({ center: coordinates, zoom: Math.max(map.getZoom(), 15), padding: fitPadding() });
    if (sheet.value) closePanel();
    nextTick(() => {
        if (!(sheet.value && panelOpen.value)) siteClose.value?.$el?.focus();
    });
}

function closeSite() {
    routeToken += 1;
    routing.value = false;
    finding.value = false;
    selectedPlace.value = null;
    pickerId.value = null;
    routeResult.value = null;
    routeError.value = '';
    nearestSummary.value = '';
    straightLineMeters.value = null;
    clearRouteLine();
    syncSelection();
    nextTick(() => (nearestButton.value?.$el || layersButton.value?.$el)?.focus());
}

function showAllSites() {
    areaPsgc.value = '';
    applyReferenceVisibility();
    fitSelection(500);
}

function resetMapView() {
    fitSelection(500);
}

function resetFilters() {
    activeId.value = '';
    opacity.value = 0.45;
    showPoints.value = true;
    showBoundaries.value = false;
    showRivers.value = false;
    areaPsgc.value = '';
    severities.value = [];
    hazardMessage.value = '';
    selectedHazard.value = null;
    applyReferenceVisibility();
    paint();
}

async function openPanel() {
    panelOpen.value = true;
    await nextTick();
    panelClose.value?.$el?.focus();
}

function closePanel() {
    panelOpen.value = false;
    nextTick(() => layersButton.value?.$el?.focus());
}

function chooseSite(value) {
    const option = siteOptions.value.find((site) => site.id === value);
    if (option) selectPlace(option.feature);
    else if (selectedPlace.value) closeSite();
}

function onKeydown(event) {
    if (event.key !== 'Escape') return;
    if (panelOpen.value) {
        closePanel();
        return;
    }
    if (selectedPlace.value && !(sheet.value && panelOpen.value)) {
        closeSite();
        return;
    }
    if (wide.value) toggleWide();
}

function onViewport() {
    sheet.value = window.matchMedia('(max-width: 767px)').matches;
    map?.resize();
}

async function createMap() {
    if (!mapboxToken || !mapEl.value) {
        loading.value = false;
        layerError.value = 'The map cannot be started in this browser.';
        return;
    }
    const mapboxgl = (await import('mapbox-gl')).default;
    await import('mapbox-gl/dist/mapbox-gl.css');
    mapboxgl.accessToken = mapboxToken;
    map = new mapboxgl.Map({
        container: mapEl.value,
        style: activeStyle(),
        center: [126.17, 9.05],
        zoom: 11,
        attributionControl: false,
    });
    map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'bottom-left');
    map.addControl(new mapboxgl.AttributionControl({ compact: true }), 'bottom-right');
    marker = new mapboxgl.Marker({ element: locationElement() });
    const startMap = async () => {
        try {
            // Mapbox GL 3 waits to draw GeoJSON until a sprite loads. Current
            // Mapbox styles ship an icon set instead, so that signal never
            // arrives and hazard polygons and markers stay invisible.
            map.style?.addImages?.(new Map(), true);
            addMapLayers();
            if (!map.getSource('hazard')) return;
            await Promise.all([showActiveLayer(), showPlaces(), loadBoundaries(), showRiverLayer()]);
            if (!started) {
                started = true;
                fitSelection();
            }
        } catch (error) {
            loading.value = false;
            layerError.value = error?.message || 'The map cannot be started in this browser.';
        }
    };
    map.on('style.load', startMap);
    if (map.isStyleLoaded()) startMap();
    map.on('click', 'place-clusters', (event) => {
        const feature = event.features?.[0];
        const clusterId = feature?.properties?.cluster_id;
        if (clusterId === undefined) return;
        map.getSource('places').getClusterExpansionZoom(clusterId, (error, zoom) => {
            if (error || !Number.isFinite(zoom)) return;
            map.easeTo({ center: feature.geometry.coordinates, zoom });
        });
    });
    map.on('click', 'place-points', (event) => {
        const feature = event.features?.[0];
        if (feature) selectPlace(feature);
    });
    map.on('click', (event) => {
        const places = map.queryRenderedFeatures(event.point, { layers: ['place-points', 'place-clusters'].filter((id) => map.getLayer(id)) });
        if (places.length) return;
        if (!map.getLayer('hazard-fill')) return;
        const hits = map.queryRenderedFeatures(event.point, { layers: ['hazard-fill'] });
        selectedHazard.value = hits[0]?.properties || null;
    });
    map.on('mouseenter', 'place-points', () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', 'place-points', () => { map.getCanvas().style.cursor = ''; });
    observer = new ResizeObserver(() => map?.resize());
    observer.observe(mapEl.value);
}

watch(activeId, () => {
    severities.value = (activeLayer.value?.legend || []).map((item) => item.value);
    hazardMessage.value = '';
    selectedHazard.value = null;
    showActiveLayer();
});
watch(opacity, paint);
watch(filteredPoints, syncPlaces);
watch([showBoundaries, showRivers, areaPsgc], applyReferenceVisibility);
watch([panelOpen, sheetTall, wide], () => nextTick(() => map?.resize()));

onMounted(async () => {
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('resize', onViewport);
    await loadCatalog();
    await createMap();
});
onBeforeUnmount(() => {
    cancelLocation?.();
    layerToken += 1;
    routeToken += 1;
    locationRequest += 1;
    placeRequest.current += 1;
    boundaryRequest.current += 1;
    riverRequest.current += 1;
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', onViewport);
    stageAnimation?.cancel();
    observer?.disconnect();
    map?.remove();
    map = null;
});
</script>

<template>
    <section class="resident-map">
        <header class="resident-map-heading">
            <div class="resident-map-heading-copy">
                <h2>Community hazard map</h2>
                <p>Know your surroundings. Explore hazards and find a listed evacuation site.</p>
            </div>
            <span class="resident-map-city"><MapIcon name="pin" /> Tandag City <span>Surigao del Sur</span></span>
        </header>
        <div ref="mapStage" class="resident-map-stage" :class="{ 'is-wide': wide, 'is-sheet': sheet && panelOpen, 'has-filters': panelOpen }">
            <div ref="mapEl" class="resident-map-canvas" role="application" aria-label="Community hazard map"></div>
            <div class="resident-map-tools">
                <Button
                    size="small"
                    ref="layersButton"
                    type="button"
                    class="resident-map-layers-button"
                    severity="secondary"
                    label="Layers"
                    :aria-pressed="panelOpen"
                    aria-label="Layers and filters"
                    title="Layers and filters"
                    :aria-expanded="panelOpen"
                    aria-controls="resident-map-filters"
                    @click="panelOpen ? closePanel() : openPanel()"
                >
                    <template #icon="{ class: iconClass }">
                        <MapIcon name="layers" :class="iconClass" />
                        <span v-if="activeCount" class="resident-map-count">{{ activeCount }}</span>
                    </template>
                </Button>
                <Button size="small" type="button" severity="secondary" aria-label="Use my location" title="Use my location" :aria-busy="locating" :disabled="locating" @click="useLocation">
                    <template #icon="{ class: iconClass }"><MapIcon name="focus" :class="iconClass" /></template>
                </Button>
                <Button size="small" ref="nearestButton" type="button" class="resident-map-nearest" label="Find nearest site" aria-label="Find nearest site" title="Find nearest site" :aria-busy="finding" :disabled="finding || locating" @click="findNearest">
                    <template #icon="{ class: iconClass }"><MapIcon name="pin-check" :class="iconClass" /></template>
                </Button>
                <div class="resident-map-basemap" role="group" aria-label="Base map style">
                    <Button size="small" type="button" severity="secondary" label="Map" :aria-pressed="!satellite" @click="satellite && toggleStyle()" />
                    <Button size="small" type="button" severity="secondary" label="Satellite" :aria-pressed="satellite" @click="!satellite && toggleStyle()" />
                </div>
                <Button size="small" type="button" severity="secondary" :aria-label="wide ? 'Restore map' : 'Expand map'" :title="wide ? 'Restore map' : 'Expand map'" @click="toggleWide">
                    <template #icon="{ class: iconClass }"><MapIcon name="collapse" :class="iconClass" /></template>
                </Button>
            </div>
            <div v-if="!selectedPlace && !(sheet && panelOpen)" class="resident-map-context">
                <span class="resident-map-context-icon"><MapIcon :name="activeLayer ? 'layers' : 'map'" /></span>
                <div><span class="resident-map-context-label">{{ activeLayer ? 'Viewing hazard layer' : 'Explore your community' }}</span><strong>{{ activeLayer?.name || areaName }}</strong></div>
            </div>
            <p class="resident-map-sr" role="status">{{ statusAnnouncement }}</p>
            <div class="resident-map-status-stack">
                <p v-if="loading" class="resident-map-status">Loading hazard layer…</p>
                <p v-if="layerError" class="resident-map-status" role="alert">{{ layerError }}</p>
                <p v-if="locating" class="resident-map-status">Finding your location…</p>
                <p v-else-if="locationMessage" class="resident-map-status" role="status">{{ locationMessage }}</p>
                <p v-if="hazardMessage" class="resident-map-status" role="status">{{ hazardMessage }}</p>
                <p v-else-if="selectedHazard" class="resident-map-status" role="status">{{ activeLayer?.name }}: {{ selectedHazard.category || 'Unclassified' }}.</p>
            </div>
            <!-- native-ok: map layer sheet stays in map stage to preserve viewport and touch behavior. -->
            <Transition name="resident-map-panel" @before-enter="element => { element.inert = false; }" @before-leave="element => { element.inert = true; }">
            <aside
                v-show="panelOpen"
                :inert="!panelOpen"
                id="resident-map-filters"
                class="resident-map-filters"
                :class="{ 'is-tall': sheetTall }"
                :role="sheet ? 'dialog' : 'region'"
                :aria-modal="sheet ? 'true' : undefined"
                aria-label="Map layers and filters"
                @touchmove.stop
                @wheel.stop
            >
                <span class="resident-map-handle" aria-hidden="true"></span>
                <header class="resident-map-filters-head">
                    <div class="resident-map-panel-title"><span class="resident-map-panel-icon"><MapIcon name="layers" /></span><div><h3>Layers &amp; places</h3><p>Choose what appears on your map.</p></div></div>
                    <Button size="small" ref="panelClose" type="button" severity="secondary" :aria-label="sheet ? 'Close filters' : 'Collapse filters'" @click="closePanel">
                        <template #icon="{ class: iconClass }"><MapIcon :name="sheet ? 'x' : 'chevron'" :class="[iconClass, { 'is-collapse': !sheet }]" /></template>
                    </Button>
                </header>
                <div class="resident-map-filters-body">
                    <section>
                        <Button size="small" type="button" class="resident-map-section" severity="secondary" text label="Hazard layer" iconPos="right" :aria-expanded="sections.hazard" aria-controls="resident-map-hazard" @click="sections.hazard = !sections.hazard">
                            <template #icon="{ class: iconClass }"><MapIcon name="chevron" :class="[iconClass, { 'is-open': sections.hazard }]" /></template>
                        </Button>
                        <Transition name="resident-map-disclosure"><div v-show="sections.hazard" :inert="!sections.hazard" id="resident-map-hazard" class="resident-map-disclosure">
                            <div class="resident-map-disclosure-clip"><div class="resident-map-stack">
                            <label id="resident-map-layer-label" for="resident-map-layer-select">Hazard layer</label>
                            <Select
                                overlayClass="resident-map-select-overlay"
                                size="small"
                                v-model="activeLayerSelection"
                                :options="hazardLayerOptions"
                                optionLabel="label"
                                optionValue="value"
                                inputId="resident-map-layer-select"
                                aria-labelledby="resident-map-layer-label"
                                placeholder="None"
                                showClear
                                fluid
                            />
                            <fieldset v-if="activeLayer?.legend?.length">
                                <legend>Severity</legend>
                                <label v-for="(item, index) in activeLayer.legend" :key="item.value">
                                    <Checkbox
                                        :inputId="`resident-map-severity-${index}`"
                                        :modelValue="severities.includes(item.value)"
                                        binary
                                        :aria-labelledby="`resident-map-severity-label-${index}`"
                                        @update:modelValue="toggleSeverity(item.value)"
                                    />
                                    <i :style="{ background: item.color }"></i><span :id="`resident-map-severity-label-${index}`">{{ item.label }}</span>
                                </label>
                            </fieldset>
                            <template v-if="activeId">
                                <Button size="small" type="button" class="resident-map-check" severity="secondary" variant="outlined" label="Check hazard at my location" :disabled="!position || locating" @click="checkHazard" />
                            </template>
                            <p v-else>Select a hazard layer before checking this location.</p>
                        </div></div></div></Transition>
                    </section>
                    <section>
                        <Button size="small" type="button" class="resident-map-section" severity="secondary" text label="Evacuation sites" iconPos="right" :aria-expanded="sections.points" aria-controls="resident-map-points" @click="sections.points = !sections.points">
                            <template #icon="{ class: iconClass }"><MapIcon name="chevron" :class="[iconClass, { 'is-open': sections.points }]" /></template>
                        </Button>
                        <Transition name="resident-map-disclosure"><div v-show="sections.points" :inert="!sections.points" id="resident-map-points" class="resident-map-disclosure">
                            <div class="resident-map-disclosure-clip"><div class="resident-map-stack">
                            <label class="resident-map-toggle resident-map-switch">
                                <span id="resident-map-show-points-label">Show evacuation sites</span>
                                <ToggleSwitch v-model="showPoints" inputId="resident-map-show-points" aria-labelledby="resident-map-show-points-label" />
                            </label>
                            <p v-if="pointsError" class="resident-map-inline-error" role="alert">
                                {{ pointsError }}
                                <Button size="small" type="button" class="resident-map-text" text label="Try again" @click="showPlaces" />
                            </p>
                            <template v-else-if="showPoints && !sitesUnavailable">
                                <label id="resident-map-area-label" for="resident-map-area-select">Evacuation-site area</label>
                                <Select
                                    overlayClass="resident-map-select-overlay"
                                    size="small"
                                    v-model="areaSelection"
                                    :options="areaOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    inputId="resident-map-area-select"
                                    aria-labelledby="resident-map-area-label"
                                    placeholder="All Tandag City"
                                    showClear
                                    fluid
                                    @change="chooseArea"
                                />
                                <Button size="small" v-if="areaPsgc" type="button" class="resident-map-text" text label="Show all Tandag sites" @click="showAllSites" />
                                <div class="resident-map-site-picker">
                                    <div class="resident-map-picker-head">
                                        <label id="resident-map-picker-label" for="resident-map-site-select">Find an evacuation site</label>
                                        <span class="resident-map-count-label">{{ siteCountLabel }}</span>
                                    </div>
                                    <Select
                                        overlayClass="resident-map-select-overlay"
                                        ref="sitePicker"
                                        v-model="pickerId"
                                        size="small"
                                        :options="siteOptions"
                                        optionLabel="name"
                                        optionValue="id"
                                        inputId="resident-map-site-select"
                                        aria-labelledby="resident-map-picker-label"
                                        placeholder="Select a listed site"
                                        filter
                                        :filterFields="['name', 'barangay']"
                                        filterPlaceholder="Search name or barangay"
                                        :pt="{ pcFilter: { root: { class: 'p-inputtext-sm', 'aria-label': 'Search evacuation sites' } } }"
                                        emptyFilterMessage="No listed sites match this search."
                                        emptyMessage="No listed evacuation sites are in this area."
                                        showClear
                                        resetFilterOnHide
                                        fluid
                                        @update:modelValue="chooseSite"
                                    >
                                        <template #option="{ option }">
                                            <div class="resident-map-site-option">
                                                <strong>{{ option.name }}</strong>
                                                <span>{{ option.barangay || 'Barangay is not listed' }}</span>
                                            </div>
                                        </template>
                                    </Select>
                                </div>
                                <p v-if="siteListMessage">{{ siteListMessage }}</p>
                            </template>
                            <p v-else>{{ siteListMessage }}</p>
                        </div></div></div></Transition>
                    </section>
                    <section>
                        <Button size="small" type="button" class="resident-map-section" severity="secondary" text label="More map options" iconPos="right" :aria-expanded="sections.more" aria-controls="resident-map-more" @click="sections.more = !sections.more">
                            <template #icon="{ class: iconClass }"><MapIcon name="chevron" :class="[iconClass, { 'is-open': sections.more }]" /></template>
                        </Button>
                        <Transition name="resident-map-disclosure"><div v-show="sections.more" :inert="!sections.more" id="resident-map-more" class="resident-map-disclosure">
                            <div class="resident-map-disclosure-clip"><div class="resident-map-stack">
                            <label v-if="activeId">Layer opacity
                                <Slider v-model="opacity" :min="0.15" :max="0.8" :step="0.05" aria-label="Layer opacity" class="w-full" />
                            </label>
                            <label class="resident-map-toggle">
                                <ToggleSwitch v-model="showBoundaries" inputId="resident-map-show-boundaries" aria-labelledby="resident-map-show-boundaries-label" />
                                <span id="resident-map-show-boundaries-label">Barangay boundaries</span>
                            </label>
                            <label class="resident-map-toggle">
                                <ToggleSwitch v-model="showRivers" inputId="resident-map-show-rivers" aria-labelledby="resident-map-show-rivers-label" />
                                <span id="resident-map-show-rivers-label">Rivers</span>
                            </label>
                            <div class="resident-map-style">
                                <Button size="small" type="button" variant="outlined" :severity="satellite ? 'secondary' : undefined" label="Map" :aria-pressed="!satellite" @click="satellite && toggleStyle()" />
                                <Button size="small" type="button" variant="outlined" :severity="satellite ? undefined : 'secondary'" label="Satellite" :aria-pressed="satellite" @click="!satellite && toggleStyle()" />
                            </div>
                        </div></div></div></Transition>
                    </section>
                    <section>
                        <Button size="small" type="button" class="resident-map-section" severity="secondary" text label="Legend" iconPos="right" :aria-expanded="sections.legend" aria-controls="resident-map-legend" @click="sections.legend = !sections.legend">
                            <template #icon="{ class: iconClass }"><MapIcon name="chevron" :class="[iconClass, { 'is-open': sections.legend }]" /></template>
                        </Button>
                        <Transition name="resident-map-disclosure"><div v-show="sections.legend" :inert="!sections.legend" id="resident-map-legend" class="resident-map-disclosure">
                            <div class="resident-map-disclosure-clip"><div class="resident-map-stack">
                            <p v-if="activeLayer"><strong>{{ activeLayer.name }}</strong></p>
                            <p v-else>No hazard layer is selected.</p>
                            <ul v-if="activeLayer" class="resident-map-legend">
                                <li v-for="item in activeLayer.legend" :key="item.value"><i :style="{ background: item.color }"></i>{{ item.label }}</li>
                            </ul>
                            <ul v-if="showPoints" class="resident-map-legend">
                                <li><span class="resident-map-swatch" style="background:#b45309"></span> Listed evacuation site</li>
                            </ul>
                        </div></div></div></Transition>
                    </section>
                    <section>
                        <Button size="small" type="button" class="resident-map-section" severity="secondary" text label="About this data" iconPos="right" :aria-expanded="sections.about" aria-controls="resident-map-about" @click="sections.about = !sections.about">
                            <template #icon="{ class: iconClass }"><MapIcon name="chevron" :class="[iconClass, { 'is-open': sections.about }]" /></template>
                        </Button>
                        <Transition name="resident-map-disclosure"><div v-show="sections.about" :inert="!sections.about" id="resident-map-about" class="resident-map-disclosure">
                            <div class="resident-map-disclosure-clip"><div class="resident-map-stack">
                            <p>Listed evacuation sites are the verified records available for Tandag City. A facility is included only when that record lists it as an evacuation site. The list may not include every site in the city.</p>
                            <p>Dataset date 2021-07-22. Availability is not confirmed unless a current source says the site is open.</p>
                            <p v-if="activeLayer">{{ activeLayer.name }}: {{ activeLayer.source }}. {{ activeLayer.date }}</p>
                        </div></div></div></Transition>
                    </section>
                    <Button size="small" v-if="sheet" type="button" class="resident-map-text" text :label="sheetTall ? 'Show more map' : 'Expand panel'" @click="sheetTall = !sheetTall" />
                    <Button size="small" type="button" class="resident-map-text" text label="Reset map view" @click="resetMapView" />
                    <Button size="small" v-if="filtersChanged" type="button" class="resident-map-text" text label="Reset filters" @click="resetFilters" />
                </div>
            </aside>
            </Transition>
            <!-- native-ok: site detail sheet stays in map stage to preserve viewport and touch behavior. -->
            <Transition name="resident-map-panel" @before-enter="element => { element.inert = false; }" @before-leave="element => { element.inert = true; }">
            <aside
                v-if="selectedPlace"
                v-show="!(sheet && panelOpen)"
                :inert="sheet && panelOpen"
                class="resident-map-detail"
                :class="{ 'is-expanded': siteExpanded, 'is-sheet': sheet }"
                :role="sheet ? 'dialog' : 'region'"
                :aria-modal="sheet ? 'true' : undefined"
                aria-label="Selected evacuation site"
            >
                <span v-if="sheet" class="resident-map-handle" aria-hidden="true"></span>
                <header class="resident-map-detail-head">
                    <MapIcon name="building" />
                    <h3>{{ selectedPlace.name }}</h3>
                    <Button size="small" type="button" severity="secondary" :aria-label="siteExpanded ? 'Collapse site details' : 'Expand site details'" @click="siteExpanded = !siteExpanded">
                        <template #icon="{ class: iconClass }"><MapIcon name="chevron" :class="[iconClass, { 'is-open': siteExpanded }]" /></template>
                    </Button>
                    <Button size="small" ref="siteClose" type="button" severity="secondary" aria-label="Close site" @click="closeSite">
                        <template #icon="{ class: iconClass }"><MapIcon name="x" :class="iconClass" /></template>
                    </Button>
                </header>
                <p v-if="!siteExpanded" class="resident-map-site-summary">{{ siteSummary }}</p>
                <Transition name="resident-map-disclosure"><div v-show="siteExpanded" class="resident-map-disclosure" :inert="!siteExpanded"><div class="resident-map-detail-body">
                    <p v-if="siteBarangay">Barangay {{ siteBarangay }}</p>
                    <p v-else>Barangay is not listed for this site.</p>
                    <p>Listed evacuation site</p>
                    <p>{{ selectedPlace.availability || 'Availability not confirmed.' }}</p>
                    <p v-if="nearestSummary">{{ nearestSummary }}</p>
                    <Button size="small" v-if="areaPsgc" type="button" class="resident-map-text" text label="Show all Tandag sites" @click="showAllSites" />
                    <p v-if="routeResult">{{ formatTrip(routeResult.distance, routeResult.duration) }}</p>
                    <p v-if="straightLineMeters && !routeResult">Nearest by straight-line distance, about {{ formatDistance(straightLineMeters) }}.</p>
                    <div class="resident-map-style" role="group" aria-label="Travel mode">
                        <Button size="small" type="button" variant="outlined" :severity="travelMode === 'walking' ? undefined : 'secondary'" label="Walking" :aria-pressed="travelMode === 'walking'" @click="setTravelMode('walking')" />
                        <Button size="small" type="button" variant="outlined" :severity="travelMode === 'driving' ? undefined : 'secondary'" label="Driving" :aria-pressed="travelMode === 'driving'" @click="setTravelMode('driving')" />
                    </div>
                    <Button size="small" type="button" class="resident-map-primary" :label="routing ? 'Getting directions…' : 'Get directions'" :disabled="routing || locating" @click="getDirections" />
                    <p v-if="routeError" class="resident-map-inline-error" role="alert">
                        {{ routeError }}
                        <Button size="small" type="button" class="resident-map-text" text label="Try again" @click="getDirections" />
                    </p>
                    <Button size="small" as="a" v-if="routeError && externalDirections" class="resident-map-text" text :href="externalDirections" target="_blank" rel="noopener noreferrer" label="Open directions in Google Maps" />
                    <p v-if="routeResult" class="resident-map-route-note">Suggested route only. Road conditions and site availability may change. Follow CDRRMO instructions.</p>
                    <p v-if="routeResult">Live road-closure data is not available.</p>
                    <details v-if="routeResult?.steps?.length">
                        <summary>Directions</summary>
                        <ol>
                            <li v-for="(step, index) in routeResult.steps" :key="index">{{ step.instruction }}</li>
                        </ol>
                    </details>
                    <Button size="small" v-if="routeResult || routeError" type="button" class="resident-map-text" text label="Clear route" @click="clearRoute" />
                    <details class="resident-map-site-info">
                        <summary>Site information</summary>
                        <p>Source: {{ selectedPlace.source }}</p>
                        <p>Dataset date: {{ selectedPlace.dataset_date }}</p>
                    </details>
                    <Button size="small" v-if="sheet" type="button" class="resident-map-text" text label="Show more map" @click="siteExpanded = false" />
                </div></div></Transition>
            </aside>
            </Transition>
        </div>
        <div class="resident-map-note"><MapIcon name="shield-check" /><p>{{ note }}</p></div>
    </section>
</template>
