<script setup>
import { computed } from 'vue';
import MapIcon from './MapIcon.vue';

const props = defineProps({
    summary: { type: Object, default: null },
    summaryLoading: { type: Boolean, default: false },
    hasPolygon: { type: Boolean, default: false },
    areaName: { type: String, default: 'Current selected area' },
    selection: { type: Object, required: true },
    showImpact: { type: Boolean, default: true },
});

defineEmits([
    'preview-report',
    'view-records',
    'view-households',
    'clear-feature',
    'back-to-building',
    'select-household',
    'locate',
    'open-residents',
    'set-tab',
]);

const household = computed(() => props.selection.household);
const building = computed(() => props.selection.building);

const showTabs = computed(() => props.hasPolygon && !!props.selection.featureType);

const view = computed(() => {
    if (props.selection.featureType && (!props.hasPolygon || props.selection.tab === 'feature')) {
        return 'feature';
    }
    if (props.hasPolygon) {
        return 'area';
    }
    return 'empty';
});

const featureTabLabel = computed(() => (props.selection.featureType === 'building' ? 'Selected building' : 'Selected household'));

const title = computed(() => {
    if (view.value === 'feature' && props.selection.featureType === 'building') return 'Building details';
    if (view.value === 'feature') return 'Household details';
    if (view.value === 'area') return 'Selected area summary';
    return 'Selection summary';
});

const headerIcon = computed(() => {
    if (view.value === 'feature' && props.selection.featureType === 'building') return 'building';
    if (view.value === 'feature') return 'home';
    if (view.value === 'area') return 'polygon';
    return 'map';
});

const subtitle = computed(() => {
    if (view.value === 'feature' && props.selection.loading) return 'Loading…';
    if (view.value === 'feature' && props.selection.featureType === 'building') {
        return building.value?.reference || 'Building';
    }
    if (view.value === 'feature') {
        return household.value?.household_reference || 'Household';
    }
    if (view.value === 'area') {
        if (props.areaName && props.areaName !== 'Current selected area') return props.areaName;
        const names = props.summary?.barangay_coverage;
        if (Array.isArray(names) && names.length) return names.join(', ');
        return 'Current selected area';
    }
    return 'Choose what to inspect';
});

const showBack = computed(() => view.value === 'feature'
    && props.selection.featureType === 'household'
    && !!building.value?.id);

const registry = computed(() => props.summary?.source_registry_unverified || {});
const official = computed(() => props.summary?.official || {});

const officialSmaller = computed(() => {
    const sourceTotal = Number(registry.value.buildings || 0) + Number(registry.value.households || 0) + Number(registry.value.residents || 0);
    const officialTotal = Number(official.value.buildings || 0) + Number(official.value.households || 0) + Number(official.value.residents || 0);
    return sourceTotal > officialTotal;
});

const rosterIncomplete = computed(() => {
    const size = household.value?.source_household_size;
    const linked = household.value?.linked_resident_count;
    return size != null && size !== '' && linked != null && Number(size) > Number(linked);
});

const rosterNote = computed(() => {
    const size = household.value?.source_household_size;
    const linked = Number(household.value?.linked_resident_count ?? 0);
    const records = linked === 1 ? '1 named resident record is' : `${linked} named resident records are`;
    return `The source reports ${size} household members, and ${records} available. The roster is incomplete.`;
});

const familyCount = computed(() => {
    const value = household.value?.source_nuclear_families;
    return value == null || value === '' ? null : value;
});

const canLocate = computed(() => {
    const point = view.value === 'feature' && props.selection.featureType === 'building' ? building.value : household.value;
    return point?.longitude != null && point?.latitude != null;
});

const sourceLabel = computed(() => {
    const value = String(household.value?.record_source || building.value?.location_source || '');
    if (!value || value.includes('/') || value.includes('\\')) return null;
    return value;
});

function relationshipLabel(member) {
    const label = member?.relationship_label;
    if (!label || /person 01/i.test(label)) return null;
    return label;
}

