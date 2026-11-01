<script setup>
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import mapboxgl from 'mapbox-gl';
import MapboxDraw from '@mapbox/mapbox-gl-draw';
import AreaSummaryPanel from './AreaSummaryPanel.vue';
import MapIcon from './MapIcon.vue';

const props = defineProps({
    embedded: { type: Boolean, default: false },
    apiBase: { type: String, default: '/cdrrmo/api' },
    allowImpact: { type: Boolean, default: true },
    scopeBarangays: { type: Boolean, default: false },
});

const route = useRoute();
const router = useRouter();
const mapboxToken = inject('mapboxToken');
const mapboxStyle = inject('mapboxStyle');
const reportContext = inject('reportContext');

const mapEl = ref(null);
const error = ref('');
const loading = ref(true);
const mapMeta = ref({ eligible_marker_count: 0, message_if_zero: '' });
const barangays = ref([]);
const incidents = ref([]);
const selectedBarangay = ref(route.query.barangay_id || '');
const selectedIncident = ref(route.query.incident_id || '');
const searchRef = ref(route.query.search || '');
const searchOpen = ref(false);
const layersOpen = ref(false);
const layers = ref({ boundaries: true, households: true, flood: false, fieldDamage: false, aiPredictions: false });
const incidentLinkedOnly = ref(false);
const mapStyleMode = ref('satellite');
const householdFeatures = ref([]);

const selection = reactive({
    featureType: null,
    featureId: null,
    tab: 'summary',
    loading: false,
    error: '',
    household: null,
    building: null,
    buildingHouseholds: [],
    outsideArea: false,
});

const mapMissing = computed(() => !mapboxToken);
const barangayEmptyLabel = computed(() => {
    if (!props.scopeBarangays) return 'All four barangays';
    return barangays.value.length ? 'All assigned barangays' : 'No assigned barangay';
});
const summary = computed(() => reportContext.state.summary);
const summaryLoading = computed(() => reportContext.state.summaryLoading);
const hasPolygon = computed(() => reportContext.state.hasPolygon);
const areaName = computed(() => {
    const match = barangays.value.find((row) => String(row.id) === String(selectedBarangay.value));
    return match?.name || 'Current selected area';
});
const searchMatches = computed(() => householdFeatures.value.slice(0, 8));
const styleUrls = {
    satellite: 'mapbox://styles/mapbox/satellite-streets-v12',
    streets: 'mapbox://styles/mapbox/streets-v12',
};

let map;
let draw;
let layersReady = false;
let detailRequest = 0;
let layerRequest = 0;
let barangayTicket = 0;
let searchTimer;
let resizeObserver;

function api(path) {
    const base = props.apiBase || '/cdrrmo/api';
    return `${base}${path}`;
}

function currentFeature() {
    return draw?.getAll()?.features?.[0] ?? null;
}

function syncReportContext() {
    const feature = currentFeature();
    if (!feature) {
        reportContext.clearPolygonContext();
        return;
    }
    reportContext.setPolygonContext(feature.geometry.coordinates[0]);
}

function clearPolygon() {
    draw?.deleteAll();
    reportContext.clearPolygonContext();
    selection.outsideArea = false;
    if (selection.featureType) selection.tab = 'feature';
}

function onDrawChange() {
    syncReportContext();
    refreshOutsideFlag();
}

function setSource(id, data) {
    if (map?.getSource(id)) {
        map.getSource(id).setData(data);
    }
}

async function fetchJson(url) {
    const params = {};
    if (selectedBarangay.value) params.barangay_id = selectedBarangay.value;
    if (selectedIncident.value) params.incident_id = selectedIncident.value;
    if (incidentLinkedOnly.value && selectedIncident.value) params.incident_linked_only = 1;
    if (searchRef.value.trim()) params.search = searchRef.value.trim();
    return (await window.axios.get(url, { params })).data;
}

