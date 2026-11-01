<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const apiBase = inject('apiBase');
const mapboxToken = inject('mapboxToken');
const mapboxStyle = inject('mapboxStyle');
const mapEl = ref(null);
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
const panelOpen = ref(false);
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
const pickerOpen = ref(false);
const pickerQuery = ref('');
const pickerId = ref('');
const pickerIndex = ref(0);
const pickerRoot = ref(null);
const pickerInput = ref(null);
const pickerStyle = ref({});
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
const pickerMatches = computed(() => {
    const term = pickerQuery.value.trim().toLowerCase();
    if (!term) return siteOptions.value;
    return siteOptions.value.filter((option) => option.name.toLowerCase().includes(term) || option.barangay.toLowerCase().includes(term));
});
const pickerLabel = computed(() => siteOptions.value.find((option) => option.id === pickerId.value)?.name || '');
const activeOptionId = computed(() => (pickerOpen.value && pickerMatches.value[pickerIndex.value] ? `resident-map-option-${pickerMatches.value[pickerIndex.value].id}` : undefined));
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
const pickerEmptyMessage = computed(() => (pickerQuery.value.trim() ? 'No listed sites match this search.' : 'No listed evacuation sites are in this area.'));

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
    if (marker?.getLngLat()) marker.addTo(map);
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
    const coverage = catalog.value?.initial_bounds;
    if (coverage && !insideBounds(coverage, longitude, latitude)) {
        locationMessage.value = 'Your position is outside the available map coverage. The marker was not moved to a guessed location.';
        return false;
    }
    position.value = { lng: longitude, lat: latitude, meters: meters || 0 };
    marker?.setLngLat([longitude, latitude]).addTo(map);
    accuracy = { type: 'FeatureCollection', features: [accuracyPolygon(longitude, latitude, meters || 0)] };
    map?.getSource('accuracy')?.setData(accuracy);
    if (moveCamera) map?.easeTo({ center: [longitude, latitude], zoom: Math.max(map.getZoom(), 14), padding: fitPadding() });
    if (meters > 100) locationMessage.value = `Location accuracy is limited to about ${Math.round(meters)} meters.`;
    return true;
}

