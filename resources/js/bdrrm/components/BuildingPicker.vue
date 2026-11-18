<script setup>
import { inject, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import mapboxgl from 'mapbox-gl';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import ProgressSpinner from 'primevue/progressspinner';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    barangayId: { type: [String, Number], default: '' },
    invalid: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'selected']);

const apiBase = inject('apiBase');
const mapboxToken = inject('mapboxToken');
const mapboxStyle = inject('mapboxStyle');
const query = ref('');
const matches = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const searching = ref(false);
const composing = ref(false);
const searchError = ref('');
const residentNotice = ref(false);
const note = ref('');
const selected = ref(null);
const detail = ref(null);
const photos = ref([]);
const detailError = ref('');
const loadingDetail = ref(false);
const expanded = ref({});
const broken = ref({});
const viewer = ref(null);
const searchInput = ref(null);
const miniMapEl = ref(null);
let searchTimer;
let searchTicket = 0;
let detailTicket = 0;
let miniMap = null;

function locationLabel(status) {
    if (status === 'verified') return 'Verified';
    if (status === 'rejected') return 'Rejected';
    return 'Unverified';
}

function formatWhen(value) {
    if (!value) return '';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return String(value);
    return `${new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date)} PHT`;
}

function photoCaption(photo) {
    const parts = ['Report photograph'];
    if (photo?.report_reference) parts.push(photo.report_reference);
    if (photo?.incident_name) parts.push(photo.incident_name);
    const when = formatWhen(photo?.recorded_at);
    if (when) parts.push(when);
    return parts.join(' · ');
}

function photoUrl(buildingId, photoId) {
    return `${apiBase}/buildings/${buildingId}/photos/${photoId}`;
}

function locationCopy(record) {
    if (record?.map_available && record.longitude != null && record.latitude != null) {
        return 'Source-recorded point. A recorded point is not a verified location.';
    }
    if (record?.map_unavailable_reason === 'invalid_geometry') {
        return 'The stored coordinates are not a valid study-area point.';
    }
    return 'Location coordinates are unavailable.';
}

function destroyMiniMap() {
    miniMap?.remove();
    miniMap = null;
}

function mountMiniMap() {
    destroyMiniMap();
    if (!miniMapEl.value || detail.value?.longitude == null || detail.value?.latitude == null || !mapboxToken) return;
    mapboxgl.accessToken = mapboxToken;
    const center = [Number(detail.value.longitude), Number(detail.value.latitude)];
    miniMap = new mapboxgl.Map({
        container: miniMapEl.value,
        style: mapboxStyle || 'mapbox://styles/mapbox/streets-v12',
        center,
        zoom: 16,
        interactive: false,
        attributionControl: true,
    });
    miniMap.on('load', () => {
        if (!miniMap) return;
        new mapboxgl.Marker({ color: '#1e3a8a' }).setLngLat(center).addTo(miniMap);
    });
}

async function search(resetPage = true) {
    const term = query.value.trim().replace(/\s+/g, ' ');
    if (!props.barangayId || term.length < 2) {
        matches.value = [];
        residentNotice.value = false;
        searchError.value = '';
        searching.value = false;
        return;
    }
    const page = resetPage ? 1 : meta.value.current_page + 1;
    const ticket = ++searchTicket;
    searching.value = true;
    try {
        const { data } = await window.axios.get(`${apiBase}/buildings`, {
            params: {
                q: term,
                barangay_id: props.barangayId,
                per_page: 10,
                page,
                lookup: '1',
                match: 'residents',
            },
        });
        if (ticket !== searchTicket) return;
        matches.value = page === 1 ? (data.data || []) : matches.value.concat(data.data || []);
        meta.value = data.meta || meta.value;
        residentNotice.value = Boolean(data.resident_without_building);
        searchError.value = '';
    } catch (exception) {
        if (ticket !== searchTicket) return;
        searchError.value = exception.response?.data?.message || 'Buildings could not be searched.';
    } finally {
        if (ticket === searchTicket) searching.value = false;
    }
}

function scheduleSearch() {
    clearTimeout(searchTimer);
    searchTicket++;
    matches.value = [];
    residentNotice.value = false;
    searchError.value = '';
    searching.value = !composing.value && Boolean(props.barangayId) && query.value.trim().length >= 2;
    if (searching.value) searchTimer = setTimeout(() => search(true), 300);
}

function submitSearch(event) {
    if (event.isComposing || composing.value) return;
    event.preventDefault();
    clearTimeout(searchTimer);
    search(true);
}

function clearSearch() {
    query.value = '';
    changeBuilding();
}