function boundsFromGeoJson(geojson) {
    const coords = [];
    for (const feature of geojson?.features || []) {
        const geom = feature.geometry;
        if (!geom) continue;
        const rings = geom.type === 'Polygon' ? [geom.coordinates[0]] : geom.type === 'MultiPolygon' ? geom.coordinates.map((p) => p[0]) : [];
        for (const ring of rings) {
            for (const c of ring) coords.push(c);
        }
    }
    if (!coords.length) return null;
    let minLng = coords[0][0];
    let maxLng = coords[0][0];
    let minLat = coords[0][1];
    let maxLat = coords[0][1];
    for (const [lng, lat] of coords) {
        minLng = Math.min(minLng, lng);
        maxLng = Math.max(maxLng, lng);
        minLat = Math.min(minLat, lat);
        maxLat = Math.max(maxLat, lat);
    }
    return [[minLng, minLat], [maxLng, maxLat]];
}

function pointInRing(lng, lat, ring) {
    if (!Array.isArray(ring) || ring.length < 4 || lng == null || lat == null) return false;
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const xi = Number(ring[i][0]);
        const yi = Number(ring[i][1]);
        const xj = Number(ring[j][0]);
        const yj = Number(ring[j][1]);
        const intersects = ((yi > lat) !== (yj > lat)) && (lng < ((xj - xi) * (lat - yi)) / ((yj - yi) || 1e-12) + xi);
        if (intersects) inside = !inside;
    }
    return inside;
}

function refreshOutsideFlag() {
    const point = selection.household || selection.building;
    const ring = reportContext.state.coordinates;
    if (!hasPolygon.value || point?.longitude == null || point?.latitude == null) {
        selection.outsideArea = false;
        return;
    }
    selection.outsideArea = !pointInRing(Number(point.longitude), Number(point.latitude), ring);
}

function drawingActive() {
    const mode = draw?.getMode?.();
    return mode === 'draw_polygon' || mode === 'direct_select';
}

function selectionGeoJson() {
    const householdId = selection.featureType === 'household' ? String(selection.featureId ?? '') : '';
    const buildingId = selection.featureType === 'building' ? String(selection.featureId ?? '') : '';
    const features = householdFeatures.value.filter((feature) => {
        const props = feature.properties || {};
        if (householdId && String(props.household_id) === householdId) return true;
        return buildingId !== '' && String(props.building_id) === buildingId;
    });
    return { type: 'FeatureCollection', features };
}

function applyHighlight() {
    if (map?.getSource('selection-highlight')) {
        map.getSource('selection-highlight').setData(selectionGeoJson());
    }
    if (!map?.getLayer('household-points')) return;
    const householdId = selection.featureType === 'household' ? String(selection.featureId ?? '') : '';
    const buildingId = selection.featureType === 'building' ? String(selection.featureId ?? '') : '';
    const clauses = [];
    if (householdId) clauses.push(['==', ['to-string', ['get', 'household_id']], householdId]);
    if (buildingId) clauses.push(['==', ['to-string', ['get', 'building_id']], buildingId]);
    const selected = clauses.length === 0 ? ['==', ['literal', 1], ['literal', 0]] : clauses.length === 1 ? clauses[0] : ['any', ...clauses];
    map.setPaintProperty('household-points', 'circle-stroke-color', ['case', selected, '#f59e0b', '#0f172a']);
    map.setPaintProperty('household-points', 'circle-stroke-width', ['case', selected, 4, 1]);
    map.setPaintProperty('household-points', 'circle-radius', ['case', selected, 11, 7]);
}

function featureCoordinates() {
    return householdFeatures.value.find((feature) => {
        const props = feature.properties || {};
        if (selection.featureType === 'household') return String(props.household_id) === String(selection.featureId);
        return String(props.building_id) === String(selection.featureId);
    })?.geometry?.coordinates;
}

function focusFeature(lng, lat, force = false) {
    if (!map || lng == null || lat == null) return;
    const inside = map.getBounds()?.contains([Number(lng), Number(lat)]);
    if (force || !inside) {
        map.easeTo({ center: [Number(lng), Number(lat)], zoom: Math.max(map.getZoom(), 16) });
    }
}

