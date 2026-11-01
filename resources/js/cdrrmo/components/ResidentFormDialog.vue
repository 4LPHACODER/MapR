<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    resident: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const firstField = ref(null);
const saving = ref(false);
const error = ref('');
const fieldErrors = ref({});
const duplicateMatches = ref([]);
const householdQuery = ref('');
const householdOptions = ref([]);
const householdTimer = ref(null);
const form = reactive({
    first_name: '',
    middle_name: '',
    last_name: '',
    household_id: '',
    sex: '',
    birth_date: '',
    record_source: '',
    reason: '',
});

const editing = computed(() => !!props.resident?.id);
const title = computed(() => (editing.value ? 'Edit Resident' : 'Add resident'));
let initialSnapshot = '';

function snapshot() {
    return JSON.stringify({ ...form, householdQuery: householdQuery.value });
}

function fillFromResident(resident) {
    form.first_name = resident?.first_name || '';
    form.middle_name = resident?.middle_name || '';
    form.last_name = resident?.last_name || '';
    form.household_id = resident?.household_id ? String(resident.household_id) : '';
    form.sex = resident?.sex || '';
    form.birth_date = resident?.birth_date || '';
    form.record_source = '';
    form.reason = '';
    householdQuery.value = resident?.household_reference || '';
    householdOptions.value = resident?.household_id
        ? [{ id: resident.household_id, household_reference: resident.household_reference, barangay: resident.barangay, building_reference: resident.building_reference }]
        : [];
    duplicateMatches.value = [];
    error.value = '';
    fieldErrors.value = {};
    initialSnapshot = snapshot();
}

watch(() => props.open, async (isOpen) => {
    if (!isOpen) return;
    fillFromResident(props.resident);
    await nextTick();
    firstField.value?.focus();
});

function requestClose() {
    if (snapshot() !== initialSnapshot && !window.confirm('Discard unsaved resident changes?')) return;
    emit('close');
}

function onKeydown(event) {
    if (event.key === 'Escape') requestClose();
}

async function searchHouseholds() {
    const term = householdQuery.value.trim();
    if (term.length < 2) {
        householdOptions.value = form.household_id ? householdOptions.value.slice(0, 1) : [];
        return;
    }
    try {
        const { data } = await window.axios.get('/cdrrmo/api/households/search', { params: { search: term } });
        householdOptions.value = data.data || [];
    } catch {
        householdOptions.value = [];
    }
}

watch(householdQuery, () => {
    clearTimeout(householdTimer.value);
    householdTimer.value = setTimeout(searchHouseholds, 300);
});

function chooseHousehold(option) {
    form.household_id = String(option.id);
    householdQuery.value = option.household_reference;
}

async function save(confirmDuplicate = false) {
    if (saving.value) return;
    saving.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        if (editing.value) {
            await window.axios.patch(`/cdrrmo/api/residents/${props.resident.id}`, {
                updated_at: props.resident.updated_at,
                reason: form.reason,
                first_name: form.first_name,
                middle_name: form.middle_name || null,
                last_name: form.last_name,
                household_id: Number(form.household_id),
                sex: form.sex || null,
                birth_date: form.birth_date || null,
            });
        } else {
            await window.axios.post('/cdrrmo/api/residents', {
                first_name: form.first_name,
                middle_name: form.middle_name || null,
                last_name: form.last_name,
                household_id: Number(form.household_id),
                sex: form.sex || null,
                birth_date: form.birth_date || null,
                record_source: form.record_source,
                confirm_duplicate: confirmDuplicate,
            });
        }
        duplicateMatches.value = [];
        emit('saved');
    } catch (exception) {
        const response = exception.response;
        if (response?.status === 422 && response.data?.matches) {
            duplicateMatches.value = response.data.matches;
            error.value = response.data.message;
        } else if (response?.status === 409) {
            error.value = response.data?.message || 'This resident was updated by someone else. Refresh and try again.';
        } else if (response?.status === 422) {
            fieldErrors.value = response.data?.errors || {};
            error.value = response.data?.message || 'Check the highlighted fields and try again.';
        } else {
            error.value = 'The resident could not be saved.';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div v-if="open" class="residents-dialog-backdrop" @keydown="onKeydown">
        <form class="residents-dialog" role="dialog" aria-modal="true" :aria-labelledby="'resident-form-title'" @submit.prevent="save(false)">
            <h3 id="resident-form-title">{{ title }}</h3>
            <p class="cdrrmo-muted">Barangay and building come from the household. Sex and birth date are optional. Imported references stay unchanged.</p>
            <p v-if="error" class="cdrrmo-error" role="alert">{{ error }}</p>
            <div v-if="duplicateMatches.length" class="residents-warning" role="status">
                <p>Matching references in this household:</p>
                <ul>
                    <li v-for="match in duplicateMatches" :key="match.id">{{ match.registry_reference }} ({{ match.residency_status }})</li>
                </ul>
                <button type="button" class="resident-action resident-action-secondary" :disabled="saving" @click="save(true)">Save as a different person</button>
            </div>
            <div class="resident-form-grid">
                <label>
                    First name
                    <input ref="firstField" v-model="form.first_name" required maxlength="191" autocomplete="off">
                    <span v-if="fieldErrors.first_name" class="cdrrmo-error">{{ fieldErrors.first_name[0] }}</span>
                </label>
                <label>
                    Middle name
                    <input v-model="form.middle_name" maxlength="191" autocomplete="off">
                </label>
                <label>
                    Last name
                    <input v-model="form.last_name" required maxlength="191" autocomplete="off">
                    <span v-if="fieldErrors.last_name" class="cdrrmo-error">{{ fieldErrors.last_name[0] }}</span>
                </label>
                <label>
                    Sex
                    <input v-model="form.sex" maxlength="32" autocomplete="off">
                </label>
                <label class="is-wide">
                    Birth date
                    <input v-model="form.birth_date" type="date">
                </label>
            </div>
            <label>
                Household reference
                <input v-model="householdQuery" type="search" placeholder="Search an active household reference" autocomplete="off">
                <span v-if="fieldErrors.household_id" class="cdrrmo-error">{{ fieldErrors.household_id[0] }}</span>
            </label>
            <ul v-if="householdOptions.length" class="residents-search-list">
                <li v-for="option in householdOptions" :key="option.id">
                    <button type="button" :aria-pressed="String(option.id) === form.household_id" @click="chooseHousehold(option)">
                        {{ option.household_reference }}
                        <span>{{ option.barangay || 'Barangay unavailable' }} · {{ option.building_reference || 'No building' }}</span>
                    </button>
                </li>
            </ul>
            <label v-if="!editing">
                Record source
                <input v-model="form.record_source" required maxlength="191" placeholder="Authorized source of this record">
                <span v-if="fieldErrors.record_source" class="cdrrmo-error">{{ fieldErrors.record_source[0] }}</span>
            </label>
            <label v-else>
                Correction reason
                <textarea v-model="form.reason" required minlength="3" maxlength="500" rows="3"></textarea>
                <span v-if="fieldErrors.reason" class="cdrrmo-error">{{ fieldErrors.reason[0] }}</span>
            </label>
            <div class="cdrrmo-actions resident-form-footer">
                <button type="button" class="resident-action resident-action-secondary" :disabled="saving" @click="requestClose">Cancel</button>
                <button type="submit" class="resident-action resident-action-primary" :disabled="saving || !form.household_id">
                    <MapIcon name="save" /> {{ saving ? 'Saving…' : 'Save Changes' }}
                </button>
            </div>
        </form>
    </div>
</template>
