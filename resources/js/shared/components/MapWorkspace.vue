<script setup>
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, useId, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import mapboxgl from 'mapbox-gl';
import MapboxDraw from '@mapbox/mapbox-gl-draw';
import AreaSummaryPanel from './AreaSummaryPanel.vue';
import MapIcon from './MapIcon.vue';
import MapLayersPanel from '../../bdrrm/components/MapLayersPanel.vue';
import MapActionsMenu from '../../bdrrm/components/MapActionsMenu.vue';
import { createCommunityLayers } from '../../bdrrm/communityLayers.js';

const props = defineProps({
    compact: { type: Boolean, default: false },
    embedded: { type: Boolean, default: false },
    apiBase: { type: String, default: '/cdrrmo/api' },
    allowImpact: { type: Boolean, default: true },
    scopeBarangays: { type: Boolean, default: false },
    residentSubmissions: { type: Boolean, default: false },
    preview: { type: Boolean, default: false },
    barangayId: { type: [String, Number], default: '' },
    communityLayers: { type: Boolean, default: false },
    actionMenu: { type: Boolean, default: false },
    singleClear: { type: Boolean, default: false },
    hideHouseholds: { type: Boolean, default: false },
});

const route = useRoute();
const controlId = useId();
const router = useRouter();
const emit = defineEmits(['select-report']);
const mapboxToken = inject('mapboxToken');
const mapboxStyle = inject('mapboxStyle');
const reportContext = inject('reportContext');

const mapEl = ref(null);
const mapFrame = ref(null);
const fullscreen = ref(false);
const error = ref('');
const loading = ref(true);
const mapMeta = ref({ eligible_marker_count: 0, message_if_zero: '' });
const barangays = ref([]);
const incidents = ref([]);
const selectedBarangay = ref(props.preview ? String(props.barangayId || '') : (route.query.barangay_id || ''));
const selectedIncident = ref(route.query.incident_id || '');
const searchRef = ref(route.query.search || '');
const searchInput = ref(null);
function clearMapSearch() {
    searchRef.value = '';
    searchOpen.value = false;
    searchInput.value?.$el?.focus();
}
const searchOpen = ref(false);
const layersOpen = ref(false);
const layers = ref({
    boundaries: true,
    households: !props.preview && !props.hideHouseholds,
    flood: false,
    fieldDamage: false,
    aiPredictions: false,
    residentSubmissions: props.residentSubmissions,
});
const previewEmpty = ref(false);
let previewShouldFit = true;
let previewBoundaries = null;
let previewPoints = [];
const incidentLinkedOnly = ref(false);
const mapStyleMode = ref('satellite');
const community = props.communityLayers ? createCommunityLayers(props.apiBase || '/bdrrm/api') : null;
const communityOpen = ref(false);
const actionsOpen = ref(false);
let residentPointFeatures = [];
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
    report: null,
    reportChoices: [],
    reportCoordinates: null,
});

const mapMissing = computed(() => !mapboxToken);
const isCdrrmoMap = computed(() => route.path === '/map'
    && props.apiBase === '/cdrrmo/api'
    && !props.compact
    && !props.embedded
    && !props.preview);