function ensureLocation(moveCamera = true) {
    locationMessage.value = '';
    hazardMessage.value = '';
    if (!navigator.geolocation) {
        locationMessage.value = 'Location is not available in this browser.';
        return Promise.resolve(null);
    }
    const token = ++locationRequest;
    locating.value = true;
    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition((result) => {
            if (token !== locationRequest) {
                resolve(null);
                return;
            }
            locating.value = false;
            resolve(applyLocation(result, moveCamera) ? position.value : null);
        }, (error) => {
            if (token !== locationRequest) {
                resolve(null);
                return;
            }
            locating.value = false;
            locationMessage.value = error.code === 1
                ? 'Location permission was denied. You can still move around the map.'
                : error.code === 3
                    ? 'Location timed out. You can still move around the map.'
                    : 'Your position is unavailable. You can still move around the map.';
            resolve(null);
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
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
        if (!(sheet.value && panelOpen.value)) siteClose.value?.focus();
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

function toggleWide() {
    wide.value = !wide.value;
    nextTick(() => map?.resize());
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
    closePicker();
    siteExpanded.value = !sheet.value;
    syncSelection();
    if (coordinates) map?.easeTo({ center: coordinates, zoom: Math.max(map.getZoom(), 15), padding: fitPadding() });
    if (sheet.value) closePanel();
    nextTick(() => {
        if (!(sheet.value && panelOpen.value)) siteClose.value?.focus();
    });
}

function closeSite() {
    routeToken += 1;
    routing.value = false;
    finding.value = false;
    selectedPlace.value = null;
    pickerId.value = '';
    pickerQuery.value = '';
    pickerOpen.value = false;
    routeResult.value = null;
    routeError.value = '';
    nearestSummary.value = '';
    straightLineMeters.value = null;
    clearRouteLine();
    syncSelection();
    nextTick(() => (nearestButton.value || layersButton.value)?.focus());
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
    panelClose.value?.focus();
}

function closePanel() {
    panelOpen.value = false;
    nextTick(() => layersButton.value?.focus());
}

let skipPickerOpen = false;

function openPicker() {
    if (skipPickerOpen || !showPoints.value || sitesUnavailable.value) return;
    pickerOpen.value = true;
    pickerQuery.value = '';
    const current = siteOptions.value.findIndex((option) => option.id === pickerId.value);
    pickerIndex.value = current >= 0 ? current : 0;
    nextTick(() => {
        pickerInput.value?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        placePicker();
    });
}

function closePicker() {
    pickerOpen.value = false;
    pickerQuery.value = '';
}

function placePicker() {
    const input = pickerInput.value;
    if (!input || !pickerOpen.value) return;
    const rect = input.getBoundingClientRect();
    const margin = 8;
    const viewport = window.visualViewport;
    const offsetTop = viewport?.offsetTop || 0;
    const offsetLeft = viewport?.offsetLeft || 0;
    const viewWidth = viewport?.width || window.innerWidth;
    const viewBottom = offsetTop + (viewport?.height || window.innerHeight);
    const nav = document.querySelector('.resident-bottom-nav');
    const navRect = nav?.getBoundingClientRect();
    const navVisible = Boolean(navRect && navRect.height > 0 && getComputedStyle(nav).display !== 'none');
    const limitBottom = (navVisible ? Math.min(viewBottom, offsetTop + navRect.top) : viewBottom) - margin;
    const limitTop = offsetTop + margin;
    const inputTop = offsetTop + rect.top;
    const inputBottom = offsetTop + rect.bottom;
    const spaceBelow = limitBottom - inputBottom - 4;
    const spaceAbove = inputTop - limitTop;
    const upward = spaceBelow < 160 && spaceAbove > spaceBelow;
    const maxHeight = Math.min(240, Math.max(0, (upward ? spaceAbove : spaceBelow) - 4));
    const width = Math.min(rect.width, viewWidth - margin * 2);
    const left = Math.max(offsetLeft + margin, Math.min(offsetLeft + rect.left, offsetLeft + viewWidth - width - margin));
    let top = upward ? inputTop - maxHeight - 4 : inputBottom + 4;
    if (top < limitTop) top = limitTop;
    if (top + maxHeight > limitBottom) top = Math.max(limitTop, limitBottom - maxHeight);
    pickerStyle.value = {
        position: 'fixed',
        left: `${left}px`,
        width: `${width}px`,
        maxHeight: `${Math.max(0, Math.min(maxHeight, limitBottom - top))}px`,
        top: `${top}px`,
        bottom: 'auto',
        zIndex: 40,
    };
}

function onPickerInput(event) {
    pickerQuery.value = event.target.value;
    if (!pickerOpen.value) pickerOpen.value = true;
    pickerIndex.value = 0;
    nextTick(placePicker);
}

function choosePicker(option) {
    if (!option) return;
    pickerId.value = option.id;
    closePicker();
    selectPlace(option.feature);
}

function onPickerKeydown(event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!pickerOpen.value) {
            openPicker();
            return;
        }
        const last = Math.max(0, pickerMatches.value.length - 1);
        pickerIndex.value = event.key === 'ArrowDown' ? Math.min(last, pickerIndex.value + 1) : Math.max(0, pickerIndex.value - 1);
        nextTick(() => document.getElementById(activeOptionId.value || '')?.scrollIntoView({ block: 'nearest' }));
    } else if (event.key === 'Home' && pickerOpen.value) {
        event.preventDefault();
        pickerIndex.value = 0;
    } else if (event.key === 'End' && pickerOpen.value) {
        event.preventDefault();
        pickerIndex.value = Math.max(0, pickerMatches.value.length - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (!pickerOpen.value) openPicker();
        else choosePicker(pickerMatches.value[pickerIndex.value]);
    } else if (event.key === 'Escape' && pickerOpen.value) {
        event.preventDefault();
        event.stopPropagation();
        closePicker();
    }
}

function onPickerBlur(event) {
    if (pickerRoot.value?.contains(event.relatedTarget)) return;
    closePicker();
}

function onPickerPointerDown(event) {
    if (!pickerOpen.value || pickerRoot.value?.contains(event.target)) return;
    closePicker();
}

function clearPicker() {
    skipPickerOpen = true;
    if (selectedPlace.value) closeSite();
    else closePicker();
    pickerId.value = '';
    nextTick(() => {
        pickerInput.value?.focus();
        skipPickerOpen = false;
    });
}

function onKeydown(event) {
    if (event.key !== 'Escape') return;
    if (pickerOpen.value) {
        closePicker();
        return;
    }
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
    placePicker();
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
    window.addEventListener('pointerdown', onPickerPointerDown);
    window.visualViewport?.addEventListener('resize', placePicker);
    window.visualViewport?.addEventListener('scroll', placePicker);
    await loadCatalog();
    await createMap();
});
onBeforeUnmount(() => {
    layerToken += 1;
    routeToken += 1;
    locationRequest += 1;
    placeRequest.current += 1;
    boundaryRequest.current += 1;
    riverRequest.current += 1;
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', onViewport);
    window.removeEventListener('pointerdown', onPickerPointerDown);
    window.visualViewport?.removeEventListener('resize', placePicker);
    window.visualViewport?.removeEventListener('scroll', placePicker);
    observer?.disconnect();
    map?.remove();
    map = null;
});
</script>

<template>
    <section class="resident-map">
        <header class="resident-map-heading">
            <h2>Community Hazard Map</h2>
            <p>Explore mapped hazards, listed evacuation sites, and your current location.</p>
        </header>
        <div class="resident-map-stage" :class="{ 'is-wide': wide, 'is-sheet': sheet && panelOpen }">
            <div ref="mapEl" class="resident-map-canvas" role="application" aria-label="Community hazard map"></div>
            <div class="resident-map-tools">
                <button ref="layersButton" type="button" aria-label="Layers and filters" title="Layers and filters" :aria-expanded="panelOpen" aria-controls="resident-map-filters" @click="panelOpen ? closePanel() : openPanel()">
                    <MapIcon name="layers" />
                    <span v-if="activeCount" class="resident-map-count">{{ activeCount }}</span>
                </button>
                <button type="button" aria-label="Use my location" title="Use my location" :aria-busy="locating" @click="useLocation">
                    <MapIcon name="focus" />
                </button>
                <button ref="nearestButton" type="button" class="resident-map-nearest" aria-label="Find nearest site" title="Find nearest site" :aria-busy="finding" @click="findNearest">
                    <MapIcon name="pin-check" />
                    <span>Find nearest site</span>
                </button>
                <button type="button" :aria-label="wide ? 'Restore map' : 'Expand map'" :title="wide ? 'Restore map' : 'Expand map'" @click="toggleWide">
                    <MapIcon :name="wide ? 'collapse' : 'collapse'" />
                </button>
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
            <aside
                v-show="panelOpen"
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
                    <h3>Map layers &amp; filters</h3>
                    <button ref="panelClose" type="button" :aria-label="sheet ? 'Close filters' : 'Collapse filters'" @click="closePanel">
                        <MapIcon :name="sheet ? 'x' : 'chevron'" :class="{ 'is-collapse': !sheet }" />
                    </button>
                </header>
                <div class="resident-map-filters-body" @scroll="placePicker">
                    <section>
                        <button type="button" class="resident-map-section" :aria-expanded="sections.hazard" aria-controls="resident-map-hazard" @click="sections.hazard = !sections.hazard">
                            <span>Hazard layer</span>
                            <MapIcon name="chevron" :class="{ 'is-open': sections.hazard }" />
                        </button>
                        <div v-show="sections.hazard" id="resident-map-hazard" class="resident-map-stack">
                            <label>Hazard layer
                                <select v-model="activeId">
                                    <option value="">None</option>
                                    <option v-for="layer in catalog?.layers || []" :key="layer.id" :value="layer.id">{{ layer.name }}</option>
                                </select>
                            </label>
                            <fieldset v-if="activeLayer?.legend?.length">
                                <legend>Severity</legend>
                                <label v-for="item in activeLayer.legend" :key="item.value">
                                    <input type="checkbox" :checked="severities.includes(item.value)" @change="toggleSeverity(item.value)">
                                    <i :style="{ background: item.color }"></i><span>{{ item.label }}</span>
                                </label>
                            </fieldset>
                            <template v-if="activeId">
                                <button type="button" class="resident-map-check" :disabled="!position || locating" @click="checkHazard">Check hazard at my location</button>
                            </template>
                            <p v-else>Select a hazard layer before checking this location.</p>
                        </div>
                    </section>
                    <section>
                        <button type="button" class="resident-map-section" :aria-expanded="sections.points" aria-controls="resident-map-points" @click="sections.points = !sections.points">
                            <span>Evacuation sites</span>
                            <MapIcon name="chevron" :class="{ 'is-open': sections.points }" />
                        </button>
                        <div v-show="sections.points" id="resident-map-points" class="resident-map-stack">
                            <label class="resident-map-toggle resident-map-switch"><span>Show evacuation sites</span><input v-model="showPoints" type="checkbox"></label>
                            <p v-if="pointsError" class="resident-map-inline-error" role="alert">
                                {{ pointsError }}
                                <button type="button" class="resident-map-text" @click="showPlaces">Try again</button>
                            </p>
                            <template v-else-if="showPoints && !sitesUnavailable">
                                <label>Evacuation-site area
                                    <select v-model="areaPsgc" @change="chooseArea">
                                        <option value="">All Tandag City</option>
                                        <option v-for="area in siteAreas" :key="area.psgc" :value="area.psgc">{{ area.name }}</option>
                                    </select>
                                </label>
                                <button v-if="areaPsgc" type="button" class="resident-map-text" @click="showAllSites">Show all Tandag sites</button>
                                <div ref="pickerRoot" class="resident-map-picker">
                                    <div class="resident-map-picker-head">
                                        <span id="resident-map-picker-label">Find an evacuation site</span>
                                        <span class="resident-map-count-label">{{ siteCountLabel }}</span>
                                    </div>
                                    <div class="resident-map-picker-control">
                                        <input
                                            ref="pickerInput"
                                            type="text"
                                            role="combobox"
                                            aria-autocomplete="list"
                                            aria-labelledby="resident-map-picker-label"
                                            :aria-expanded="pickerOpen"
                                            aria-controls="resident-map-picker-list"
                                            :aria-activedescendant="activeOptionId"
                                            placeholder="Select a listed site"
                                            :value="pickerOpen ? pickerQuery : pickerLabel"
                                            @focus="openPicker"
                                            @input="onPickerInput"
                                            @keydown="onPickerKeydown"
                                            @blur="onPickerBlur"
                                        >
                                        <button v-if="pickerId" type="button" aria-label="Clear selected site" @mousedown.prevent @click="clearPicker"><MapIcon name="x" /></button>
                                    </div>
                                    <ul v-show="pickerOpen" id="resident-map-picker-list" class="resident-map-picker-list" role="listbox" aria-labelledby="resident-map-picker-label" :style="pickerStyle">
                                        <li v-if="!pickerMatches.length" class="resident-map-picker-empty" role="presentation">{{ pickerEmptyMessage }}</li>
                                        <li
                                            v-for="(option, index) in pickerMatches"
                                            :id="`resident-map-option-${option.id}`"
                                            :key="option.id"
                                            role="option"
                                            :aria-selected="option.id === pickerId"
                                            :class="{ 'is-active': index === pickerIndex }"
                                            @mousedown.prevent="choosePicker(option)"
                                        >
                                            <strong>{{ option.name }}</strong>
                                            <span>{{ option.barangay || 'Barangay is not listed' }}</span>
                                        </li>
                                    </ul>
                                </div>
                                <p v-if="siteListMessage">{{ siteListMessage }}</p>
                            </template>
                            <p v-else>{{ siteListMessage }}</p>
                        </div>
                    </section>
                    <section>
                        <button type="button" class="resident-map-section" :aria-expanded="sections.more" aria-controls="resident-map-more" @click="sections.more = !sections.more">
                            <span>More map options</span>
                            <MapIcon name="chevron" :class="{ 'is-open': sections.more }" />
                        </button>
                        <div v-show="sections.more" id="resident-map-more" class="resident-map-stack">
                            <label v-if="activeId">Layer opacity
                                <input v-model.number="opacity" type="range" min="0.15" max="0.8" step="0.05" aria-label="Layer opacity">
                            </label>
                            <label class="resident-map-toggle"><input v-model="showBoundaries" type="checkbox"> Barangay boundaries</label>
                            <label class="resident-map-toggle"><input v-model="showRivers" type="checkbox"> Rivers</label>
                            <div class="resident-map-style">
                                <button type="button" :aria-pressed="!satellite" @click="satellite && toggleStyle()">Map</button>
                                <button type="button" :aria-pressed="satellite" @click="!satellite && toggleStyle()">Satellite</button>
                            </div>
                        </div>
                    </section>
                    <section>
                        <button type="button" class="resident-map-section" :aria-expanded="sections.legend" aria-controls="resident-map-legend" @click="sections.legend = !sections.legend">
                            <span>Legend</span>
                            <MapIcon name="chevron" :class="{ 'is-open': sections.legend }" />
                        </button>
                        <div v-show="sections.legend" id="resident-map-legend" class="resident-map-stack">
                            <p v-if="activeLayer"><strong>{{ activeLayer.name }}</strong></p>
                            <p v-else>No hazard layer is selected.</p>
                            <ul v-if="activeLayer" class="resident-map-legend">
                                <li v-for="item in activeLayer.legend" :key="item.value"><i :style="{ background: item.color }"></i>{{ item.label }}</li>
                            </ul>
                            <ul v-if="showPoints" class="resident-map-legend">
                                <li><span class="resident-map-swatch" style="background:#b45309"></span> Listed evacuation site</li>
                            </ul>
                        </div>
                    </section>
                    <section>
                        <button type="button" class="resident-map-section" :aria-expanded="sections.about" aria-controls="resident-map-about" @click="sections.about = !sections.about">
                            <span>About this data</span>
                            <MapIcon name="chevron" :class="{ 'is-open': sections.about }" />
                        </button>
                        <div v-show="sections.about" id="resident-map-about" class="resident-map-stack">
                            <p>Listed evacuation sites are the verified records available for Tandag City. A facility is included only when that record lists it as an evacuation site. The list may not include every site in the city.</p>
                            <p>Dataset date 2021-07-22. Availability is not confirmed unless a current source says the site is open.</p>
                            <p v-if="activeLayer">{{ activeLayer.name }}: {{ activeLayer.source }}. {{ activeLayer.date }}</p>
                        </div>
                    </section>
                    <button v-if="sheet" type="button" class="resident-map-text" @click="sheetTall = !sheetTall">{{ sheetTall ? 'Show more map' : 'Expand panel' }}</button>
                    <button type="button" class="resident-map-text" @click="resetMapView">Reset map view</button>
                    <button v-if="filtersChanged" type="button" class="resident-map-text" @click="resetFilters">Reset filters</button>
                </div>
            </aside>
            <aside
                v-if="selectedPlace"
                v-show="!(sheet && panelOpen)"
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
                    <button type="button" :aria-label="siteExpanded ? 'Collapse site details' : 'Expand site details'" @click="siteExpanded = !siteExpanded">
                        <MapIcon name="chevron" :class="{ 'is-open': siteExpanded }" />
                    </button>
                    <button ref="siteClose" type="button" aria-label="Close site" @click="closeSite"><MapIcon name="x" /></button>
                </header>
                <p v-if="!siteExpanded" class="resident-map-site-summary">{{ siteSummary }}</p>
                <div v-show="siteExpanded" class="resident-map-detail-body">
                    <p v-if="siteBarangay">Barangay {{ siteBarangay }}</p>
                    <p v-else>Barangay is not listed for this site.</p>
                    <p>Listed evacuation site</p>
                    <p>{{ selectedPlace.availability || 'Availability not confirmed.' }}</p>
                    <p v-if="nearestSummary">{{ nearestSummary }}</p>
                    <button v-if="areaPsgc" type="button" class="resident-map-text" @click="showAllSites">Show all Tandag sites</button>
                    <p v-if="routeResult">{{ formatTrip(routeResult.distance, routeResult.duration) }}</p>
                    <p v-if="straightLineMeters && !routeResult">Nearest by straight-line distance, about {{ formatDistance(straightLineMeters) }}.</p>
                    <div class="resident-map-style" role="group" aria-label="Travel mode">
                        <button type="button" :aria-pressed="travelMode === 'walking'" @click="setTravelMode('walking')">Walking</button>
                        <button type="button" :aria-pressed="travelMode === 'driving'" @click="setTravelMode('driving')">Driving</button>
                    </div>
                    <button type="button" class="resident-map-primary" :disabled="routing || locating" @click="getDirections">{{ routing ? 'Getting directions…' : 'Get directions' }}</button>
                    <p v-if="routeError" class="resident-map-inline-error" role="alert">
                        {{ routeError }}
                        <button type="button" class="resident-map-text" @click="getDirections">Try again</button>
                    </p>
                    <a v-if="routeError && externalDirections" class="resident-map-text" :href="externalDirections" target="_blank" rel="noopener noreferrer">Open directions in Google Maps</a>
                    <p v-if="routeResult" class="resident-map-route-note">Suggested route only. Road conditions and site availability may change. Follow CDRRMO instructions.</p>
                    <p v-if="routeResult">Live road-closure data is not available.</p>
                    <details v-if="routeResult?.steps?.length">
                        <summary>Directions</summary>
                        <ol>
                            <li v-for="(step, index) in routeResult.steps" :key="index">{{ step.instruction }}</li>
                        </ol>
                    </details>
                    <button v-if="routeResult || routeError" type="button" class="resident-map-text" @click="clearRoute">Clear route</button>
                    <details class="resident-map-site-info">
                        <summary>Site information</summary>
                        <p>Source: {{ selectedPlace.source }}</p>
                        <p>Dataset date: {{ selectedPlace.dataset_date }}</p>
                    </details>
                    <button v-if="sheet" type="button" class="resident-map-text" @click="siteExpanded = false">Show more map</button>
                </div>
            </aside>
        </div>
        <p class="resident-map-note">{{ note }}</p>
    </section>
</template>
