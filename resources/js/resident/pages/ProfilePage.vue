<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import Button from 'primevue/button';
import { useConfirm } from 'primevue/useconfirm';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import MapIcon from '../../shared/components/MapIcon.vue';
import ProfilePhotoControl from '../components/ProfilePhotoControl.vue';
import { applyResidentAccount, residentAccount } from '../accountState';

const apiBase = inject('apiBase');
const confirm = useConfirm();
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const logoutUrl = document.getElementById('resident-app')?.dataset.logoutUrl || '/logout';
const profile = ref(null);
const contact = ref('');
const savedContact = ref('');
const message = ref('');
const loadError = ref('');
const fieldError = ref('');
const loading = ref(true);
const saving = ref(false);
const logoutForm = ref(null);
const contactInput = ref(null);

const dirty = computed(() => contact.value !== savedContact.value);
const describedBy = computed(() => (fieldError.value ? 'resident-contact-help resident-contact-error' : 'resident-contact-help'));
const rows = computed(() => {
    if (!profile.value) return [];
    return [
        { label: 'Full name', value: profile.value.name || 'Not provided' },
        { label: 'Email address', value: profile.value.email || 'Not provided' },
        { label: 'Role', value: profile.value.role || 'Not provided' },
        { label: 'Barangay', value: profile.value.barangay_name || 'Not assigned' },
        { label: 'Registry reference', value: profile.value.registry_reference || 'Not provided' },
    ];
});

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        profile.value = (await window.axios.get(`${apiBase}/profile`)).data.data;
        contact.value = profile.value.contact_number || '';
        savedContact.value = contact.value;
        applyResidentAccount(profile.value);
    } catch (exception) {
        profile.value = null;
        loadError.value = exception.response?.data?.message || 'Your profile could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function discard() {
    contact.value = savedContact.value;
    message.value = '';
    fieldError.value = '';
}

async function save() {
    if (saving.value || !dirty.value) return;
    fieldError.value = '';
    message.value = '';
    saving.value = true;
    const submittedContact = contact.value;
    try {
        profile.value = (await window.axios.patch(`${apiBase}/profile`, {
            contact_number: submittedContact || null,
        })).data.data;
        applyResidentAccount(profile.value);
        savedContact.value = profile.value.contact_number || '';
        if (contact.value === submittedContact) contact.value = savedContact.value;
        message.value = 'Contact details updated.';
    } catch (exception) {
        const errors = exception.response?.data?.errors?.contact_number;
        fieldError.value = errors?.[0] || exception.response?.data?.message || 'The contact number could not be saved.';
    } finally {
        saving.value = false;
        if (fieldError.value) {
            await nextTick();
            contactInput.value?.$el?.focus();
        }
    }
}

function beforeUnload(event) {
    if (!dirty.value) return;
    event.preventDefault();
    event.returnValue = '';
}

function onLogout(event) {
    if (!dirty.value) return;
    event.preventDefault();
    requestDiscardConfirmation(() => logoutForm.value?.submit());
}