async function loadDetail(id) {
    const ticket = ++detailTicket;
    detailError.value = '';
    loadingDetail.value = true;
    try {
        const [building, photoList] = await Promise.all([
            window.axios.get(`${apiBase}/buildings/${id}`),
            window.axios.get(`${apiBase}/buildings/${id}/photos`),
        ]);
        if (ticket !== detailTicket) return;
        detail.value = building.data;
        photos.value = photoList.data.data || [];
        await nextTick();
        mountMiniMap();
    } catch (exception) {
        if (ticket !== detailTicket) return;
        detail.value = null;
        photos.value = [];
        destroyMiniMap();
        detailError.value = exception.response?.status === 403
            ? ''
            : 'This building record could not be loaded.';
    } finally {
        if (ticket === detailTicket) loadingDetail.value = false;
    }
}

function selectBuilding(row) {
    note.value = '';
    detail.value = null;
    photos.value = [];
    destroyMiniMap();
    selected.value = { ...row };
    emit('update:modelValue', row.id);
    emit('selected', selected.value);
    loadDetail(row.id);
}

function clearSelection() {
    detailTicket++;
    loadingDetail.value = false;
    selected.value = null;
    detail.value = null;
    photos.value = [];
    detailError.value = '';
    destroyMiniMap();
    emit('update:modelValue', '');
    emit('selected', null);
}

function changeBuilding() {
    searchInput.value?.$el?.focus();
}

function openViewer(buildingId, photo) {
    viewer.value = {
        url: photoUrl(buildingId, photo.id),
        alt: photoCaption(photo),
        caption: `${photoCaption(photo)}. This photograph is from a linked report. It does not show the current condition of the building.`,
        broken: false,
    };
}

watch(query, scheduleSearch);

watch(() => props.barangayId, (next, previous) => {
    if (String(next) === String(previous)) return;
    if (selected.value && previous !== undefined && previous !== '') {
        clearSelection();
        note.value = 'The building was cleared because it is not in the selected barangay.';
    }
    scheduleSearch();
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    searchTicket++;
    detailTicket++;
    destroyMiniMap();
});
</script>