function initials(name) {
    const clean = String(name || '').replace(/\(source value requires review\)/ig, '').trim();
    const parts = clean.split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return parts.slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function formatUpdated(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
}
</script>

<template>
    <aside class="cdrrmo-panel map-summary-panel" role="region" :aria-label="title">
        <header class="map-summary-header">
            <div class="map-summary-heading">
                <MapIcon :name="headerIcon" />
                <div>
                    <h3>{{ title }}</h3>
                    <p>{{ subtitle }}</p>
                </div>
            </div>
            <button
                v-if="showBack"
                type="button"
                class="mapr-button mapr-button-secondary map-summary-header-btn"
                @click="$emit('back-to-building')"
            >
                Back to building
            </button>
            <button
                v-else-if="selection.featureType"
                type="button"
                class="mapr-button mapr-button-secondary map-summary-header-btn"
                aria-label="Clear selection"
                @click="$emit('clear-feature')"
            >
                Clear selection
            </button>
        </header>

        <div v-if="showTabs" class="selection-tabs" role="tablist">
            <button type="button" role="tab" :aria-selected="selection.tab === 'summary'" :class="{ 'is-active': selection.tab === 'summary' }" @click="$emit('set-tab', 'summary')">
                Area summary
            </button>
            <button type="button" role="tab" :aria-selected="selection.tab === 'feature'" :class="{ 'is-active': selection.tab === 'feature' }" @click="$emit('set-tab', 'feature')">
                {{ featureTabLabel }}
            </button>
        </div>

        <div class="map-summary-body">
            <template v-if="view === 'empty'">
                <p>Click a household marker, draw an area, or choose a barangay. Details appear in this panel.</p>
                <ul class="selection-help">
                    <li>A teal marker opens household or building details.</li>
                    <li>Draw area summarizes the polygon from the registry.</li>
                    <li>A barangay uses that stored boundary.</li>
                </ul>
            </template>

            <template v-else-if="view === 'area'">
                <p v-if="summaryLoading">Calculating area totals…</p>
                <template v-else-if="summary">
                    <div class="selection-metrics">
                        <article>
                            <MapIcon name="building" />
                            <p>Mapped buildings</p>
                            <strong>{{ registry.buildings ?? 0 }}</strong>
                        </article>
                        <article>
                            <MapIcon name="home" />
                            <p>Households</p>
                            <strong>{{ registry.households ?? 0 }}</strong>
                        </article>
                        <article>
                            <MapIcon name="users" />
                            <p>Linked residents</p>
                            <strong>{{ registry.residents ?? 0 }}</strong>
                        </article>
                        <article v-if="registry.reported_household_size_total != null">
                            <MapIcon name="users" />
                            <p>Reported household size</p>
                            <strong>{{ registry.reported_household_size_total }}</strong>
                        </article>
                    </div>
                    <p class="cdrrmo-muted">Registry counts use source-recorded locations. They are not a field-verified impact estimate.</p>

                    <section class="selection-block">
                        <h4>Eligible official impact estimate</h4>
                        <dl class="selection-details">
                            <div><dt>Buildings</dt><dd>{{ official.buildings ?? 0 }}</dd></div>
                            <div><dt>Households</dt><dd>{{ official.households ?? 0 }}</dd></div>
                            <div><dt>Residents</dt><dd>{{ official.residents ?? 0 }}</dd></div>
                        </dl>
                        <p v-if="officialSmaller" class="cdrrmo-muted">Official totals are smaller because only verified locations are included.</p>
                    </section>

                    <section class="selection-block">
                        <h4>Field-verified damage</h4>
                        <dl v-if="summary.field_verified_damaged?.available || summary.field_verified_damaged?.buildings" class="selection-details">
                            <div><dt>Buildings</dt><dd>{{ summary.field_verified_damaged.buildings }}</dd></div>
                            <div><dt>Households</dt><dd>{{ summary.field_verified_damaged.households }}</dd></div>
                            <div><dt>Residents</dt><dd>{{ summary.field_verified_damaged.residents }}</dd></div>
                        </dl>
                        <p v-else class="cdrrmo-muted">No field-verified damage findings are linked in this area. Missing assessment data does not mean a building is unaffected.</p>
                    </section>

                    <section v-if="summary.vulnerable_groups?.categories?.length" class="selection-block">
                        <h4>Vulnerable groups</h4>
                        <dl class="selection-details">
                            <div v-for="cat in summary.vulnerable_groups.categories" :key="cat.name">
                                <dt>{{ cat.name }}</dt>
                                <dd>{{ cat.count }}</dd>
                            </div>
                        </dl>
                        <p class="cdrrmo-muted">{{ summary.vulnerable_groups.overlap_note }}</p>
                    </section>

                    <section v-if="summary.data_completeness" class="selection-block">
                        <h4>Missing data</h4>
                        <dl class="selection-details">
                            <div><dt>Buildings without households</dt><dd>{{ summary.data_completeness.buildings_without_household_links ?? 0 }}</dd></div>
                            <div><dt>Households without buildings</dt><dd>{{ summary.data_completeness.households_without_building_links ?? 0 }}</dd></div>
                        </dl>
                    </section>
                </template>
                <p v-else class="cdrrmo-muted">Area totals could not be calculated.</p>
            </template>

            <template v-else>
                <p v-if="selection.loading">Loading details…</p>
                <p v-else-if="selection.error" class="cdrrmo-error">{{ selection.error }}</p>
                <template v-else-if="selection.featureType === 'building' && building">
                    <p v-if="selection.outsideArea" class="selection-outside">This building is outside the selected area. Area totals are unchanged.</p>
                    <p class="cdrrmo-muted">Source-recorded point shared by the households at this location. It is not a separately verified building.</p>
                    <div class="selection-metrics">
                        <article>
                            <MapIcon name="home" />
                            <p>Linked households</p>
                            <strong>{{ building.household_count ?? selection.buildingHouseholds.length }}</strong>
                        </article>
                        <article>
                            <MapIcon name="users" />
                            <p>Linked residents</p>
                            <strong>{{ building.resident_count ?? 0 }}</strong>
                        </article>
                    </div>
                    <dl class="selection-details">
                        <div><dt>Building reference</dt><dd>{{ building.reference || '—' }}</dd></div>
                        <div><dt>Barangay</dt><dd>{{ building.barangay || '—' }}</dd></div>
                    </dl>
                    <h4>Households at this building</h4>
                    <div v-if="selection.buildingHouseholds.length" class="selection-rows">
                        <button v-for="row in selection.buildingHouseholds" :key="row.id" type="button" class="selection-row" @click="$emit('select-household', row.id)">
                            <MapIcon name="home" />
                            <span>{{ row.household_reference || 'Household record' }}</span>
                        </button>
                    </div>
                    <p v-else class="cdrrmo-muted">No active households are linked to this building.</p>
                    <details class="selection-source">
                        <summary>Source information</summary>
                        <dl class="selection-details">
                            <div v-if="sourceLabel"><dt>Location source</dt><dd>{{ sourceLabel }}</dd></div>
                            <div><dt>Map note</dt><dd>{{ building.map_unavailable_reason ? 'Coordinates are not available for locate.' : 'Source-recorded coordinates.' }}</dd></div>
                        </dl>
                    </details>
                </template>
                <template v-else-if="household">
                    <p v-if="selection.outsideArea" class="selection-outside">This household is outside the selected area. Area totals are unchanged.</p>
                    <div class="selection-metrics">
                        <article>
                            <MapIcon name="users" />
                            <p>Reported household size</p>
                            <strong>{{ household.source_household_size ?? '—' }}</strong>
                        </article>
                        <article>
                            <MapIcon name="users" />
                            <p>Available resident records</p>
                            <strong>{{ household.linked_resident_count ?? 0 }}</strong>
                        </article>
                        <article v-if="familyCount != null">
                            <MapIcon name="home" />
                            <p>Nuclear families</p>
                            <strong>{{ familyCount }}</strong>
                        </article>
                    </div>
                    <p v-if="rosterIncomplete" class="cdrrmo-muted">{{ rosterNote }}</p>
                    <dl class="selection-details">
                        <div><dt>Household reference</dt><dd>{{ household.household_reference || '—' }}</dd></div>
                        <div><dt>Barangay</dt><dd>{{ household.barangay || '—' }}</dd></div>
                        <div v-if="household.building_reference"><dt>Building reference</dt><dd>{{ household.building_reference }}</dd></div>
                    </dl>
                    <p class="cdrrmo-muted">{{ household.location_note }}</p>
                    <h4>Resident members</h4>
                    <ul v-if="household.members?.length" class="selection-members">
                        <li v-for="member in household.members" :key="member.id">
                            <span class="selection-avatar" aria-hidden="true">{{ initials(member.display_name) }}</span>
                            <span>
                                <strong>{{ member.display_name }}</strong>
                                <span v-if="relationshipLabel(member)" class="cdrrmo-muted">{{ relationshipLabel(member) }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="cdrrmo-muted">No individual member records are linked for this household.</p>
                    <details class="selection-source">
                        <summary>Source information</summary>
                        <dl class="selection-details">
                            <div v-if="sourceLabel"><dt>Record source</dt><dd>{{ sourceLabel }}</dd></div>
                            <div><dt>Updated</dt><dd>{{ formatUpdated(household.updated_at) }}</dd></div>
                        </dl>
                    </details>
                </template>
            </template>
        </div>

        <footer v-if="view === 'area' && summary" class="map-summary-footer">
            <button type="button" class="mapr-button mapr-button-secondary" @click="$emit('view-records')">View linked residents</button>
            <button type="button" class="mapr-button mapr-button-secondary" @click="$emit('view-households')">View households</button>
            <button
                v-if="showImpact"
                type="button"
                class="mapr-button mapr-button-primary"
                :disabled="!summary.can_snapshot"
                :title="summary.can_snapshot ? '' : 'An active incident is required before a report preview can be saved.'"
                @click="$emit('preview-report')"
            >
                Preview area report
            </button>
        </footer>
        <footer v-else-if="view === 'feature' && !selection.loading && (household || building)" class="map-summary-footer">
            <button v-if="selection.featureType === 'household' && household" type="button" class="mapr-button mapr-button-primary" @click="$emit('open-residents')">
                <MapIcon name="users" /> Open in Residents
            </button>
            <button type="button" class="mapr-button mapr-button-secondary" :disabled="!canLocate" @click="$emit('locate')">
                <MapIcon name="focus" /> Locate on map
            </button>
            <button type="button" class="mapr-button mapr-button-secondary" @click="$emit('clear-feature')">
                Clear selection
            </button>
        </footer>
    </aside>
</template>
