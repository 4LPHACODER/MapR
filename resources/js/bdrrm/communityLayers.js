import { reactive } from 'vue';
import mapboxgl from 'mapbox-gl';

function emptyCollection() {
    return { type: 'FeatureCollection', features: [] };
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

function markerImage(selected) {
    const canvas = document.createElement('canvas');
    canvas.width = selected ? 112 : 88;
    canvas.height = canvas.width;
    const context = canvas.getContext('2d');
    const center = canvas.width / 2;
    const radius = selected ? 46 : 36;
    context.fillStyle = selected ? '#9a3412' : '#b45309';
    context.beginPath();
    context.arc(center, center, radius, 0, Math.PI * 2);
    context.fill();
    context.lineWidth = 6;
    context.strokeStyle = '#fff';
    context.stroke();
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

export function createCommunityLayers(apiBase) {
    const state = reactive({
        note: '',
        layers: [],
        activeId: '',
        opacity: 0.45,
        severities: [],
        layerError: '',
        loading: false,
        points: [],
        pointsError: '',
        sitesMessage: '',
        showSites: true,
        areaPsgc: '',
        areas: [],
        boundaries: [],
        pickerQuery: '',
        pickerOpen: false,
        site: null,
        siteOpen: true,
        locationMessage: '',
        locating: false,
        travelMode: 'walking',
        route: null,
        routeError: '',
        nearestSummary: '',
        straightLineMeters: null,
        routing: false,
        finding: false,
        showBoundaries: false,
        showRivers: false,
    });

    let map = null;
    let marker = null;
    let position = null;
    let routeToken = 0;
    let locationRequest = 0;
    let layerToken = 0;
    const layerCache = new Map();
    let hazardData = emptyCollection();
    let boundaryData = emptyCollection();
    let riverData = emptyCollection();
    let routeGeometry = null;
    let accuracyData = emptyCollection();

    function visiblePlaces() {
        if (!state.showSites) return [];
        return state.points.filter((feature) => {
            if (!state.areaPsgc) return true;
            const boundary = state.boundaries.find((item) => item.properties?.psgc === state.areaPsgc);
            if (!boundary) return true;
            const [lng, lat] = feature.geometry?.coordinates || [];
            return featureContains(boundary, lng, lat);
        });
    }

    function paint() {
        if (!map?.getLayer('hazard-fill')) return;
        const visible = state.activeId ? 'visible' : 'none';
        map.setLayoutProperty('hazard-fill', 'visibility', visible);
        map.setLayoutProperty('hazard-line', 'visibility', visible);
        const layer = state.layers.find((item) => item.id === state.activeId);
        const pairs = (layer?.legend || []).flatMap((item) => [item.value, item.color]);
        const color = pairs.length ? ['match', ['get', 'category'], ...pairs, '#94a3b8'] : '#94a3b8';
        map.setPaintProperty('hazard-fill', 'fill-color', color);
        map.setPaintProperty('hazard-fill', 'fill-opacity', Number(state.opacity));
        map.setPaintProperty('hazard-line', 'line-color', color);
        const legend = layer?.legend || [];
        const subset = state.severities.length > 0 && state.severities.length < legend.length;
        const filter = subset ? ['in', ['get', 'category'], ['literal', [...state.severities]]] : null;
        map.setFilter('hazard-fill', filter);
        map.setFilter('hazard-line', filter);
        const showBoundary = state.showBoundaries || Boolean(state.areaPsgc);
        if (map.getLayer('community-boundary-line')) {
            map.setLayoutProperty('community-boundary-line', 'visibility', showBoundary ? 'visible' : 'none');
            map.setFilter('community-boundary-line', state.showBoundaries ? null : ['==', ['get', 'psgc'], state.areaPsgc || '']);
        }
        if (map.getLayer('community-river-fill')) {
            const rivers = state.showRivers ? 'visible' : 'none';
            map.setLayoutProperty('community-river-fill', 'visibility', rivers);
            map.setLayoutProperty('community-river-line', 'visibility', rivers);
        }
        const places = state.showSites ? 'visible' : 'none';
        ['place-clusters', 'place-count', 'place-points', 'place-labels', 'place-label-selected'].forEach((id) => {
            if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', places);
        });
    }

    function syncPlaces() {
        const features = visiblePlaces();
        map?.getSource('places')?.setData({ type: 'FeatureCollection', features });
        syncSelection();
    }

    function syncSelection() {
        if (!map?.getLayer('place-points')) return;
        const id = state.site?.id || '';
        map.setLayoutProperty('place-points', 'icon-image', ['case', ['==', ['get', 'id'], id], 'evacuation-selected', 'evacuation']);
        if (map.getLayer('place-labels')) {
            map.setFilter('place-labels', ['all', ['!', ['has', 'point_count']], ['!=', ['get', 'id'], id || '__none__']]);
        }
        if (map.getLayer('place-label-selected')) {
            map.setFilter('place-label-selected', ['==', ['get', 'id'], id || '__none__']);
        }
    }

    function addImages() {
        if (!map) return;
        if (!map.hasImage('evacuation')) map.addImage('evacuation', markerImage(false), { pixelRatio: 2 });
        if (!map.hasImage('evacuation-selected')) map.addImage('evacuation-selected', markerImage(true), { pixelRatio: 2 });
    }

    function addSources() {
        if (!map || map.getSource('hazard')) return;
        addImages();
        map.addSource('hazard', { type: 'geojson', data: hazardData });
        map.addLayer({ id: 'hazard-fill', type: 'fill', source: 'hazard', paint: { 'fill-color': '#bfdbfe', 'fill-opacity': Number(state.opacity) } });
        map.addLayer({ id: 'hazard-line', type: 'line', source: 'hazard', paint: { 'line-color': '#1e3a8a', 'line-width': 1 } });
        map.addSource('public-boundaries', { type: 'geojson', data: boundaryData });
        map.addLayer({ id: 'community-boundary-line', type: 'line', source: 'public-boundaries', layout: { visibility: 'none' }, paint: { 'line-color': '#123b70', 'line-width': 1.5 } });
        map.addSource('public-rivers', { type: 'geojson', data: riverData });
        map.addLayer({ id: 'community-river-fill', type: 'fill', source: 'public-rivers', layout: { visibility: 'none' }, paint: { 'fill-color': '#38bdf8', 'fill-opacity': 0.35 } });
        map.addLayer({ id: 'community-river-line', type: 'line', source: 'public-rivers', layout: { visibility: 'none' }, paint: { 'line-color': '#0369a1', 'line-width': 1.25 } });
        map.addSource('community-route', { type: 'geojson', data: routeGeometry ? { type: 'Feature', geometry: routeGeometry, properties: {} } : emptyCollection() });
        map.addLayer({ id: 'route-casing', type: 'line', source: 'community-route', paint: { 'line-color': '#ffffff', 'line-width': 8 } });
        map.addLayer({ id: 'route-line', type: 'line', source: 'community-route', paint: { 'line-color': '#9a3412', 'line-width': 4 } });
        map.addSource('places', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: visiblePlaces() },
            cluster: true,
            clusterRadius: 50,
            clusterMaxZoom: 16,
        });
        map.addLayer({ id: 'place-clusters', type: 'circle', source: 'places', filter: ['has', 'point_count'], paint: { 'circle-color': '#9a3412', 'circle-radius': 22, 'circle-stroke-width': 3, 'circle-stroke-color': '#fff' } });
        map.addLayer({ id: 'place-count', type: 'symbol', source: 'places', filter: ['has', 'point_count'], layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-size': 13, 'text-font': ['DIN Pro Medium', 'Arial Unicode MS Regular'] }, paint: { 'text-color': '#fff' } });
        map.addLayer({ id: 'place-points', type: 'symbol', source: 'places', filter: ['!', ['has', 'point_count']], layout: { 'icon-image': 'evacuation', 'icon-size': 1, 'icon-allow-overlap': true } });
        map.addLayer({
            id: 'place-labels',
            type: 'symbol',
            source: 'places',
            minzoom: 14,
            filter: ['!', ['has', 'point_count']],
            layout: { 'text-field': ['get', 'name'], 'text-size': 12, 'text-font': ['DIN Pro Medium', 'Arial Unicode MS Regular'], 'text-offset': [0, 1.45], 'text-anchor': 'top', 'text-max-width': 10, 'text-optional': true },
            paint: { 'text-color': '#1e293b', 'text-halo-color': '#fff', 'text-halo-width': 1.5 },
        });
        map.addLayer({
            id: 'place-label-selected',
            type: 'symbol',
            source: 'places',
            filter: ['==', ['get', 'id'], ''],
            layout: { 'text-field': ['get', 'name'], 'text-size': 13, 'text-font': ['DIN Pro Medium', 'Arial Unicode MS Regular'], 'text-offset': [0, 1.6], 'text-anchor': 'top', 'text-max-width': 12, 'text-allow-overlap': true },
            paint: { 'text-color': '#7c2d12', 'text-halo-color': '#fff', 'text-halo-width': 1.5 },
        });
        map.addSource('community-accuracy', { type: 'geojson', data: accuracyData });
        map.addLayer({ id: 'accuracy-fill', type: 'fill', source: 'community-accuracy', paint: { 'fill-color': '#2563eb', 'fill-opacity': 0.15 } });
        map.addLayer({ id: 'accuracy-line', type: 'line', source: 'community-accuracy', paint: { 'line-color': '#2563eb', 'line-width': 1.5 } });
        map.off('click', 'place-points', onPlaceClick);
        map.off('click', 'place-clusters', onClusterClick);
        map.on('click', 'place-points', onPlaceClick);
        map.on('click', 'place-clusters', onClusterClick);
        paint();
        syncSelection();
        if (marker && position) marker.addTo(map);
    }

    function onPlaceClick(event) {
        const feature = event.features?.[0];
        if (!feature) return;
        const properties = feature.properties || {};
        state.site = {
            ...properties,
            id: properties.id || feature.id,
            coordinates: feature.geometry?.coordinates,
        };
        state.siteOpen = true;
        state.pickerOpen = false;
        syncSelection();
    }

    function onClusterClick(event) {
        const feature = event.features?.[0];
        const clusterId = feature?.properties?.cluster_id;
        const source = map?.getSource('places');
        if (!feature || clusterId == null || !source?.getClusterExpansionZoom) return;
        source.getClusterExpansionZoom(clusterId, (error, zoom) => {
            if (!error) map?.easeTo({ center: feature.geometry.coordinates, zoom });
        });
    }

    async function loadCatalog() {
        try {
            const catalog = (await window.axios.get(`${apiBase}/map/hazards`)).data;
            state.note = catalog.note || '';
            state.layers = catalog.layers || [];
            state.areas = catalog.areas || [];
            if (state.activeId && !state.layers.some((layer) => layer.id === state.activeId)) state.activeId = '';
        } catch (exception) {
            state.layers = [];
            state.layerError = exception.response?.data?.message || 'Hazard layers are not available.';
        }
    }

    async function loadBoundaries() {
        try {
            const collection = (await window.axios.get(`${apiBase}/map/hazard-boundaries`)).data;
            boundaryData = collection;
            state.boundaries = collection.features || [];
            map?.getSource('public-boundaries')?.setData(collection);
            paint();
        } catch {
            state.boundaries = [];
        }
    }

    async function loadRivers() {
        try {
            riverData = (await window.axios.get(`${apiBase}/map/rivers`)).data;
            map?.getSource('public-rivers')?.setData(riverData);
            paint();
        } catch {
            riverData = emptyCollection();
        }
    }

    async function loadPlaces() {
        state.pointsError = '';
        state.sitesMessage = '';
        try {
            const collection = (await window.axios.get(`${apiBase}/map/evacuation-sites`)).data;
            state.points = (collection.features || []).filter((feature) => feature.properties?.category === 'evacuation');
            state.sitesMessage = state.points.length ? '' : (collection.message || 'No verified evacuation-site list is available.');
        } catch (exception) {
            state.points = [];
            state.pointsError = exception.response?.data?.message || 'No verified evacuation-site list is available.';
        }
        syncPlaces();
    }

    async function showActiveLayer() {
        const token = ++layerToken;
        if (!map?.getSource('hazard')) return;
        if (!state.activeId) {
            hazardData = emptyCollection();
            map.getSource('hazard').setData(hazardData);
            paint();
            state.loading = false;
            return;
        }
        state.layerError = '';
        state.loading = true;
        try {
            if (!layerCache.has(state.activeId)) {
                layerCache.set(state.activeId, (await window.axios.get(`${apiBase}/map/hazards/${state.activeId}`)).data);
            }
            if (token !== layerToken || !map?.getSource('hazard')) return;
            hazardData = layerCache.get(state.activeId);
            map.getSource('hazard').setData(hazardData);
            paint();
        } catch (exception) {
            if (token !== layerToken) return;
            hazardData = emptyCollection();
            map?.getSource('hazard')?.setData(hazardData);
            state.layerError = exception.response?.data?.message || 'This hazard layer is not available.';
        } finally {
            if (token === layerToken) state.loading = false;
        }
    }

    function setLayer(id) {
        state.activeId = id;
        state.layerError = '';
        const layer = state.layers.find((item) => item.id === id);
        state.severities = (layer?.legend || []).map((item) => item.value);
        showActiveLayer();
    }

    function toggleSeverity(value) {
        if (state.severities.includes(value)) {
            state.severities = state.severities.filter((item) => item !== value);
        } else {
            state.severities = [...state.severities, value];
        }
        paint();
    }

    function setOpacity(value) {
        const number = Number(value);
        state.opacity = Math.min(0.8, Math.max(0.15, number));
        paint();
    }

    function selectSite(feature) {
        if (!feature) {
            state.site = null;
            syncSelection();
            return;
        }
        state.site = {
            ...(feature.properties || {}),
            id: feature.id || feature.properties?.id,
            coordinates: feature.geometry?.coordinates,
        };
        state.siteOpen = true;
        state.pickerOpen = false;
        syncSelection();
        if (map && state.site.coordinates) {
            map.easeTo({ center: state.site.coordinates, zoom: Math.max(map.getZoom(), 15) });
        }
    }

    function drawRoute(geometry) {
        routeGeometry = geometry || null;
        map?.getSource('community-route')?.setData(geometry
            ? { type: 'Feature', geometry, properties: {} }
            : emptyCollection());
    }

    function fitRoute(geometry) {
        const coordinates = geometry?.coordinates || [];
        if (!map || coordinates.length < 2) return;
        const bounds = coordinates.reduce((current, point) => [
            [Math.min(current[0][0], point[0]), Math.min(current[0][1], point[1])],
            [Math.max(current[1][0], point[0]), Math.max(current[1][1], point[1])],
        ], [[coordinates[0][0], coordinates[0][1]], [coordinates[0][0], coordinates[0][1]]]);
        map.fitBounds(bounds, { padding: 48, duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 500 });
    }

    function ensureLocation(moveCamera) {
        state.locationMessage = '';
        if (!navigator.geolocation) {
            state.locationMessage = 'Location is not available in this browser.';
            return Promise.resolve(null);
        }
        const token = ++locationRequest;
        state.locating = true;
        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition((result) => {
                if (token !== locationRequest) {
                    resolve(null);
                    return;
                }
                state.locating = false;
                const { longitude, latitude, accuracy } = result.coords;
                position = { lng: longitude, lat: latitude, meters: accuracy || 0 };
                if (!marker) {
                    const element = document.createElement('div');
                    element.className = 'bdrrm-location-dot';
                    marker = new mapboxgl.Marker({ element }).setLngLat([longitude, latitude]);
                } else {
                    marker.setLngLat([longitude, latitude]);
                }
                marker.addTo(map);
                accuracyData = { type: 'FeatureCollection', features: [accuracyPolygon(longitude, latitude, accuracy || 0)] };
                map?.getSource('community-accuracy')?.setData(accuracyData);
                if (moveCamera) map?.easeTo({ center: [longitude, latitude], zoom: Math.max(map.getZoom(), 14) });
                if ((accuracy || 0) > 100) state.locationMessage = `Location accuracy is limited to about ${Math.round(accuracy)} meters.`;
                resolve(position);
            }, (error) => {
                if (token !== locationRequest) {
                    resolve(null);
                    return;
                }
                state.locating = false;
                state.locationMessage = error.code === 1
                    ? 'Location permission was denied. You can still move around the map.'
                    : 'Your position is unavailable. You can still move around the map.';
                resolve(null);
            }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
        });
    }

    async function findNearest() {
        const token = ++routeToken;
        state.routing = false;
        state.finding = true;
        state.routeError = '';
        const areaName = state.areas.find((area) => area.psgc === state.areaPsgc)?.name || 'the selected barangay';
        const candidates = visiblePlaces();
        state.nearestSummary = state.areaPsgc
            ? `Searching listed evacuation sites in ${areaName}.`
            : 'Searching listed evacuation sites in Tandag City.';
        const located = await ensureLocation(false);
        if (token !== routeToken) return;
        if (!located) {
            state.finding = false;
            state.nearestSummary = '';
            return;
        }
        if (state.areaPsgc && candidates.length === 0) {
            state.finding = false;
            state.nearestSummary = '';
            state.routeError = `No listed evacuation sites are in ${areaName}.`;
            return;
        }
        try {
            const payload = {
                origin: [located.lng, located.lat],
                profile: state.travelMode,
                client_token: token,
            };
            if (state.areaPsgc) {
                payload.site_ids = candidates.map((feature) => feature.id || feature.properties?.id).filter(Boolean).slice(0, 100);
                payload.area_name = areaName;
            }
            const { data } = await window.axios.post(`${apiBase}/map/nearest-site`, payload);
            if (token !== routeToken || Number(data.client_token) !== token) return;
            const feature = data.site;
            state.site = { ...feature.properties, id: feature.id, coordinates: feature.geometry.coordinates };
            state.siteOpen = true;
            const scope = data.restricted_to_barangay
                ? `Nearest-site search is limited to ${data.search_area}.`
                : `Search area: ${data.search_area}.`;
            state.nearestSummary = [data.summary, scope, data.closure_note].filter(Boolean).join(' ');
            state.straightLineMeters = data.method === 'straight-line' ? data.straight_line_meters : null;
            state.route = data.route?.geometry ? data.route : null;
            state.routeError = data.route && !data.route.geometry ? (data.route.message || '') : '';
            if (data.route?.geometry) {
                drawRoute(data.route.geometry);
                fitRoute(data.route.geometry);
            } else {
                drawRoute(null);
                map?.easeTo({ center: feature.geometry.coordinates, zoom: Math.max(map.getZoom(), 15) });
            }
            syncSelection();
        } catch (exception) {
            if (token !== routeToken) return;
            state.nearestSummary = '';
            state.routeError = exception.response?.data?.message || 'The nearest listed site could not be found.';
        } finally {
            if (token === routeToken) state.finding = false;
        }
    }

    async function getDirections() {
        if (!state.site?.coordinates) return;
        const token = ++routeToken;
        state.finding = false;
        state.routeError = '';
        state.routing = true;
        const located = await ensureLocation(false);
        if (token !== routeToken) return;
        if (!located) {
            state.routing = false;
            return;
        }
        try {
            const { data } = await window.axios.post(`${apiBase}/map/routes`, {
                origin: [located.lng, located.lat],
                destination: state.site.coordinates,
                profile: state.travelMode,
                client_token: token,
            });
            if (token !== routeToken || Number(data.client_token) !== token) return;
            state.route = data;
            state.nearestSummary = '';
            state.straightLineMeters = null;
            if (data.geometry) {
                drawRoute(data.geometry);
                fitRoute(data.geometry);
            } else {
                drawRoute(null);
                state.routeError = data.message || 'No route was returned for this site.';
            }
        } catch (exception) {
            if (token !== routeToken) return;
            state.route = null;
            drawRoute(null);
            state.routeError = exception.response?.data?.message || 'Directions are unavailable right now.';
        } finally {
            if (token === routeToken) state.routing = false;
        }
    }

    function clearRoute() {
        routeToken += 1;
        state.routing = false;
        state.finding = false;
        state.route = null;
        state.routeError = '';
        state.nearestSummary = '';
        state.straightLineMeters = null;
        drawRoute(null);
    }

    function bind(nextMap) {
        map = nextMap;
        addSources();
        if (hazardData.features?.length || state.activeId) map.getSource('hazard')?.setData(hazardData);
        if (boundaryData.features?.length) map.getSource('public-boundaries')?.setData(boundaryData);
        if (riverData.features?.length) map.getSource('public-rivers')?.setData(riverData);
        drawRoute(routeGeometry);
        map.getSource('community-accuracy')?.setData(accuracyData);
        syncPlaces();
        paint();
    }

    async function load() {
        await Promise.all([loadCatalog(), loadBoundaries(), loadRivers(), loadPlaces()]);
        if (state.activeId) await showActiveLayer();
    }

    return {
        state,
        visiblePlaces,
        bind,
        load,
        setLayer,
        toggleSeverity,
        setOpacity,
        selectSite,
        syncPlaces,
        paint,
        ensureLocation,
        findNearest,
        getDirections,
        clearRoute,
    };
}
