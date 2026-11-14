<script setup>
import { computed } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    community: { type: Object, required: true },
    open: { type: Boolean, default: true },
});

const emit = defineEmits(['update:open']);

const state = computed(() => props.community.state);
const activeLayer = computed(() => state.value.layers.find((layer) => layer.id === state.value.activeId) || null);
const areaName = computed(() => state.value.areas.find((area) => area.psgc === state.value.areaPsgc)?.name || 'Tandag City');
const enabledCount = computed(() => {
    let count = 0;
    if (state.value.activeId) count += 1;
    if (state.value.showBoundaries) count += 1;
    if (state.value.showRivers) count += 1;
    if (state.value.showSites) count += 1;
    return count;
});
const matches = computed(() => {
    state.value.points;
    state.value.areaPsgc;
    state.value.showSites;
    const query = state.value.pickerQuery.trim().toLowerCase();
    return props.community.visiblePlaces().filter((feature) => {
        const name = String(feature.properties?.name || '').toLowerCase();
        return query === '' || name.includes(query);
    }).slice(0, 12);
});

function distance(meters) {
    if (!Number.isFinite(Number(meters))) return '';
    if (meters < 1000) return `${Math.round(meters)} m`;
    return `${(meters / 1000).toFixed(1)} km`;
}

function duration(seconds) {
    if (!Number.isFinite(Number(seconds))) return '';
    const minutes = Math.round(seconds / 60);
    if (minutes < 1) return 'less than a minute';
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    return `${hours} hr ${minutes % 60} min`;
}
</script>

