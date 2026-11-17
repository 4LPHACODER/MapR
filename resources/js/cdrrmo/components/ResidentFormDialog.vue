<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Textarea from 'primevue/textarea';
import { dateToIso, isoToDate } from '../../shared/ui/dates.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    resident: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved', 'hide']);

const formElement = ref(null);
const discardOpen = ref(false);
const chosenHousehold = ref(null);
const householdError = ref('');
let householdTicket = 0;
let householdComposing = false;
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
    chosenHousehold.value = householdOptions.value[0] || null;
    discardOpen.value = false;
    householdError.value = '';
    duplicateMatches.value = [];
    error.value = '';
    fieldErrors.value = {};
    initialSnapshot = snapshot();
}

watch(() => props.open, async (isOpen) => {
    clearTimeout(householdTimer.value);
    householdTicket++;
    if (isOpen) fillFromResident(props.resident);
});

function requestClose() {
    if (saving.value) return;
    if (snapshot() !== initialSnapshot) { discardOpen.value = true; return; }
    emit('close');
}

async function searchHouseholds() {
    if (!props.open || householdComposing) return;
    const ticket = ++householdTicket;
    householdError.value = '';
    const term = householdQuery.value.trim();
    if (term.length < 2) {
        householdOptions.value = form.household_id ? householdOptions.value.slice(0, 1) : [];
        return;
    }
    try {
        const { data } = await window.axios.get('/cdrrmo/api/households/search', { params: { search: term } });
        if (ticket === householdTicket) householdOptions.value = data.data || [];
    } catch {
        if (ticket === householdTicket) { householdOptions.value = []; householdError.value = 'Households could not be loaded. Try searching again.'; }
    }
}

watch(householdQuery, () => {
    clearTimeout(householdTimer.value);
    householdTicket++;
    if (props.open && !householdComposing) householdTimer.value = setTimeout(searchHouseholds, 300);
});

// A corrected field no longer carries the previous validation message.
const editableKeys = ['first_name', 'middle_name', 'last_name', 'household_id', 'sex', 'birth_date', 'record_source', 'reason'];
watch(() => editableKeys.map((key) => form[key]), (values, previous) => {
    for (let index = 0; index < editableKeys.length; index++) {
        if (values[index] !== previous?.[index]) delete fieldErrors.value[editableKeys[index]];
    }
});

function chooseHousehold(option) {
    chosenHousehold.value = option;
    form.household_id = String(option.id);
    householdQuery.value = option.household_reference;
}

async function focusFirstError() {
    await nextTick();
    formElement.value?.querySelector('[aria-invalid="true"]')?.focus();
}
onBeforeUnmount(() => { clearTimeout(householdTimer.value); householdTicket++; });
function clearHouseholdSearch() { householdQuery.value = ''; householdOptions.value = []; }