<template>
    <div class="report-building-picker">
        <div class="grid gap-1.5">
            <label for="report-building_id" class="font-semibold">Building <span class="report-create-optional">Optional for draft</span></label>
            <div class="report-create-search relative">
                <MapIcon name="search" class="pointer-events-none absolute inset-s-3 top-1/2 size-3.5 -translate-y-1/2" />
                <InputText
                    id="report-building_id"
                    ref="searchInput"
                    v-model="query"
                    size="small"
                    fluid
                    maxlength="80"
                    autocomplete="off"
                    :disabled="!barangayId"
                    :invalid="invalid"
                    :aria-describedby="error ? 'report-building-help report-building-error' : 'report-building-help'"
                    :aria-busy="searching"
                    placeholder="Search for a building"
                    @keydown.enter="submitSearch"
                    @compositionstart="composing = true; scheduleSearch()"
                    @compositionend="composing = false; scheduleSearch()"
                />
                <Button
                    v-if="query"
                    type="button"
                    aria-label="Clear building search"
                    size="small"
                    severity="secondary"
                    text
                    class="report-create-search-clear"
                    @click="clearSearch"
                >
                    <template #icon><MapIcon name="x" class="size-3.5" /></template>
                </Button>
            </div>
            <small id="report-building-help" class="report-note">
                {{ barangayId ? 'Search by building reference, address, resident name, or resident ID.' : 'A barangay is needed to search for buildings.' }}
            </small>
            <small v-if="error" id="report-building-error" class="report-field-error">{{ error }}</small>
        </div>
        <p v-if="note" class="report-note" role="status">{{ note }}</p>
        <p class="visually-hidden" aria-live="polite">
            <template v-if="!searching && !searchError && query.trim().length >= 2 && matches.length">{{ meta.total }} matching {{ meta.total === 1 ? 'building' : 'buildings' }}.</template>
        </p>

        <article v-if="selected" class="report-selected-building">
            <div class="report-building-card">
                <div class="report-building-photo">
                    <Button v-if="photos[0] && !broken[photos[0].id]" type="button" size="small" severity="secondary" outlined class="report-photo-button" @click="openViewer(selected.id, photos[0])">
                        <img :src="photoUrl(selected.id, photos[0].id)" :alt="photoCaption(photos[0])" @error="broken[photos[0].id] = true">
                    </Button>
                    <p v-else class="report-photo-placeholder"><MapIcon name="building" /> No building photo available.</p>
                </div>
                <div>
                    <h3>{{ selected.reference }}</h3>
                    <p v-if="selected.matched_residents?.length">Linked resident: {{ selected.matched_residents[0].name }} · Resident ID {{ selected.matched_residents[0].resident_id }}</p>
                    <p class="report-meta"><span><MapIcon name="pin" /> {{ detail?.address || selected.address || 'Address not recorded' }} · {{ detail?.barangay || selected.barangay_name || 'Barangay' }}</span></p>
                    <p>Location source: {{ detail?.location_source || selected.location_source || 'Not recorded' }}</p>
                    <p><span class="report-badge" :data-status="detail?.location_status || selected.location_status">{{ locationLabel(detail?.location_status || selected.location_status) }}</span></p>
                    <p v-if="detail" class="report-note">{{ locationCopy(detail) }}</p>
                    <p v-else-if="loadingDetail" class="report-note" role="status">Loading location…</p>
                    <Message v-if="detailError" severity="error" size="small">
                        {{ detailError }}
                        <Button type="button" label="Retry" size="small" severity="secondary" text @click="loadDetail(selected.id)" />
                    </Message>
                </div>
                <div class="report-actions-row">
                    <Button type="button" label="Change Building" size="small" severity="secondary" outlined @click="changeBuilding" />
                    <Button type="button" label="Clear Selection" size="small" severity="secondary" text @click="clearSelection(); changeBuilding()" />
                </div>
            </div>
            <div v-if="photos.length > 1" class="report-photo-row">
                <Button v-for="photo in photos.slice(1)" :key="photo.id" type="button" size="small" severity="secondary" outlined class="report-photo-button" @click="openViewer(selected.id, photo)">
                    <img v-if="!broken[photo.id]" :src="photoUrl(selected.id, photo.id)" :alt="photoCaption(photo)" @error="broken[photo.id] = true">
                    <span v-else>No building photo available.</span>
                </Button>
            </div>
            <div v-if="detail?.longitude != null && detail?.latitude != null" ref="miniMapEl" class="report-mini-map" role="img" aria-label="Recorded building location"></div>
            <Button as="a" label="View on Barangay Map" size="small" severity="secondary" outlined class="justify-self-start" :href="`/bdrrm/map?building_id=${selected.id}`" target="_blank" rel="noopener" />
        </article>

        <p v-if="searching" class="report-note flex items-center gap-2" role="status">
            <ProgressSpinner class="size-3.5 shrink-0" stroke-width="5" aria-hidden="true" />
            Searching buildings…
        </p>
        <Message v-else-if="searchError" severity="error" size="small">
            {{ searchError }}
            <Button type="button" label="Retry" size="small" severity="secondary" text @click="search(true)" />
        </Message>
        <p v-else-if="residentNotice" class="report-note" role="status">Resident found, but no linked building is available.</p>
        <p v-else-if="!composing && barangayId && query.trim().length >= 2 && !matches.length" class="report-note" role="status">No buildings match this search.</p>
        <div v-if="matches.length" class="report-building-results">
            <article v-for="row in matches" :key="row.id" class="report-building-card" :class="{ 'is-selected': String(selected?.id) === String(row.id) }">
                <div class="report-building-photo">
                    <Button v-if="row.photo && !broken[`list-${row.photo.id}`]" type="button" size="small" severity="secondary" outlined class="report-photo-button" @click="openViewer(row.id, row.photo)">
                        <img :src="photoUrl(row.id, row.photo.id)" :alt="photoCaption(row.photo)" @error="broken[`list-${row.photo.id}`] = true">
                    </Button>
                    <p v-else class="report-photo-placeholder"><MapIcon name="building" /> No building photo available.</p>
                </div>
                <div>
                    <h3>{{ row.reference }}</h3>
                    <p v-if="row.matched_residents?.length">Linked resident: {{ row.matched_residents[0].name }} · Resident ID {{ row.matched_residents[0].resident_id }}</p>
                    <Button v-if="row.matched_resident_count > 1" type="button" size="small" severity="secondary" text :label="`+${row.matched_resident_count - 1} linked residents`" :aria-expanded="Boolean(expanded[row.id])" @click="expanded[row.id] = !expanded[row.id]" />
                    <ul v-if="expanded[row.id]" class="report-matches">
                        <li v-for="person in row.matched_residents" :key="person.resident_id">{{ person.name }} · Resident ID {{ person.resident_id }}</li>
                    </ul>
                    <p class="report-meta"><span><MapIcon name="pin" /> {{ row.address || 'Address not recorded' }} · {{ row.barangay_name || 'Barangay' }}</span></p>
                    <p><span class="report-badge" :data-status="row.location_status">{{ locationLabel(row.location_status) }}</span></p>
                </div>
                <Button type="button" label="Select Building" size="small" severity="secondary" outlined class="justify-self-start" @click="selectBuilding(row)" />
            </article>
            <Button v-if="meta.current_page < meta.last_page" type="button" label="Load More" size="small" severity="secondary" outlined class="justify-self-start" :loading="searching" @click="search(false)" />
        </div>

        <Dialog :visible="Boolean(viewer)" header="Building photograph" modal :draggable="false" :close-button-props="{ size: 'small' }" class="w-[min(36rem,calc(100vw-2rem))]" @update:visible="viewer = null">
            <div v-if="viewer" class="grid gap-3">
                <img v-if="!viewer.broken" :src="viewer.url" :alt="viewer.alt" class="max-h-[65dvh] w-full object-contain" @error="viewer.broken = true">
                <p v-else>No building photo available.</p>
                <p class="text-sm">{{ viewer.caption }}</p>
            </div>
        </Dialog>
    </div>
</template>
