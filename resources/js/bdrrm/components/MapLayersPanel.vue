<script setup>
import { computed, ref, useId } from 'vue';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Slider from 'primevue/slider';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    community: { type: Object, required: true },
    open: { type: Boolean, default: true },
    overlayTarget: { type: Object, default: null },
});

const emit = defineEmits(['update:open']);
const panelId = useId();
const siteSearch = ref(null);
const selectPt = { transition: { css: false } };

const state = computed(() => props.community.state);
const activeLayer = computed(() => state.value.layers.find((layer) => layer.id === state.value.activeId) || null);
const hazardOptions = computed(() => [{ id: '', name: 'None' }, ...state.value.layers]);
const areaOptions = computed(() => [{ psgc: '', name: 'Tandag City' }, ...state.value.areas]);
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

function clearSiteSearch() {
    state.value.pickerQuery = '';
    siteSearch.value?.$el?.focus();
}
</script>

<template>
    <aside class="bdrrm-community" :class="{ 'is-open': open }" aria-label="Map Layers">
        <Button size="small" type="button" severity="secondary" outlined class="bdrrm-layers-pill" :aria-label="enabledCount ? `Map Layers (${enabledCount} enabled)` : 'Map Layers'" :aria-expanded="open" :aria-controls="panelId" @click="emit('update:open', !open)">
            <MapIcon name="layers" />
            Map Layers
            <span v-if="enabledCount" class="bdrrm-layers-count" aria-hidden="true">{{ enabledCount }}</span>
            <MapIcon name="chevron" :class="open ? 'bdrrm-chevron-up' : 'bdrrm-chevron-down'" />
        </Button>
        <div v-show="open" :id="panelId" class="bdrrm-community-body">
            <div class="bdrrm-field">
                <label :id="`${panelId}-hazard-label`" :for="`${panelId}-hazard`">Hazard layer</label>
                <Select
                    :input-id="`${panelId}-hazard`"
                    :aria-labelledby="`${panelId}-hazard-label`"
                    :model-value="state.activeId"
                    :options="hazardOptions"
                    option-label="name"
                    option-value="id"
                    placeholder="None"
                    size="small"
                    :append-to="overlayTarget || 'body'"
                    panel-class="bdrrm-map-select"
                    :pt="selectPt"
                    @update:model-value="community.setLayer"
                />
            </div>
            <p v-if="state.layerError" class="cdrrmo-error">{{ state.layerError }}</p>
            <template v-if="activeLayer">
                <fieldset class="bdrrm-checks">
                    <legend>Categories</legend>
                    <label v-for="item in activeLayer.legend" :key="item.value" class="bdrrm-check">
                        <Checkbox size="small" binary :model-value="state.severities.includes(item.value)" @update:model-value="community.toggleSeverity(item.value)" />
                        {{ item.label || item.value }}
                    </label>
                </fieldset>
                <div class="bdrrm-field">
                    <span :id="`${panelId}-opacity`">Opacity <span class="font-normal tabular-nums">{{ Math.round(state.opacity * 100) }}%</span></span>
                    <Slider :model-value="Math.round(state.opacity * 100)" :min="15" :max="80" :step="5" :aria-labelledby="`${panelId}-opacity`" @update:model-value="community.setOpacity($event / 100)" />
                </div>
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
                    <Checkbox v-model="state.showBoundaries" size="small" binary @change="community.paint()" />
                    Barangay boundaries
                </label>
                <label class="bdrrm-check">
                    <Checkbox v-model="state.showRivers" size="small" binary @change="community.paint()" />
                    Rivers
                </label>
            </details>
            <section class="bdrrm-evacuation">
                <h4>Evacuation sites</h4>
                <label class="bdrrm-check">
                    <Checkbox v-model="state.showSites" size="small" binary @change="community.syncPlaces(); community.paint()" />
                    Show listed evacuation sites
                </label>
                <div class="bdrrm-field">
                    <label :id="`${panelId}-area-label`" :for="`${panelId}-area`">Evacuation area</label>
                    <Select
                        :input-id="`${panelId}-area`"
                        :aria-labelledby="`${panelId}-area-label`"
                        v-model="state.areaPsgc"
                        :options="areaOptions"
                        option-label="name"
                        option-value="psgc"
                        placeholder="Tandag City"
                        size="small"
                        :append-to="overlayTarget || 'body'"
                        panel-class="bdrrm-map-select"
                        :pt="selectPt"
                        @change="community.syncPlaces(); community.paint()"
                    />
                </div>
                <div class="map-search bdrrm-site-search" @keydown.esc.stop="state.pickerOpen = false">
                    <InputText
                        ref="siteSearch"
                        v-model="state.pickerQuery"
                        size="small"
                        type="search"
                        aria-label="Search listed evacuation sites"
                        placeholder="Search listed sites"
                        @focus="state.pickerOpen = true"
                        @input="state.pickerOpen = true"
                    />
                    <Button v-if="state.pickerQuery" size="small" severity="secondary" text type="button" class="map-search-clear" aria-label="Clear evacuation site search" @click="clearSiteSearch"><MapIcon name="x" /></Button>
                    <ul v-if="state.pickerOpen" class="map-search-results">
                        <li v-if="!matches.length" class="cdrrmo-muted">No listed evacuation sites are in this area.</li>
                        <li v-for="feature in matches" :key="feature.id || feature.properties.id">
                            <Button size="small" severity="secondary" text type="button" @click="community.selectSite(feature)">{{ feature.properties.name }}</Button>
                        </li>
                    </ul>
                </div>
                <p v-if="state.pointsError" class="cdrrmo-error">{{ state.pointsError }}</p>
                <p v-else-if="state.sitesMessage" class="cdrrmo-muted">{{ state.sitesMessage }}</p>
                <div class="bdrrm-route-actions">
                    <Button size="small" severity="secondary" outlined type="button" :disabled="state.locating" @click="community.ensureLocation(true)">Use my location</Button>
                    <Button size="small" severity="secondary" outlined type="button" :disabled="state.finding" @click="community.findNearest()">Nearest site</Button>
                </div>
                <div class="map-mode-toggle" role="group" aria-label="Travel mode">
                    <Button size="small" severity="secondary" outlined type="button" :aria-pressed="state.travelMode === 'walking'" @click="state.travelMode = 'walking'">Walking</Button>
                    <Button size="small" severity="secondary" outlined type="button" :aria-pressed="state.travelMode === 'driving'" @click="state.travelMode = 'driving'">Driving</Button>
                </div>
                <p v-if="state.locationMessage" class="cdrrmo-muted">{{ state.locationMessage }}</p>
                <p v-if="state.nearestSummary">{{ state.nearestSummary }}</p>
                <p v-if="state.straightLineMeters != null" class="cdrrmo-muted">Straight-line distance {{ distance(state.straightLineMeters) }}. This is separate from the route distance.</p>
                <p v-if="state.routeError" class="cdrrmo-error">{{ state.routeError }}</p>
                <p v-if="state.route?.distance != null">Route {{ distance(state.route.distance) }} · {{ duration(state.route.duration) }}</p>
                <p class="cdrrmo-muted">Suggested route only. Live road-closure data is not available. This route is not a flood-free or safe path.</p>
                <p class="cdrrmo-muted">Searching {{ areaName }}.</p>
                <article v-if="state.site" class="bdrrm-site-card">
                    <Button size="small" severity="secondary" text type="button" :aria-expanded="state.siteOpen" @click="state.siteOpen = !state.siteOpen">
                        {{ state.site.name || 'Listed evacuation site' }}
                    </Button>
                    <div v-show="state.siteOpen">
                        <p>{{ state.site.category_label || 'Listed evacuation site' }}</p>
                        <p v-if="state.site.source_barangay">{{ state.site.source_barangay }}</p>
                        <p>{{ state.site.availability || 'Availability not confirmed.' }}</p>
                        <div class="bdrrm-route-actions">
                            <Button size="small" type="button" :disabled="state.routing" @click="community.getDirections()">Directions</Button>
                            <Button size="small" severity="secondary" outlined type="button" @click="community.clearRoute()">Clear route</Button>
                            <Button size="small" severity="secondary" text type="button" @click="community.selectSite(null)">Close</Button>
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
    </aside>
</template>