const isAssessorMap = computed(() => props.actionMenu && props.apiBase === '/rdana/api');
const controlSize = computed(() => props.compact || props.actionMenu ? 'small' : undefined);
const filterPanelClass = computed(() => {
    if (isAssessorMap.value) return 'rdana-map-select';
    if (props.actionMenu) return 'bdrrm-map-select';
    if (props.compact) return 'cdrrmo-dashboard-overlay';
    if (isCdrrmoMap.value) return 'cdrrmo-map-overlay';
    return undefined;
});
const barangayEmptyLabel = computed(() => {
    if (!props.scopeBarangays) return 'All four barangays';
    if (props.actionMenu) return barangays.value.length ? 'All assigned' : 'None assigned';
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
const markerColors = {
    household: '#0d9488',
    building: '#6d28d9',
    report: '#1e3a8a',
    preliminary: '#f59e0b',
};

let map;
let draw;
let residentPopup = null;
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

function boundsFromCoords(coords) {
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

function fitPreviewView() {
    if (!props.preview || !map || !previewShouldFit) return;
    const bounds = boundsFromGeoJson(previewBoundaries) || boundsFromCoords(previewPoints);
    if (bounds) {
        const duration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 400;
        map.fitBounds(bounds, { padding: 36, maxZoom: 15, duration });
    }
    previewShouldFit = false;
}

function resetPreview() {
    previewShouldFit = true;
    fitPreviewView();
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
    if (selection.featureType === 'resident-report' && Array.isArray(selection.reportCoordinates)) {
        return {
            type: 'FeatureCollection',
            features: [{ type: 'Feature', geometry: { type: 'Point', coordinates: selection.reportCoordinates }, properties: {} }],
        };
    }
    const householdId = selection.featureType === 'household' ? String(selection.featureId ?? '') : '';
    const buildingId = selection.featureType === 'building' ? String(selection.featureId ?? '') : '';
    const features = householdFeatures.value.filter((feature) => {
        const props = feature.properties || {};
        if (householdId && String(props.household_id) === householdId) return true;
        return buildingId !== '' && String(props.building_id) === buildingId;
    });
    if (buildingId !== '' && features.length === 0 && selection.building?.longitude != null && selection.building?.latitude != null) {
        return {
            type: 'FeatureCollection',
            features: [{
                type: 'Feature',
                geometry: { type: 'Point', coordinates: [Number(selection.building.longitude), Number(selection.building.latitude)] },
                properties: {},
            }],
        };
    }
    return { type: 'FeatureCollection', features };
}

function applyHighlight() {
    if (map?.getSource('selection-highlight')) {
        map.getSource('selection-highlight').setData(selectionGeoJson());
    }
    if (!map?.getLayer('household-points')) {
        paintResidentSelection();
        return;
    }
    const householdId = selection.featureType === 'household' ? String(selection.featureId ?? -1) : '-1';
    const buildingId = selection.featureType === 'building' ? String(selection.featureId ?? -1) : '-1';
    // Mapbox needs a data-driven expression even when the selection is empty.
    const selected = ['any',
        ['==', ['to-string', ['get', 'household_id']], householdId],
        ['==', ['to-string', ['get', 'building_id']], buildingId],
    ];
    map.setPaintProperty('household-points', 'circle-stroke-color', ['case', selected, '#f59e0b', props.actionMenu ? '#fff' : '#0f172a']);
    map.setPaintProperty('household-points', 'circle-stroke-width', ['case', selected, 4, 1]);
    map.setPaintProperty('household-points', 'circle-radius', ['case', selected, 11, 7]);
    paintResidentSelection();
}

function paintResidentSelection() {
    if (!map?.getLayer('resident-submission-points')) return;
    const reportId = selection.featureType === 'resident-report' ? String(selection.featureId ?? '') : '';
    // Keep the paint expression data-driven when clearing the selection.
    const reportSelected = ['==', ['to-string', ['get', 'id']], reportId];
    map.setPaintProperty('resident-submission-points', 'circle-stroke-color', ['case', reportSelected, '#f59e0b', '#ffffff']);
    map.setPaintProperty('resident-submission-points', 'circle-stroke-width', ['case', reportSelected, 4, 2]);
    map.setPaintProperty('resident-submission-points', 'circle-radius', ['case', reportSelected, 11, 8]);
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
    selection.report = null;
    selection.reportChoices = [];
    selection.reportCoordinates = null;
    selection.loading = false;
    selection.error = '';
    selection.outsideArea = false;
    selection.tab = 'summary';
    residentPopup?.remove();
    emit('select-report', null);
    applyHighlight();
}

function closeCommunitySheet() {
    if (props.communityLayers && window.innerWidth < 768) communityOpen.value = false;
}

function rememberResidentPoints(collection) {
    residentPointFeatures = collection?.features || [];
    const wanted = route.query.resident_report_id;
    if (props.preview || wanted == null || wanted === '') return;
    const feature = residentPointFeatures.find((item) => String(item.properties?.id) === String(wanted));
    if (!feature?.geometry?.coordinates) return;
    if (selection.featureType === 'resident-report' && String(selection.featureId) === String(wanted)) return;
    selectResidentReport(feature.properties || {}, feature.geometry.coordinates);
}

function keepReportVisible(coordinates) {
    if (!map || !Array.isArray(coordinates)) return;
    if (map.getBounds()?.contains(coordinates)) return;
    const duration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 400;
    map.easeTo({ center: coordinates, duration });
}

async function loadLinkedReports(url, requestId) {
    selection.report = null;
    selection.reportChoices = [];
    if (!props.residentSubmissions) return;
    try {
        const rows = (await window.axios.get(url)).data?.data || [];
        if (requestId !== detailRequest) return;
        selection.reportChoices = rows;
        selection.report = rows.length === 1 ? rows[0] : null;
    } catch {
        if (requestId !== detailRequest) return;
        selection.reportChoices = [];
    }
}

function chooseLinkedReport(id) {
    selection.report = (selection.reportChoices || []).find((row) => String(row.id) === String(id)) || null;
    if (selection.report) emit('select-report', selection.report.id);
}

async function selectResidentReport(properties, coordinates) {
    const id = Number(properties?.id);
    if (!Number.isInteger(id)) return;
    const requestId = ++detailRequest;
    residentPopup?.remove();
    selection.featureType = 'resident-report';
    selection.featureId = id;
    selection.tab = 'feature';
    selection.household = null;
    selection.building = null;
    selection.buildingHouseholds = [];
    selection.reportChoices = [];
    selection.reportCoordinates = coordinates;
    selection.loading = true;
    selection.error = '';
    closeCommunitySheet();
    selection.report = {
        id,
        reference: properties.reference,
        incident_name: properties.incident_name || null,
        barangay_name: properties.barangay_name || null,
        reported_location: properties.reported_location || null,
        submitted_at: properties.submitted_at || null,
        status: properties.status,
        label: properties.label || properties.status,
        resident_name: null,
        incident_type: null,
    };
    emit('select-report', id);
    applyHighlight();
    keepReportVisible(coordinates);
    try {
        const data = (await window.axios.get(api(`/submissions/${id}/summary`))).data?.data;
        if (requestId !== detailRequest) return;
        if (data) selection.report = data;
    } catch {
        if (requestId !== detailRequest) return;
        selection.error = 'The report summary could not be loaded.';
    } finally {
        if (requestId === detailRequest) selection.loading = false;
    }
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
        closeCommunitySheet();
        await loadLinkedReports(api(`/households/${id}/resident-reports`), requestId);
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
        applyHighlight();
        focusFeature(selection.building.longitude, selection.building.latitude, locate);
        closeCommunitySheet();
        await loadLinkedReports(api(`/buildings/${buildingId}/resident-reports`), requestId);
    } catch (exception) {
        if (requestId !== detailRequest) return;
        const status = exception?.response?.status;
        selection.error = status === 403
            ? 'That record is outside your assignment.'
            : status === 404
                ? 'This building record is unavailable.'
                : 'Building details could not be loaded.';
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

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[char]));
}

function onResidentLayerClick(event) {
    const feature = event.features?.[0];
    const coordinates = feature?.geometry?.coordinates;
    const id = Number(feature?.properties?.id);
    if (!map || !coordinates || !Number.isInteger(id)) return;
    selectResidentReport(feature.properties || {}, coordinates);
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
    if (map.getLayer('resident-submission-points')) {
        map.off('click', 'resident-submission-points', onResidentLayerClick);
        map.off('mouseenter', 'resident-submission-points', onPointerEnter);
        map.off('mouseleave', 'resident-submission-points', onPointerLeave);
        map.on('click', 'resident-submission-points', onResidentLayerClick);
        map.on('mouseenter', 'resident-submission-points', onPointerEnter);
        map.on('mouseleave', 'resident-submission-points', onPointerLeave);
    }
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
    map.addLayer({ id: 'field-damage-points', type: 'circle', source: 'field-damage', paint: { 'circle-color': ['coalesce', ['get', 'damage_color'], '#64748b'], 'circle-radius': props.actionMenu ? 11 : 8, 'circle-stroke-color': '#fff', 'circle-stroke-width': 1 } });
    map.addLayer({ id: 'ai-prediction-points', type: 'circle', source: 'ai-predictions', paint: { 'circle-color': markerColors.preliminary, 'circle-radius': props.actionMenu ? 9 : 6, 'circle-stroke-color': '#fff', 'circle-stroke-width': 1 } });
    map.addLayer({ id: 'household-clusters', type: 'circle', source: 'households', filter: ['has', 'point_count'], paint: { 'circle-color': '#0d9488', 'circle-radius': 18 } });
    map.addLayer({ id: 'household-count', type: 'symbol', source: 'households', filter: ['has', 'point_count'], layout: { 'text-field': ['get', 'point_count'], 'text-size': 12 }, paint: { 'text-color': '#fff' } });
    map.addLayer({
        id: 'household-points',
        type: 'circle',
        source: 'households',
        filter: ['!', ['has', 'point_count']],
        paint: {
            'circle-color': props.actionMenu
                ? ['case', ['>', ['to-number', ['get', 'household_count_at_building'], 0], 1], markerColors.building, markerColors.household]
                : markerColors.household,
            'circle-stroke-color': props.actionMenu ? '#fff' : '#0f172a',
            'circle-stroke-width': 1,
            'circle-radius': 7,
        },
    });
    if (props.residentSubmissions) {
        map.addSource('resident-submissions', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        map.addLayer({
            id: 'resident-submission-points',
            type: 'circle',
            source: 'resident-submissions',
            paint: { 'circle-color': markerColors.report, 'circle-radius': 8, 'circle-stroke-color': '#fff', 'circle-stroke-width': 2 },
        });
    }
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
    if (!selection.featureId || selection.featureType === 'resident-report') return;
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

async function refreshPreview(requestId) {
    const boundaries = await fetchJson(api(`/map/boundaries`));
    let points = { type: 'FeatureCollection', features: [] };
    try {
        points = await fetchJson(api(`/map/resident-submissions`));
    } catch {
        points = { type: 'FeatureCollection', features: [] };
    }
    if (requestId !== layerRequest) return;
    setSource('boundaries', boundaries);
    setSource('resident-submissions', points);
    previewBoundaries = boundaries;
    previewPoints = (points.features || [])
        .filter((feature) => feature.geometry?.type === 'Point' && Array.isArray(feature.geometry?.coordinates))
        .map((feature) => feature.geometry.coordinates);
    previewEmpty.value = previewPoints.length === 0;
    syncLayerVisibility();
    fitPreviewView();
}

async function refreshLayers() {
    if (!map) return;
    const requestId = ++layerRequest;
    loading.value = true;
    error.value = '';
    try {
        ensureMapLayers();
        if (community) community.bind(map);
        if (props.preview) {
            await refreshPreview(requestId);
            return;
        }
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
        if (!currentFeature() && !selection.featureId && !reportedFocus()) {
            const bounds = boundsFromGeoJson(boundaries);
            if (bounds) map.fitBounds(bounds, { padding: 48, maxZoom: 14 });
        }
        if (layers.value.flood) setSource('flood', await fetchJson(api(`/map/flood`)));
        if (layers.value.fieldDamage) setSource('field-damage', await fetchJson(api(`/map/field-damage`)));
        if (layers.value.aiPredictions) setSource('ai-predictions', await fetchJson(api(`/map/ai-predictions`)));
        if (props.residentSubmissions && layers.value.residentSubmissions) {
            try {
                const points = await fetchJson(api(`/map/resident-submissions`));
                setSource('resident-submissions', points);
                rememberResidentPoints(points);
            } catch {
                setSource('resident-submissions', { type: 'FeatureCollection', features: [] });
                rememberResidentPoints({ features: [] });
            }
        }
        applyHighlight();
        syncLayerVisibility();
        focusReportedQuery();
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

function reportedFocus() {
    if (!props.residentSubmissions) return null;
    const lng = Number(route.query.lng);
    const lat = Number(route.query.lat);
    if (!Number.isFinite(lng) || !Number.isFinite(lat) || lng < -180 || lng > 180 || lat < -90 || lat > 90) return null;
    return [lng, lat];
}

function focusReportedQuery() {
    const point = reportedFocus();
    if (!map || !point) return;
    const wide = window.innerWidth >= 960;
    map.easeTo({
        center: point,
        zoom: Math.max(map.getZoom(), 16),
        padding: wide
            ? { top: 56, right: 72, bottom: 56, left: 48 }
            : { top: 56, right: 48, bottom: 72, left: 24 },
    });
}

function layerIdFor(key) {
    if (key === 'households') return 'household-points';
    if (key === 'flood') return 'flood-fill';
    if (key === 'fieldDamage') return 'field-damage-points';
    if (key === 'aiPredictions') return 'ai-prediction-points';
    if (key === 'residentSubmissions') return 'resident-submission-points';
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
    toggleLayer('residentSubmissions', layers.value.residentSubmissions);
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

function runMapAction(command) {
    if (command === 'draw') startDraw();
    else if (command === 'edit' && hasPolygon.value) editDraw();
    else if (command === 'clear-area' && hasPolygon.value) clearPolygon();
    else if (command === 'fit' && hasPolygon.value) fitToSelection();
    else if (command === 'registry') layersOpen.value = !layersOpen.value;
    else if (command === 'map-layers') communityOpen.value = !communityOpen.value;
    else if (command === 'reset') resetStudyArea();
    else if (command === 'filters') clearFilters();
}

// Layer disclosure is the compact map's occasional state transition.
// Filters, keyboard toggles, and default-density map instances stay instant.
let animateLayers = false;
let layerAnimation;

function toggleLayers(event) {
    animateLayers = props.compact && event.detail > 0;
    layersOpen.value = !layersOpen.value;
}

function prepareLayerMotion(element) {
    const height = element.getBoundingClientRect().height;
    const opacity = height ? getComputedStyle(element).opacity : '0';
    if (layerAnimation) {
        layerAnimation.onfinish = null;
        layerAnimation.cancel();
        layerAnimation = null;
    }
    element.style.height = `${height}px`;
    element.style.opacity = opacity;
}

function transitionLayers(element, done, opening) {
    if (!animateLayers || !element.animate) {
        element.style.height = '';
        element.style.opacity = '';
        done();
        return;
    }
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const styles = getComputedStyle(element);
    const content = element.firstElementChild;
    const targetHeight = content.getBoundingClientRect().height + parseFloat(getComputedStyle(content).marginBottom);
    if (reduced) element.style.height = '';
    const frames = reduced
        ? [{ opacity: element.style.opacity }, { opacity: opening ? 1 : 0 }]
        : [{ height: element.style.height, opacity: element.style.opacity }, { height: `${opening ? targetHeight : 0}px`, opacity: opening ? 1 : 0 }];
    const durationToken = reduced ? '--cdrrmo-dash-motion-reduced' : opening ? '--cdrrmo-dash-motion-expand' : '--cdrrmo-dash-motion-collapse';
    const animation = element.animate(frames, {
        duration: parseFloat(styles.getPropertyValue(durationToken)),
        easing: styles.getPropertyValue('--resident-sidebar-ease').trim(),
    });
    layerAnimation = animation;
    animation.onfinish = () => {
        layerAnimation = null;
        element.style.height = '';
        element.style.opacity = '';
        done();
    };
}

function onKeydown(event) {
    if (event.key !== 'Escape') return;
    if (props.actionMenu && (event.defaultPrevented || event.target.closest?.('.bdrrm-community'))) return;
    if (actionsOpen.value) return;
    if (searchOpen.value) {
        searchOpen.value = false;
        return;
    }
    if (layersOpen.value) {
        animateLayers = false;
        layersOpen.value = false;
        return;
    }
    if (selection.featureType) clearFeatureSelection();
}

function syncFullscreen() {
    fullscreen.value = document.fullscreenElement === mapFrame.value;
}

onMounted(async () => {
    if (!props.preview) {
        const summaryRes = await window.axios.get(api(`/summary`));
        barangays.value = summaryRes.data.barangays;
        try {
            incidents.value = (await window.axios.get(api(`/incidents`))).data.data || [];
        } catch {
            incidents.value = [];
        }
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
    if (props.preview) {
        map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-right');
    } else {
        map.addControl(new mapboxgl.NavigationControl(), 'top-right');
        map.addControl(new mapboxgl.ScaleControl({ maxWidth: 120 }), 'bottom-left');
        map.addControl(new mapboxgl.FullscreenControl(props.actionMenu ? { container: mapFrame.value } : undefined), 'top-right');
        draw = new MapboxDraw({ displayControlsDefault: false });
        map.addControl(draw);
        map.on('draw.create', onDrawChange);
        map.on('draw.update', onDrawChange);
        map.on('draw.delete', onDrawChange);
    }
    map.on('load', async () => {
        await refreshLayers();
        if (community) community.load();
        if (props.preview) return;
        if (selectedBarangay.value) await selectBarangayBoundary();
        if (route.query.focus === 'residents') {
            await focusResidentSelection();
        } else if (route.query.building_id) {
            await selectBuilding(route.query.building_id, { locate: true });
        } else if (route.query.household_id) {
            await selectHousehold(route.query.household_id, { locate: true });
        }
    });

    resizeObserver = new ResizeObserver(() => map?.resize());
    if (mapEl.value) resizeObserver.observe(mapEl.value);
});

watch(() => props.barangayId, (value) => {
    if (!props.preview) return;
    previewShouldFit = true;
    selectedBarangay.value = value ? String(value) : '';
});
watch(() => [route.query.incident_id, route.query.barangay_id], ([incidentId, barangayId]) => {
    if (!props.compact || props.preview) return;
    selectedIncident.value = typeof incidentId === 'string' ? incidentId : '';
    selectedBarangay.value = typeof barangayId === 'string' ? barangayId : '';
});
watch(selectedBarangay, async () => {
    const ticket = ++barangayTicket;
    if (props.preview) previewShouldFit = true;
    await refreshLayers();
    if (props.preview || ticket !== barangayTicket) return;
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
watch(() => layers.value.residentSubmissions, async (on) => {
    if (!props.residentSubmissions || !on) return;
    try {
        setSource('resident-submissions', await fetchJson(api(`/map/resident-submissions`)));
    } catch {
        setSource('resident-submissions', { type: 'FeatureCollection', features: [] });
    }
});
watch(() => [route.query.lng, route.query.lat], () => focusReportedQuery());
watch(() => route.query.building_id, (buildingId) => {
    if (!map || props.preview || !buildingId) return;
    selectBuilding(buildingId, { locate: true });
});
watch(() => reportContext.state.coordinates, () => refreshOutsideFlag());

onBeforeUnmount(() => {
    layerAnimation?.cancel();
    clearTimeout(searchTimer);
    resizeObserver?.disconnect();
    window.removeEventListener('keydown', onKeydown);
    residentPopup?.remove();
    map?.remove();
});
</script>

<template>
    <div class="map-workspace" :class="{ 'is-compact': compact, 'is-embedded': embedded, 'is-preview': preview, 'is-cdrrmo-map': isCdrrmoMap }">
        <header v-if="!preview && actionMenu && !isAssessorMap" class="bdrrm-map-heading flex items-start justify-between gap-3">
            <slot name="heading" />
            <MapActionsMenu
                v-model:open="actionsOpen"
                :has-polygon="hasPolygon"
                :registry-open="layersOpen"
                :map-layers-open="communityOpen"
                :has-map-layers="!!community"
                @command="runMapAction"
            />
        </header>
        <div v-if="!preview" class="map-toolbar">
            <div class="map-toolbar-group is-filters" role="group" aria-label="Map filters">
                <div v-if="isCdrrmoMap" class="map-filter-heading">
                    <span class="map-toolbar-label">Map filters</span>
                    <Button size="small" severity="secondary" text type="button" class="map-filter-reset" @click="clearFilters"><MapIcon name="filter-reset" /> Clear filters</Button>
                </div>
                <span v-else-if="!actionMenu" class="map-toolbar-label">{{ compact ? 'Map view' : 'Filters' }}</span>
                <div class="map-control-field map-basemap-field">
                    <span v-if="isCdrrmoMap || actionMenu" class="map-control-field-label">Basemap</span>
                    <div class="map-mode-toggle" role="group" aria-label="Basemap">
                        <Button :size="controlSize" severity="secondary" outlined type="button" :aria-pressed="mapStyleMode === 'satellite'" :class="{ 'is-active': mapStyleMode === 'satellite' }" @click="switchMapStyle('satellite')">Satellite</Button>
                        <Button :size="controlSize" severity="secondary" outlined type="button" :aria-pressed="mapStyleMode === 'streets'" :class="{ 'is-active': mapStyleMode === 'streets' }" @click="switchMapStyle('streets')">Map</Button>
                    </div>
                </div>
                <div v-if="!compact" class="map-control-field">
                    <label v-if="isCdrrmoMap || actionMenu" class="map-control-field-label" :for="`${controlId}-incident-filter`">Incident</label>
                    <Select :input-id="`${controlId}-incident-filter`" v-model="selectedIncident" :options="[{ label: actionMenu ? 'Registry view' : 'Registry view (all incidents)', value: '' }, ...incidents.map((row) => ({ label: row.incident_name, value: String(row.id) }))]" option-label="label" option-value="value" aria-label="Incident or registry view" placeholder="Registry view" :size="controlSize" :pt="compact || actionMenu ? { transition: { css: false } } : undefined" :panel-class="filterPanelClass" />
                </div>
                <div v-if="!compact" class="map-control-field">
                    <label v-if="isCdrrmoMap || actionMenu" class="map-control-field-label" :for="`${controlId}-barangay-filter`">Barangay</label>
                    <Select :input-id="`${controlId}-barangay-filter`" v-model="selectedBarangay" :options="[{ label: barangayEmptyLabel, value: '' }, ...barangays.map((row) => ({ label: row.name, value: String(row.id) }))]" option-label="label" option-value="value" aria-label="Barangay" :placeholder="barangayEmptyLabel" :size="controlSize" :pt="compact || actionMenu ? { transition: { css: false } } : undefined" :panel-class="filterPanelClass" />
                </div>
                <div class="map-control-field map-search-field">
                    <label v-if="isCdrrmoMap || actionMenu" class="map-control-field-label" :for="`${controlId}-household-search`">{{ isAssessorMap ? 'Household or building reference' : 'Search records' }}</label>
                    <div class="map-search">
                        <MapIcon v-if="actionMenu" name="search" class="bdrrm-map-search-icon" />
                        <InputText :size="controlSize"
                            ref="searchInput"
                            :id="`${controlId}-household-search`"
                            v-model="searchRef"
                            type="search"
                            :placeholder="isAssessorMap ? 'Search by reference' : 'Household or building reference'"
                            aria-label="Search household or building reference"
                            @focus="searchOpen = !!searchRef.trim() && householdFeatures.length > 1"
                        />
                        <Button v-if="searchRef" :size="controlSize" severity="secondary" text type="button" class="map-search-clear" aria-label="Clear map search" @click="clearMapSearch"><MapIcon name="x" /></Button>
                        <ul v-if="searchOpen && searchRef.trim() && searchMatches.length" class="map-search-results" @mousedown.prevent>
                            <li v-for="feature in searchMatches" :key="feature.properties.household_id">
                                <Button :size="controlSize" severity="secondary" outlined type="button" @click="chooseSearchFeature(feature)">
                                    {{ feature.properties.household_reference }}
                                    <span v-if="feature.properties.building_reference"> · {{ feature.properties.building_reference }}</span>
                                </Button>
                            </li>
                            <li v-if="householdFeatures.length > searchMatches.length" class="cdrrmo-muted">Showing {{ searchMatches.length }} of {{ householdFeatures.length }} matches.</li>
                        </ul>
                    </div>
                </div>
                <label v-if="!(isCdrrmoMap || isAssessorMap) || selectedIncident" class="map-inline-check" :class="{ 'is-disabled': !selectedIncident }">
                    <Checkbox v-model="incidentLinkedOnly" binary :size="controlSize" :disabled="!selectedIncident" />
                    {{ isCdrrmoMap ? 'Only show households linked to this incident' : 'Incident-linked records only' }}
                </label>
            </div>
            <MapActionsMenu
                v-if="isAssessorMap"
                v-model:open="actionsOpen"
                :has-polygon="hasPolygon"
                :registry-open="layersOpen"
                panel-class="rdana-map-actions-menu"
                @command="runMapAction"
            />
            <div v-if="!actionMenu" class="map-toolbar-group is-actions" role="group" aria-label="Map actions">
                <span class="map-toolbar-label">Map actions</span>
                <Button :size="controlSize" severity="secondary" outlined type="button" class="map-tool-btn" @click="startDraw"><MapIcon name="polygon" /> Draw area</Button>
                <Button :size="controlSize" severity="secondary" outlined type="button" class="map-tool-btn" :disabled="!hasPolygon" @click="editDraw"><MapIcon name="pencil" /> Edit area</Button>
                <Button :size="controlSize" severity="secondary" outlined type="button" class="map-tool-btn" :disabled="!hasPolygon" @click="clearPolygon"><MapIcon name="eraser" /> Clear area</Button>
                <Button :size="controlSize" severity="secondary" outlined type="button" class="map-tool-btn" :disabled="!hasPolygon" @click="fitToSelection"><MapIcon name="focus" /> Fit to area</Button>
                <Button :size="controlSize" severity="secondary" outlined type="button" class="map-tool-btn" :aria-expanded="layersOpen" :aria-controls="`${controlId}-layers`" @click="toggleLayers"><MapIcon name="layers" /> Layers</Button>
                <Button :size="controlSize" severity="secondary" outlined v-if="community" type="button" class="map-tool-btn" :aria-expanded="communityOpen" @click="communityOpen = !communityOpen"><MapIcon name="layers" /> Hazard map</Button>
                <Button :size="controlSize" severity="secondary" outlined type="button" class="map-tool-btn" @click="resetStudyArea"><MapIcon name="reset" /> Reset view</Button>
                <Button v-if="!isCdrrmoMap" :size="controlSize" severity="secondary" outlined type="button" class="map-tool-btn" @click="clearFilters"><MapIcon name="filter-reset" /> Clear filters</Button>
            </div>
        </div>
        <Transition
            :css="false"
            @before-enter="prepareLayerMotion"
            @enter="(element, done) => transitionLayers(element, done, true)"
            @enter-cancelled="prepareLayerMotion"
            @before-leave="prepareLayerMotion"
            @leave="(element, done) => transitionLayers(element, done, false)"
            @leave-cancelled="prepareLayerMotion"
        >
            <div
                v-if="!preview"
                v-show="layersOpen"
                :id="`${controlId}-layers`"
                class="map-layer-disclosure"
                :inert="!layersOpen"
                :aria-hidden="!layersOpen"
            >
                <div class="cdrrmo-filters layer-panel" role="group" aria-label="Registry layers">
                    <label><Checkbox v-model="layers.boundaries" binary :size="controlSize" /> Barangay boundaries</label>
                    <label><Checkbox v-model="layers.households" binary :size="controlSize" /> Source-recorded households</label>
                    <label><Checkbox v-model="layers.flood" binary :size="controlSize" /> Flood hazard (historical)</label>
                    <label><Checkbox v-model="layers.fieldDamage" binary :size="controlSize" /> Field-verified damage</label>
                    <label><Checkbox v-model="layers.aiPredictions" binary :size="controlSize" /> Preliminary AI classification</label>
                    <label v-if="residentSubmissions"><Checkbox v-model="layers.residentSubmissions" binary :size="controlSize" /> Resident-reported points (not field verified)</label>
                </div>
            </div>
        </Transition>
        <p v-if="!actionMenu && loading">Loading map layers…</p>
        <p v-if="!actionMenu && error" class="cdrrmo-error">
            {{ error }}
            <Button :size="controlSize" severity="secondary" outlined v-if="preview" type="button" class="map-tool-btn" @click="refreshLayers">Retry</Button>
        </p>
        <div class="map-layout">
            <div ref="mapFrame" class="map-canvas-wrap" @fullscreenchange="syncFullscreen">
                <div v-if="preview" class="map-preview-tools">
                    <Button size="small" severity="secondary" outlined type="button" class="map-tool-btn" @click="resetPreview"><MapIcon name="reset" /> Reset view</Button>
                </div>
                <div ref="mapEl" class="map-canvas" :class="{ 'map-canvas-embedded': embedded }" />
                <MapLayersPanel v-if="community" :community="community" :open="communityOpen" :overlay-target="fullscreen ? mapFrame : null" @update:open="communityOpen = $event" />
                <div v-if="actionMenu" class="bdrrm-map-status" role="status">
                    <p v-if="error" class="is-error">{{ error }}</p>
                    <p v-else-if="loading">Loading map layers…</p>
                    <p v-else-if="(mapMeta.total ?? mapMeta.eligible_marker_count) === 0">{{ mapMeta.message_if_zero || mapMeta.message_if_empty || 'No source-recorded household locations are available.' }}</p>
                </div>
                <div v-else-if="!preview && !loading && (mapMeta.total ?? mapMeta.eligible_marker_count) === 0" class="map-empty-overlay">
                    <p>{{ mapMeta.message_if_zero || mapMeta.message_if_empty || 'No source-recorded household locations are available.' }}</p>
                </div>
            </div>
            <AreaSummaryPanel
                v-if="!preview || selection.featureType === 'resident-report'"
                :compact="compact || actionMenu"
                :resident-reports="residentSubmissions"
                :summary="summary"
                :summary-loading="summaryLoading"
                :has-polygon="hasPolygon"
                :area-name="areaName"
                :area-note="actionMenu ? summary?.note : undefined"
                :selection="selection"
                :show-impact="allowImpact"
                :single-clear="singleClear"
                :show-linked-area-records="!isAssessorMap"
                @preview-report="previewReport"
                @view-records="viewLinkedRecords"
                @view-households="viewLinkedRecords"
                @clear-feature="clearFeatureSelection"
                @back-to-building="backToBuilding"
                @select-household="(id) => selectHousehold(id, { fromBuilding: true })"
                @locate="locateSelection"
                @open-residents="openSelectedResidents"
                @choose-report="chooseLinkedReport"
                @set-tab="(tab) => { selection.tab = tab; }"
            >
                <template v-if="actionMenu" #empty>
                    <p v-if="isAssessorMap" class="rdana-map-empty-selection">Select a marker, choose a barangay, or draw an area to inspect it here.</p>
                    <div v-else class="bdrrm-map-no-selection">
                        <MapIcon name="pin" />
                        <h4>{{ selectedBarangay ? areaName : 'No location selected' }}</h4>
                        <p v-if="selectedBarangay">Select a marker or draw an area within this view.</p>
                        <p v-else>Select a marker, choose a barangay, or draw an area from Map Actions.</p>
                    </div>
                </template>
            </AreaSummaryPanel>
            <footer v-if="actionMenu" class="bdrrm-map-key" aria-label="Map legend">
                <ul class="flex flex-wrap items-center gap-x-3 gap-y-1" aria-label="Map markers">
                    <li :style="{ '--marker-color': markerColors.household }">Household</li>
                    <li :style="{ '--marker-color': markerColors.building }">Shared building</li>
                    <li v-if="residentSubmissions" :style="{ '--marker-color': markerColors.report }">Resident report</li>
                    <li v-if="community" style="--marker-color: #9a3412">Evacuation site</li>
                    <li class="is-assessment">Field-verified damage</li>
                    <li :style="{ '--marker-color': markerColors.preliminary }">Preliminary AI</li>
                    <li class="is-selected">Selected location</li>
                </ul>
                <p><template v-if="residentSubmissions">Resident reports are unverified. </template>Field damage colors reflect the selected incident’s assessments.</p>
                <p v-if="!isAssessorMap || (!loading && !error)" class="min-h-lh tabular-nums">
                    <span v-if="!loading && !error">Showing {{ mapMeta.returned ?? mapMeta.source_marker_count ?? 0 }} of {{ mapMeta.total ?? mapMeta.source_marker_count ?? 0 }} source-recorded households.</span>
                </p>
            </footer>
        </div>
        <p v-if="preview && !loading && previewEmpty" class="map-preview-empty">No reported locations to display.</p>
        <p v-if="preview" class="cdrrmo-muted map-legend">Navy circles are reported locations, not field-verified damage.</p>
        <p v-else-if="!actionMenu" class="cdrrmo-muted map-legend">
            Showing {{ mapMeta.returned ?? mapMeta.source_marker_count ?? 0 }} of {{ mapMeta.total ?? mapMeta.source_marker_count ?? 0 }} source-recorded households.
            Teal markers are for browsing. An amber outline marks the selection. Damage colors apply only when an assessment exists for the selected incident.
            <template v-if="residentSubmissions"> Navy circles are resident-reported locations and are not field verified.</template>
        </p>
    </div>
</template>