async function save(confirmDuplicate = false) {
    if (saving.value) return;
    const errors = {};
    if (!form.first_name.trim()) errors.first_name = ['Enter the first name.'];
    if (!form.last_name.trim()) errors.last_name = ['Enter the last name.'];
    if (!form.household_id) errors.household_id = ['Choose an active household.'];
    if (!editing.value && !form.record_source.trim()) errors.record_source = ['Enter the authorized record source.'];
    if (editing.value && form.reason.trim().length < 3) errors.reason = ['Enter a correction reason of at least 3 characters.'];
    fieldErrors.value = errors;
    if (Object.keys(errors).length) { await focusFirstError(); return; }
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
        await focusFirstError();
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :visible="open" :header="title" modal :draggable="false" :closable="!saving" :close-on-escape="!saving && !discardOpen" class="cdrrmo-residents-overlay registry-dialog registry-form-dialog" @update:visible="!$event && requestClose()" @after-hide="$emit('hide')">
        <form id="resident-edit-form" ref="formElement" class="registry-dialog-form" novalidate @submit.prevent="save(false)">
            <p class="registry-muted">Barangay and building come from the household. Sex and birth date are optional. Imported references stay unchanged.</p>
            <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
            <div v-if="duplicateMatches.length" class="registry-duplicate-note" role="status"><p>Matching references in this household:</p><ul><li v-for="match in duplicateMatches" :key="match.id">{{ match.registry_reference }} ({{ match.residency_status }})</li></ul><Button label="Save as a different person" size="small" severity="secondary" outlined :disabled="saving" @click="save(true)" /></div>
            <div class="registry-form-grid">
                <div class="registry-field"><label for="resident-first-name">First name <span aria-hidden="true">*</span></label><InputText id="resident-first-name" v-model="form.first_name" autofocus required maxlength="191" autocomplete="off" size="small" :disabled="saving" :invalid="!!fieldErrors.first_name" :aria-invalid="!!fieldErrors.first_name" :aria-describedby="fieldErrors.first_name ? 'resident-first-name-error' : undefined" /><small v-if="fieldErrors.first_name" id="resident-first-name-error" class="registry-field-error">{{ fieldErrors.first_name[0] }}</small></div>
                <div class="registry-field"><label for="resident-middle-name">Middle name</label><InputText id="resident-middle-name" v-model="form.middle_name" maxlength="191" autocomplete="off" size="small" :disabled="saving" :invalid="!!fieldErrors.middle_name" :aria-invalid="!!fieldErrors.middle_name" :aria-describedby="fieldErrors.middle_name ? 'resident-middle-name-error' : undefined" /><small v-if="fieldErrors.middle_name" id="resident-middle-name-error" class="registry-field-error">{{ fieldErrors.middle_name[0] }}</small></div>
                <div class="registry-field"><label for="resident-last-name">Last name <span aria-hidden="true">*</span></label><InputText id="resident-last-name" v-model="form.last_name" required maxlength="191" autocomplete="off" size="small" :disabled="saving" :invalid="!!fieldErrors.last_name" :aria-invalid="!!fieldErrors.last_name" :aria-describedby="fieldErrors.last_name ? 'resident-last-name-error' : undefined" /><small v-if="fieldErrors.last_name" id="resident-last-name-error" class="registry-field-error">{{ fieldErrors.last_name[0] }}</small></div>
                <div class="registry-field"><label for="resident-sex">Sex <span class="registry-optional">(optional)</span></label><InputText id="resident-sex" v-model="form.sex" maxlength="32" autocomplete="off" size="small" :disabled="saving" :invalid="!!fieldErrors.sex" :aria-describedby="fieldErrors.sex ? 'resident-sex-error' : undefined" /><small v-if="fieldErrors.sex" id="resident-sex-error" class="registry-field-error">{{ fieldErrors.sex[0] }}</small></div>
                <div class="registry-field registry-field-wide"><label for="resident-birth-date">Birth date <span class="registry-optional">(optional)</span></label><DatePicker input-id="resident-birth-date" :model-value="isoToDate(form.birth_date)" date-format="dd M yy" placeholder="Select or enter a date" show-icon icon-display="input" size="small" :disabled="saving" :invalid="!!fieldErrors.birth_date" panel-class="cdrrmo-residents-overlay registry-calendar" :pt="{ transition: { css: false }, pcInputText: { root: { 'aria-invalid': !!fieldErrors.birth_date, 'aria-describedby': fieldErrors.birth_date ? 'resident-birth-date-error' : undefined } } }" @update:model-value="form.birth_date = dateToIso($event)" /><small v-if="fieldErrors.birth_date" id="resident-birth-date-error" class="registry-field-error">{{ fieldErrors.birth_date[0] }}</small></div>
            </div>
            <div class="registry-field"><label for="resident-household-search">Household reference <span aria-hidden="true">*</span></label><div class="registry-search"><MapIcon name="search" /><InputText id="resident-household-search" v-model="householdQuery" placeholder="Search an active household reference" autocomplete="off" size="small" :disabled="saving" :invalid="!!fieldErrors.household_id" :aria-invalid="!!fieldErrors.household_id" :aria-describedby="fieldErrors.household_id ? 'resident-household-error' : 'resident-household-help'" @compositionstart="householdComposing = true; clearTimeout(householdTimer)" @compositionend="householdComposing = false; searchHouseholds()" /><Button v-if="householdQuery" aria-label="Clear household search" size="small" text severity="secondary" :disabled="saving" @click="clearHouseholdSearch"><MapIcon name="x" /></Button></div><small id="resident-household-help" class="registry-muted">Search at least two characters, then select a household below.</small><small v-if="fieldErrors.household_id" id="resident-household-error" class="registry-field-error">{{ fieldErrors.household_id[0] }}</small></div>
            <Message v-if="householdError" severity="error" :closable="false">{{ householdError }}</Message>
            <ul v-if="householdOptions.length" class="registry-household-options" aria-label="Matching households"><li v-for="option in householdOptions" :key="option.id"><Button type="button" size="small" severity="secondary" text :aria-pressed="String(option.id) === form.household_id" :disabled="saving" @click="chooseHousehold(option)"><span>{{ option.household_reference }}<small>{{ option.barangay || 'Barangay unavailable' }} · {{ option.building_reference || 'No building' }}</small></span><MapIcon v-if="String(option.id) === form.household_id" name="check" /></Button></li></ul>
            <p v-if="chosenHousehold" class="registry-household-selected"><MapIcon name="home" /><span>Selected household: <strong>{{ chosenHousehold.household_reference }}</strong></span></p>
            <div v-if="!editing" class="registry-field"><label for="resident-record-source">Record source <span aria-hidden="true">*</span></label><InputText id="resident-record-source" v-model="form.record_source" required maxlength="191" placeholder="Authorized source of this record" size="small" :disabled="saving" :invalid="!!fieldErrors.record_source" :aria-invalid="!!fieldErrors.record_source" :aria-describedby="fieldErrors.record_source ? 'resident-source-error' : undefined" /><small v-if="fieldErrors.record_source" id="resident-source-error" class="registry-field-error">{{ fieldErrors.record_source[0] }}</small></div>
            <div v-else class="registry-field"><label for="resident-correction-reason">Correction reason <span aria-hidden="true">*</span></label><Textarea id="resident-correction-reason" v-model="form.reason" required minlength="3" maxlength="500" auto-resize rows="3" :disabled="saving" :invalid="!!fieldErrors.reason" :aria-invalid="!!fieldErrors.reason" :aria-describedby="fieldErrors.reason ? 'resident-reason-error' : undefined" /><small v-if="fieldErrors.reason" id="resident-reason-error" class="registry-field-error">{{ fieldErrors.reason[0] }}</small></div>
        </form>
        <template #footer><Button label="Cancel" severity="secondary" outlined size="small" :disabled="saving" @click="requestClose" /><Button type="submit" form="resident-edit-form" label="Save Changes" size="small" :disabled="saving || !form.household_id" :loading="saving"><template #icon><MapIcon name="save" /></template></Button></template>
    </Dialog>
    <Dialog :visible="discardOpen" header="Discard unsaved resident changes?" modal :draggable="false" class="cdrrmo-residents-overlay registry-dialog" @update:visible="!$event && (discardOpen = false)"><p>Your changes have not been saved. Keep editing or discard them.</p><template #footer><Button label="Keep editing" severity="secondary" outlined size="small" autofocus @click="discardOpen = false" /><Button label="Discard changes" severity="danger" size="small" @click="discardOpen = false; $emit('close')" /></template></Dialog>
</template>