function requestDiscardConfirmation(onAccept, onReject = () => {}) {
    confirm.require({
        header: 'Discard contact changes?',
        message: 'Your contact number has not been saved.',
        acceptLabel: 'Discard changes',
        rejectLabel: 'Cancel',
        defaultFocus: 'reject',
        acceptProps: { severity: 'danger', size: 'small', class: 'resident-profile-confirm-button' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small', class: 'resident-profile-confirm-button' },
        accept: () => {
            discard();
            onAccept();
        },
        reject: onReject,
        onHide: onReject,
    });
}

onBeforeRouteLeave(() => {
    if (!dirty.value) return true;
    return new Promise((resolve) => {
        requestDiscardConfirmation(() => resolve(true), () => resolve(false));
    });
});

onMounted(() => {
    document.title = 'Profile | MapR';
    load();
    window.addEventListener('beforeunload', beforeUnload);
});
onUnmounted(() => window.removeEventListener('beforeunload', beforeUnload));
</script>

<template>
    <section class="resident-profile" aria-label="Your profile" :aria-busy="loading">
        <div v-if="loading" class="resident-profile-loading" role="status">Loading your profile…</div>
        <div v-else-if="loadError" class="resident-profile-error">
            <Message severity="error" :closable="false">{{ loadError }}</Message>
            <Button type="button" size="small" severity="secondary" outlined @click="load">Retry</Button>
        </div>
        <template v-else-if="profile">
            <article class="resident-card resident-profile-summary">
                <div class="resident-profile-identity">
                    <ProfilePhotoControl :name="profile.name" />
                    <div class="resident-profile-identity-copy">
                        <h2>{{ profile.name }}</h2>
                        <div class="resident-profile-meta">
                            <span class="resident-profile-role">{{ profile.role || 'Resident' }}</span>
                            <span class="resident-profile-place">{{ profile.barangay_name || 'Barangay not assigned' }}</span>
                        </div>
                    </div>
                </div>
            </article>
            <Message
                v-if="residentAccount.photoNotice"
                :severity="residentAccount.photoNoticeError ? 'error' : 'success'"
                :closable="false"
                :role="residentAccount.photoNoticeError ? 'alert' : 'status'"
            >{{ residentAccount.photoNotice }}</Message>
            <Message v-if="!profile.reporting_open" severity="warn" :closable="false">
                Your barangay is not assigned yet. Ask barangay staff to assign it before reporting.
            </Message>
            <div class="resident-profile-grid">
                <section class="resident-card resident-profile-account" aria-labelledby="resident-account-heading">
                    <header class="resident-profile-panel-heading">
                        <h3 id="resident-account-heading">Account information</h3>
                        <span class="resident-profile-readonly">Read only</span>
                    </header>
                    <dl class="resident-profile-rows">
                        <div v-for="row in rows" :key="row.label">
                            <dt>{{ row.label }}</dt>
                            <dd>{{ row.value }}</dd>
                        </div>
                    </dl>
                    <p class="resident-profile-account-help">Need to correct these details? Contact your barangay staff.</p>
                </section>
                <section class="resident-card resident-profile-contact" aria-labelledby="resident-contact-heading">
                    <header class="resident-profile-panel-heading">
                        <h3 id="resident-contact-heading">Contact details</h3>
                    </header>
                    <form novalidate @submit.prevent="save">
                        <label for="resident-contact-number">Contact number <span>(optional)</span></label>
                        <InputText
                            id="resident-contact-number"
                            ref="contactInput"
                            v-model="contact"
                            size="small"
                            type="tel"
                            maxlength="32"
                            autocomplete="tel"
                            placeholder="Enter your contact number"
                            :invalid="Boolean(fieldError)"
                            :aria-invalid="fieldError ? 'true' : 'false'"
                            :aria-describedby="describedBy"
                            :disabled="saving"
                            @update:model-value="message = ''; fieldError = ''"
                        />
                        <p id="resident-contact-help" class="resident-profile-help">Up to 32 characters. Include your country code if needed.</p>
                        <div class="resident-profile-feedback" aria-live="polite">
                            <Message v-if="fieldError" id="resident-contact-error" severity="error" :closable="false">{{ fieldError }}</Message>
                            <Message v-else-if="message" severity="success" :closable="false">{{ message }}</Message>
                            <span v-else-if="dirty">You have unsaved changes.</span>
                        </div>
                        <div class="resident-profile-contact-actions">
                            <Button type="submit" size="small" :disabled="saving || !dirty" :aria-busy="saving" class="resident-profile-save">
                                {{ saving ? 'Saving…' : 'Save changes' }}
                            </Button>
                            <Button type="button" size="small" severity="secondary" text :disabled="saving || !dirty" @click="discard">Discard</Button>
                        </div>
                    </form>
                </section>
            </div>
            <footer class="resident-profile-footer">
                <form ref="logoutForm" novalidate method="POST" :action="logoutUrl" @submit="onLogout">
                    <!-- native-ok: logout form submits the CSRF token -->
                    <input type="hidden" name="_token" :value="csrf">
                    <Button type="submit" size="small" severity="secondary" text :disabled="saving"><MapIcon name="logout" />Sign out</Button>
                </form>
            </footer>
        </template>
    </section>
</template>