function locateSelection() {
    const point = selection.household || selection.building;
    const coords = featureCoordinates();
    const lng = point?.longitude ?? coords?.[0];
    const lat = point?.latitude ?? coords?.[1];
    focusFeature(lng, lat, true);
}

function clearFeatureSelection() {
    detailRequest += 1;
    selection.featureType = null;
    selection.featureId = null;
    selection.household = null;
    selection.building = null;
    selection.buildingHouseholds = [];
    selection.loading = false;
    selection.error = '';
    selection.outsideArea = false;
    selection.tab = 'summary';
    applyHighlight();
}

function backToBuilding() {
    if (!selection.building?.id) return;
    detailRequest += 1;
    selection.featureType = 'building';
    selection.featureId = selection.building.id;
    selection.household = null;
    selection.loading = false;
    selection.error = '';
    selection.tab = 'feature';
    refreshOutsideFlag();
    applyHighlight();
}

async function selectHousehold(id, { fromBuilding = false, locate = false } = {}) {
    const requestId = ++detailRequest;
    selection.featureType = 'household';
    selection.featureId = id;
    selection.tab = 'feature';
    selection.loading = true;
    selection.error = '';
    selection.household = null;
    selection.outsideArea = false;
    if (!fromBuilding) {
        selection.building = null;
        selection.buildingHouseholds = [];
    }
    applyHighlight();
    const coords = featureCoordinates();
    if (coords) focusFeature(coords[0], coords[1], locate);
    try {
        const data = (await window.axios.get(api(`/households/${id}`))).data;
        if (requestId !== detailRequest) return;
        selection.household = data;
        refreshOutsideFlag();
        focusFeature(data.longitude, data.latitude, locate);
    } catch {
        if (requestId !== detailRequest) return;
        selection.error = 'Household details could not be loaded.';
    } finally {
        if (requestId === detailRequest) selection.loading = false;
    }
}

async function selectBuilding(buildingId, { locate = false } = {}) {
    const requestId = ++detailRequest;
    selection.featureType = 'building';
    selection.featureId = buildingId;
    selection.tab = 'feature';
    selection.loading = true;
    selection.error = '';
    selection.household = null;
    selection.building = null;
    selection.buildingHouseholds = [];
    selection.outsideArea = false;
    applyHighlight();
    const coords = featureCoordinates();
    if (coords) focusFeature(coords[0], coords[1], locate);
    try {
        const [buildingRes, householdsRes] = await Promise.all([
            window.axios.get(api(`/buildings/${buildingId}`)),
            window.axios.get(api(`/buildings/${buildingId}/households`)),
        ]);
        if (requestId !== detailRequest) return;
        selection.building = buildingRes.data;
        selection.buildingHouseholds = householdsRes.data?.data || [];
        refreshOutsideFlag();
        focusFeature(selection.building.longitude, selection.building.latitude, locate);
    } catch {
        if (requestId !== detailRequest) return;
        selection.error = 'Building details could not be loaded.';
    } finally {
        if (requestId === detailRequest) selection.loading = false;
    }
}

function onHouseholdLayerClick(event) {
    if (drawingActive()) return;
    const clusters = map.queryRenderedFeatures(event.point, { layers: ['household-clusters'] });
    if (clusters.length) return;
    const props = event.features?.[0]?.properties;
    if (!props) return;
    if (Number(props.household_count_at_building) > 1) {
        selectBuilding(props.building_id);
        return;
    }
    selectHousehold(props.household_id);
}

function onClusterClick(event) {
    if (drawingActive()) return;
    const feature = event.features?.[0];
    if (!feature || !map) return;
    const clusterId = feature.properties.cluster_id;
    map.getSource('households').getClusterExpansionZoom(clusterId, (err, zoom) => {
        if (err) return;
        map.easeTo({ center: feature.geometry.coordinates, zoom });
    });
}

function onPointerEnter() {
    if (map) map.getCanvas().style.cursor = 'pointer';
}

function onPointerLeave() {
    if (map) map.getCanvas().style.cursor = '';
}