<template>
    <aside class="bdrrm-community" :class="{ 'is-open': open, 'is-minimized': !open }" aria-label="Map Layers">
        <button v-if="!open" type="button" class="bdrrm-layers-pill" @click="emit('update:open', true)">
            <MapIcon name="layers" />
            Map Layers
            <span v-if="enabledCount" class="bdrrm-layers-count">{{ enabledCount }}</span>
            <MapIcon name="chevron" class="bdrrm-chevron-down" />
        </button>
        <div class="bdrrm-community-expanded" :hidden="!open">
            <header class="bdrrm-community-header">
                <MapIcon name="layers" />
                <h3>Map Layers</h3>
                <button type="button" aria-label="Minimize Map Layers" @click="emit('update:open', false)">
                    <MapIcon name="chevron" class="bdrrm-chevron-up" />
                </button>
            </header>
            <div class="bdrrm-community-body">
                <label class="bdrrm-field">
                    Hazard
                    <select :value="state.activeId" aria-label="Hazard layer" @change="community.setLayer($event.target.value)">
                        <option value="">None</option>
                        <option v-for="layer in state.layers" :key="layer.id" :value="layer.id">{{ layer.name }}</option>
                    </select>
                </label>
                <p v-if="state.layerError" class="cdrrmo-error">{{ state.layerError }}</p>
                <template v-if="activeLayer">
                    <fieldset class="bdrrm-checks">
                        <legend>Categories</legend>
                        <label v-for="item in activeLayer.legend" :key="item.value" class="bdrrm-check">
                            <input type="checkbox" :checked="state.severities.includes(item.value)" @change="community.toggleSeverity(item.value)">
                            {{ item.label || item.value }}
                        </label>
                    </fieldset>
                    <label class="bdrrm-field">
                        Opacity
                        <input type="range" min="0.15" max="0.8" step="0.05" :value="state.opacity" aria-label="Hazard opacity" @input="community.setOpacity($event.target.value)">
                    </label>
                    <ul class="bdrrm-legend" aria-label="Hazard legend">
                        <li v-for="item in activeLayer.legend" :key="item.value">
                            <span :style="{ background: item.color }" />
                            {{ item.label || item.value }}
                        </li>
                    </ul>
                </template>
                <details class="bdrrm-disclosure">
                    <summary>Reference layers</summary>
                    <label class="bdrrm-check">
                        <input type="checkbox" :checked="state.showBoundaries" @change="state.showBoundaries = $event.target.checked; community.paint()">
                        Barangay boundaries
                    </label>
                    <label class="bdrrm-check">
                        <input type="checkbox" :checked="state.showRivers" @change="state.showRivers = $event.target.checked; community.paint()">
                        Rivers
                    </label>
                </details>
                <section class="bdrrm-evacuation">
                    <h4>Evacuation sites</h4>
                    <label class="bdrrm-check">
                        <input type="checkbox" :checked="state.showSites" @change="state.showSites = $event.target.checked; community.syncPlaces(); community.paint()">
                        Show listed evacuation sites
                    </label>
                    <label class="bdrrm-field">
                        Area
                        <select :value="state.areaPsgc" aria-label="Evacuation area" @change="state.areaPsgc = $event.target.value; community.syncPlaces(); community.paint()">
                            <option value="">Tandag City</option>
                            <option v-for="area in state.areas" :key="area.psgc" :value="area.psgc">{{ area.name }}</option>
                        </select>
                    </label>
                    <div class="bdrrm-site-search">
                        <input
                            :value="state.pickerQuery"
                            type="search"
                            aria-label="Search listed evacuation sites"
                            placeholder="Search listed sites"
                            @focus="state.pickerOpen = true"
                            @input="state.pickerQuery = $event.target.value; state.pickerOpen = true"
                        >
                        <ul v-if="state.pickerOpen" class="map-search-results">
                            <li v-if="!matches.length" class="cdrrmo-muted">No listed evacuation sites are in this area.</li>
                            <li v-for="feature in matches" :key="feature.id || feature.properties.id">
                                <button type="button" @click="community.selectSite(feature)">{{ feature.properties.name }}</button>
                            </li>
                        </ul>
                    </div>
                    <p v-if="state.pointsError" class="cdrrmo-error">{{ state.pointsError }}</p>
                    <p v-else-if="state.sitesMessage" class="cdrrmo-muted">{{ state.sitesMessage }}</p>
                    <div class="bdrrm-route-actions">
                        <button type="button" class="mapr-button mapr-button-secondary" :disabled="state.locating" @click="community.ensureLocation(true)">Use my location</button>
                        <button type="button" class="mapr-button mapr-button-secondary" :disabled="state.finding" @click="community.findNearest()">Nearest site</button>
                    </div>
                    <div class="map-mode-toggle" role="group" aria-label="Travel mode">
                        <button type="button" :class="{ 'is-active': state.travelMode === 'walking' }" @click="state.travelMode = 'walking'">Walking</button>
                        <button type="button" :class="{ 'is-active': state.travelMode === 'driving' }" @click="state.travelMode = 'driving'">Driving</button>
                    </div>
                    <p v-if="state.locationMessage" class="cdrrmo-muted">{{ state.locationMessage }}</p>
                    <p v-if="state.nearestSummary">{{ state.nearestSummary }}</p>
                    <p v-if="state.straightLineMeters != null" class="cdrrmo-muted">Straight-line distance {{ distance(state.straightLineMeters) }}. This is separate from the route distance.</p>
                    <p v-if="state.routeError" class="cdrrmo-error">{{ state.routeError }}</p>
                    <p v-if="state.route?.distance != null">Route {{ distance(state.route.distance) }} · {{ duration(state.route.duration) }}</p>
                    <p class="cdrrmo-muted">Suggested route only. Live road-closure data is not available. This route is not a flood-free or safe path.</p>
                    <p class="cdrrmo-muted">Searching {{ areaName }}.</p>
                    <article v-if="state.site" class="bdrrm-site-card">
                        <button type="button" @click="state.siteOpen = !state.siteOpen">
                            {{ state.site.name || 'Listed evacuation site' }}
                        </button>
                        <div v-show="state.siteOpen">
                            <p>{{ state.site.category_label || 'Listed evacuation site' }}</p>
                            <p v-if="state.site.source_barangay">{{ state.site.source_barangay }}</p>
                            <p>{{ state.site.availability || 'Availability not confirmed.' }}</p>
                            <div class="bdrrm-route-actions">
                                <button type="button" class="mapr-button mapr-button-primary" :disabled="state.routing" @click="community.getDirections()">Directions</button>
                                <button type="button" class="mapr-button mapr-button-secondary" @click="community.clearRoute()">Clear route</button>
                                <button type="button" class="mapr-button mapr-button-secondary" @click="community.selectSite(null)">Close</button>
                            </div>
                        </div>
                    </article>
                </section>
                <details class="bdrrm-disclosure">
                    <summary>About this data</summary>
                    <p v-if="state.note" class="cdrrmo-muted">{{ state.note }}</p>
                    <p v-if="state.site?.source" class="cdrrmo-muted">
                        {{ state.site.source }}<template v-if="state.site.dataset_date"> · {{ state.site.dataset_date }}</template>
                    </p>
                </details>
            </div>
        </div>
    </aside>
</template>