function bindLayerClicks() {
    if (!map) return;
    map.off('click', 'household-points', onHouseholdLayerClick);
    map.off('click', 'household-clusters', onClusterClick);
    map.off('mouseenter', 'household-points', onPointerEnter);
    map.off('mouseleave', 'household-points', onPointerLeave);
    map.off('mouseenter', 'household-clusters', onPointerEnter);
    map.off('mouseleave', 'household-clusters', onPointerLeave);
    map.on('click', 'household-points', onHouseholdLayerClick);
    map.on('click', 'household-clusters', onClusterClick);
    map.on('mouseenter', 'household-points', onPointerEnter);
    map.on('mouseleave', 'household-points', onPointerLeave);
    map.on('mouseenter', 'household-clusters', onPointerEnter);
    map.on('mouseleave', 'household-clusters', onPointerLeave);
}

function ensureMapLayers() {
    if (!map || layersReady) return;

    map.addSource('boundaries', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addSource('flood', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addSource('field-damage', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addSource('ai-predictions', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addSource('households', { type: 'geojson', data: { type: 'FeatureCollection', features: [] }, cluster: true, clusterRadius: 42 });

    map.addLayer({ id: 'flood-fill', type: 'fill', source: 'flood', paint: { 'fill-color': '#38bdf8', 'fill-opacity': 0.25 } });
    map.addLayer({ id: 'boundary-line', type: 'line', source: 'boundaries', paint: { 'line-color': '#2563eb', 'line-width': 2 } });
    map.addLayer({ id: 'field-damage-points', type: 'circle', source: 'field-damage', paint: { 'circle-color': ['coalesce', ['get', 'damage_color'], '#64748b'], 'circle-radius': 8, 'circle-stroke-color': '#fff', 'circle-stroke-width': 1 } });
    map.addLayer({ id: 'ai-prediction-points', type: 'circle', source: 'ai-predictions', paint: { 'circle-color': '#f59e0b', 'circle-radius': 6, 'circle-stroke-color': '#fff', 'circle-stroke-width': 1 } });
    map.addLayer({ id: 'household-clusters', type: 'circle', source: 'households', filter: ['has', 'point_count'], paint: { 'circle-color': '#0d9488', 'circle-radius': 18 } });
    map.addLayer({ id: 'household-count', type: 'symbol', source: 'households', filter: ['has', 'point_count'], layout: { 'text-field': ['get', 'point_count'], 'text-size': 12 }, paint: { 'text-color': '#fff' } });
    map.addLayer({
        id: 'household-points',
        type: 'circle',
        source: 'households',
        filter: ['!', ['has', 'point_count']],
        paint: { 'circle-color': '#0d9488', 'circle-stroke-color': '#0f172a', 'circle-stroke-width': 1, 'circle-radius': 7 },
    });
    map.addSource('selection-highlight', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addLayer({
        id: 'selection-highlight',
        type: 'circle',
        source: 'selection-highlight',
        paint: { 'circle-color': '#f59e0b', 'circle-opacity': 0.2, 'circle-radius': 16, 'circle-stroke-color': '#f59e0b', 'circle-stroke-width': 3 },
    });

    map.setLayoutProperty('flood-fill', 'visibility', 'none');
    map.setLayoutProperty('field-damage-points', 'visibility', 'none');
    map.setLayoutProperty('ai-prediction-points', 'visibility', 'none');
    bindLayerClicks();
    applyHighlight();
    layersReady = true;
}

function reconcileSelection(features) {
    if (!selection.featureId) return;
    const stillThere = features.some((feature) => {
        const props = feature.properties || {};
        if (selection.featureType === 'household') return String(props.household_id) === String(selection.featureId);
        return String(props.building_id) === String(selection.featureId);
    });
    if (!stillThere) clearFeatureSelection();
}

function maybeSelectSearchResult(features) {
    const query = searchRef.value.trim();
    if (!query || features.length !== 1 || drawingActive()) return;
    const props = features[0].properties || {};
    if (Number(props.household_count_at_building) > 1 && query.toLowerCase() === String(props.building_reference || '').toLowerCase()) {
        if (selection.featureType === 'building' && String(selection.featureId) === String(props.building_id)) return;
        selectBuilding(props.building_id, { locate: true });
        return;
    }
    if (selection.featureType === 'household' && String(selection.featureId) === String(props.household_id)) return;
    selectHousehold(props.household_id, { locate: true });
}

async function refreshLayers() {
    if (!map) return;
    const requestId = ++layerRequest;
    loading.value = true;
    error.value = '';
    try {
        ensureMapLayers();
        mapMeta.value = (await window.axios.get(api(`/map/meta`))).data;
        const [boundaries, households] = await Promise.all([
            fetchJson(api(`/map/boundaries`)),
            fetchJson(api(`/map/household-locations`)),
        ]);
        if (requestId !== layerRequest) return;
        setSource('boundaries', boundaries);
        setSource('households', households);
        mapMeta.value = { ...mapMeta.value, ...(households.meta || {}) };
        householdFeatures.value = households.features || [];
        searchOpen.value = !!searchRef.value.trim() && householdFeatures.value.length > 1;
        reconcileSelection(householdFeatures.value);
        maybeSelectSearchResult(householdFeatures.value);
        if (!currentFeature() && !selection.featureId) {
            const bounds = boundsFromGeoJson(boundaries);
            if (bounds) map.fitBounds(bounds, { padding: 48, maxZoom: 14 });
        }
        if (layers.value.flood) setSource('flood', await fetchJson(api(`/map/flood`)));
        if (layers.value.fieldDamage) setSource('field-damage', await fetchJson(api(`/map/field-damage`)));
        if (layers.value.aiPredictions) setSource('ai-predictions', await fetchJson(api(`/map/ai-predictions`)));
        applyHighlight();
        syncLayerVisibility();
    } catch {
        if (requestId === layerRequest) {
            error.value = 'Map layers could not be loaded from the authorized API.';
        }
    } finally {
        if (requestId === layerRequest) {
            loading.value = false;
            map?.resize();
        }
    }
}

async function focusResidentSelection() {
    if (!map) return;
    try {
        const { data } = await window.axios.get(api(`/residents/map-focus`));
        const points = Array.isArray(data.points) ? data.points : [];
        if (!points.length) return;
        if (points.length === 1) {
            map.flyTo({ center: [points[0].longitude, points[0].latitude], zoom: 16 });
        } else {
            const lngs = points.map((point) => point.longitude);
            const lats = points.map((point) => point.latitude);
            map.fitBounds(
                [[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]],
                { padding: 48, maxZoom: 16 },
            );
        }
        const householdIds = [...new Set(points.map((point) => point.household_id).filter(Boolean))];
        if (householdIds.length === 1) {
            await selectHousehold(householdIds[0], { locate: false });
        }
    } catch {
        error.value = 'Selected resident locations could not be opened on the map.';
    }
}

function fitToSelection() {
    const feature = currentFeature();
    if (!feature || !map) return;
    const coords = feature.geometry.coordinates[0];
    const lngs = coords.map((c) => c[0]);
    const lats = coords.map((c) => c[1]);
    map.fitBounds([[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]], { padding: 40 });
}

async function resetStudyArea() {
    const boundaries = await fetchJson(api(`/map/boundaries`));
    setSource('boundaries', boundaries);
    const bounds = boundsFromGeoJson(boundaries);
    if (bounds) map.fitBounds(bounds, { padding: 48, maxZoom: 14 });
}

function switchMapStyle(mode) {
    if (!map || mapStyleMode.value === mode) return;
    const feature = currentFeature();
    mapStyleMode.value = mode;
    layersReady = false;
    map.setStyle(styleUrls[mode] || styleUrls.satellite);
    map.once('style.load', async () => {
        ensureMapLayers();
        if (feature && draw && !currentFeature()) draw.add(feature);
        await refreshLayers();
        applyHighlight();
    });
}

function clearFilters() {
    selectedBarangay.value = '';
    selectedIncident.value = '';
    searchRef.value = '';
    incidentLinkedOnly.value = false;
    searchOpen.value = false;
    router.replace({ query: {} });
}

async function previewReport() {
    if (!props.allowImpact) return;
    syncReportContext();
    if (!reportContext.state.hasPolygon) return;
    await reportContext.storeReportContext();
    reportContext.openReportPreview();
}

async function viewLinkedRecords() {
    syncReportContext();
    if (!reportContext.state.hasPolygon) return;
    await reportContext.storeReportContext();
    reportContext.openResidentsWithArea(router);
}

function openSelectedResidents() {
    const id = selection.household?.id;
    if (!id) return;
    router.push({ path: '/residents', query: { household_id: id } });
}

function chooseSearchFeature(feature) {
    searchOpen.value = false;
    const props = feature.properties || {};
    if (Number(props.household_count_at_building) > 1 && searchRef.value.trim().toLowerCase() === String(props.building_reference || '').toLowerCase()) {
        selectBuilding(props.building_id, { locate: true });
        return;
    }
    selectHousehold(props.household_id, { locate: true });
}

function layerIdFor(key) {
    if (key === 'households') return 'household-points';
    if (key === 'flood') return 'flood-fill';
    if (key === 'fieldDamage') return 'field-damage-points';
    if (key === 'aiPredictions') return 'ai-prediction-points';
    return 'boundary-line';
}

function toggleLayer(key, visible) {
    const mapKey = layerIdFor(key);
    if (!map?.getLayer(mapKey)) return;
    map.setLayoutProperty(mapKey, 'visibility', visible ? 'visible' : 'none');
    if (key === 'households' && map.getLayer('household-clusters')) {
        map.setLayoutProperty('household-clusters', 'visibility', visible ? 'visible' : 'none');
        map.setLayoutProperty('household-count', 'visibility', visible ? 'visible' : 'none');
    }
}

function syncLayerVisibility() {
    toggleLayer('boundaries', layers.value.boundaries);
    toggleLayer('households', layers.value.households);
    toggleLayer('flood', layers.value.flood);
    toggleLayer('fieldDamage', layers.value.fieldDamage);
    toggleLayer('aiPredictions', layers.value.aiPredictions);
}

function startDraw() {
    draw?.changeMode('draw_polygon');
}

function editDraw() {
    const feature = currentFeature();
    if (feature?.id) draw?.changeMode('direct_select', { featureId: feature.id });
}

async function selectBarangayBoundary() {
    if (!selectedBarangay.value || !draw) return;
    const requestId = ++barangayTicket;
    const boundaries = await fetchJson(api(`/map/boundaries`));
    if (requestId !== barangayTicket || !selectedBarangay.value) return;
    const feature = boundaries.features?.[0];
    const ring = feature?.geometry?.type === 'Polygon'
        ? feature.geometry.coordinates[0]
        : feature?.geometry?.type === 'MultiPolygon'
            ? feature.geometry.coordinates[0][0]
            : null;
    if (!ring) return;
    draw.deleteAll();
    draw.add({ type: 'Feature', geometry: { type: 'Polygon', coordinates: [ring] } });
    reportContext.setPolygonContext(ring);
    if (!selection.featureType) selection.tab = 'summary';
    fitToSelection();
    refreshOutsideFlag();
}

function onKeydown(event) {
    if (event.key !== 'Escape') return;
    if (searchOpen.value) {
        searchOpen.value = false;
        return;
    }
    if (layersOpen.value) {
        layersOpen.value = false;
        return;
    }
    if (selection.featureType) clearFeatureSelection();
}

onMounted(async () => {
    const summaryRes = await window.axios.get(api(`/summary`));
    barangays.value = summaryRes.data.barangays;
    try {
        incidents.value = (await window.axios.get(api(`/incidents`))).data.data || [];
    } catch {
        incidents.value = [];
    }

    window.addEventListener('keydown', onKeydown);

    if (mapMissing.value) {
        error.value = 'MAPBOX_ACCESS_TOKEN is not configured for this environment.';
        loading.value = false;
        return;
    }

    mapboxgl.accessToken = mapboxToken;
    map = new mapboxgl.Map({
        container: mapEl.value,
        style: mapboxStyle || styleUrls.satellite,
        center: [126.17, 9.08],
        zoom: 12,
    });
    map.addControl(new mapboxgl.NavigationControl(), 'top-right');
    map.addControl(new mapboxgl.ScaleControl({ maxWidth: 120 }), 'bottom-left');
    map.addControl(new mapboxgl.FullscreenControl(), 'top-right');
    draw = new MapboxDraw({ displayControlsDefault: false });
    map.addControl(draw);
    map.on('draw.create', onDrawChange);
    map.on('draw.update', onDrawChange);
    map.on('draw.delete', onDrawChange);
    map.on('load', async () => {
        await refreshLayers();
        if (selectedBarangay.value) await selectBarangayBoundary();
        if (route.query.focus === 'residents') {
            await focusResidentSelection();
        } else if (route.query.household_id) {
            await selectHousehold(route.query.household_id, { locate: true });
        }
    });

    resizeObserver = new ResizeObserver(() => map?.resize());
    if (mapEl.value) resizeObserver.observe(mapEl.value);
});

watch(selectedBarangay, async () => {
    const ticket = ++barangayTicket;
    await refreshLayers();
    if (ticket !== barangayTicket) return;
    await selectBarangayBoundary();
});
watch(incidentLinkedOnly, () => {
    if (selectedIncident.value) refreshLayers();
});
watch(selectedIncident, (value) => {
    if (!value && incidentLinkedOnly.value) {
        incidentLinkedOnly.value = false;
        return;
    }
    refreshLayers();
});
watch(searchRef, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => refreshLayers(), 300);
});
watch(layers, () => syncLayerVisibility(), { deep: true });
watch(() => layers.value.flood, async (on) => { if (on) setSource('flood', await fetchJson(api(`/map/flood`))); });
watch(() => layers.value.fieldDamage, async (on) => { if (on) setSource('field-damage', await fetchJson(api(`/map/field-damage`))); });
watch(() => layers.value.aiPredictions, async (on) => { if (on) setSource('ai-predictions', await fetchJson(api(`/map/ai-predictions`))); });
watch(() => reportContext.state.coordinates, () => refreshOutsideFlag());

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    resizeObserver?.disconnect();
    window.removeEventListener('keydown', onKeydown);
    map?.remove();
});
</script>

<template>
    <div class="map-workspace" :class="{ 'is-embedded': embedded }">
        <div class="map-toolbar">
            <div class="map-toolbar-group">
                <span class="map-toolbar-label">Filters</span>
                <div class="map-mode-toggle" role="group" aria-label="Basemap">
                    <button type="button" :class="{ 'is-active': mapStyleMode === 'satellite' }" @click="switchMapStyle('satellite')">Satellite</button>
                    <button type="button" :class="{ 'is-active': mapStyleMode === 'streets' }" @click="switchMapStyle('streets')">Map</button>
                </div>
                <select v-model="selectedIncident" aria-label="Incident or registry view">
                    <option value="">Registry view</option>
                    <option v-for="inc in incidents" :key="inc.id" :value="String(inc.id)">{{ inc.incident_name }}</option>
                </select>
                <select v-model="selectedBarangay" aria-label="Barangay">
                    <option value="">{{ barangayEmptyLabel }}</option>
                    <option v-for="b in barangays" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
                </select>
                <div class="map-search">
                    <input
                        v-model="searchRef"
                        type="search"
                        placeholder="Household or building reference"
                        aria-label="Search household or building reference"
                        @focus="searchOpen = !!searchRef.trim() && householdFeatures.length > 1"
                    >
                    <ul v-if="searchOpen && searchRef.trim() && searchMatches.length" class="map-search-results" @mousedown.prevent>
                        <li v-for="feature in searchMatches" :key="feature.properties.household_id">
                            <button type="button" @click="chooseSearchFeature(feature)">
                                {{ feature.properties.household_reference }}
                                <span v-if="feature.properties.building_reference"> · {{ feature.properties.building_reference }}</span>
                            </button>
                        </li>
                        <li v-if="householdFeatures.length > searchMatches.length" class="cdrrmo-muted">Showing {{ searchMatches.length }} of {{ householdFeatures.length }} matches.</li>
                    </ul>
                </div>
                <label class="map-inline-check" :class="{ 'is-disabled': !selectedIncident }">
                    <input v-model="incidentLinkedOnly" type="checkbox" :disabled="!selectedIncident">
                    Incident-linked records only
                </label>
            </div>
            <div class="map-toolbar-group">
                <span class="map-toolbar-label">Map actions</span>
                <button type="button" class="mapr-button mapr-button-secondary map-tool-btn" @click="startDraw"><MapIcon name="polygon" /> Draw area</button>
                <button type="button" class="mapr-button mapr-button-secondary map-tool-btn" :disabled="!hasPolygon" @click="editDraw"><MapIcon name="pencil" /> Edit area</button>
                <button type="button" class="mapr-button mapr-button-secondary map-tool-btn" :disabled="!hasPolygon" @click="clearPolygon"><MapIcon name="eraser" /> Clear area</button>
                <button type="button" class="mapr-button mapr-button-secondary map-tool-btn" :disabled="!hasPolygon" @click="fitToSelection"><MapIcon name="focus" /> Fit to area</button>
                <button type="button" class="mapr-button mapr-button-secondary map-tool-btn" :aria-expanded="layersOpen" @click="layersOpen = !layersOpen"><MapIcon name="layers" /> Layers</button>
                <button type="button" class="mapr-button mapr-button-secondary map-tool-btn" @click="resetStudyArea"><MapIcon name="reset" /> Reset view</button>
                <button type="button" class="mapr-button mapr-button-secondary map-tool-btn" @click="clearFilters"><MapIcon name="filter-reset" /> Clear filters</button>
            </div>
        </div>
        <div v-if="layersOpen" class="cdrrmo-filters layer-panel">
            <label><input v-model="layers.boundaries" type="checkbox"> Barangay boundaries</label>
            <label><input v-model="layers.households" type="checkbox"> Source-recorded households</label>
            <label><input v-model="layers.flood" type="checkbox"> Flood hazard (historical)</label>
            <label><input v-model="layers.fieldDamage" type="checkbox"> Field-verified damage</label>
            <label><input v-model="layers.aiPredictions" type="checkbox"> Preliminary AI classification</label>
        </div>
        <p v-if="loading">Loading map layers…</p>
        <p v-if="error" class="cdrrmo-error">{{ error }}</p>
        <div class="map-layout">
            <div class="map-canvas-wrap">
                <div ref="mapEl" class="map-canvas" :class="{ 'map-canvas-embedded': embedded }" />
                <div v-if="!loading && (mapMeta.total ?? mapMeta.eligible_marker_count) === 0" class="map-empty-overlay">
                    <p>{{ mapMeta.message_if_zero || mapMeta.message_if_empty || 'No source-recorded household locations are available.' }}</p>
                </div>
            </div>
            <AreaSummaryPanel
                :summary="summary"
                :summary-loading="summaryLoading"
                :has-polygon="hasPolygon"
                :area-name="areaName"
                :selection="selection"
                :show-impact="allowImpact"
                @preview-report="previewReport"
                @view-records="viewLinkedRecords"
                @view-households="viewLinkedRecords"
                @clear-feature="clearFeatureSelection"
                @back-to-building="backToBuilding"
                @select-household="(id) => selectHousehold(id, { fromBuilding: true })"
                @locate="locateSelection"
                @open-residents="openSelectedResidents"
                @set-tab="(tab) => { selection.tab = tab; }"
            />
        </div>
        <p class="cdrrmo-muted map-legend">
            Showing {{ mapMeta.returned ?? mapMeta.source_marker_count ?? 0 }} of {{ mapMeta.total ?? mapMeta.source_marker_count ?? 0 }} source-recorded households.
            Teal markers are for browsing. An amber outline marks the selection. Damage colors apply only when an assessment exists for the selected incident.
        </p>
    </div>
</template>
