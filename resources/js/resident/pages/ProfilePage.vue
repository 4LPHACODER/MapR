<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import Button from 'primevue/button';
import { useConfirm } from 'primevue/useconfirm';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
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
const section = ref('all');
const message = ref('');
const loadError = ref('');
const fieldError = ref('');
const loading = ref(true);
const saving = ref(false);
const menuOpen = ref(false);
const menuTrigger = ref(null);
const menuEl = ref(null);
const menuPosition = ref({ top: 0, left: 0 });
const logoutForm = ref(null);
const photoControl = ref(null);
const sections = [
    { label: 'All information', value: 'all' },
    { label: 'Account information', value: 'account' },
    { label: 'Contact details', value: 'contact' },
];

const dirty = computed(() => contact.value !== savedContact.value);
const describedBy = computed(() => (fieldError.value ? 'resident-contact-help resident-contact-error' : 'resident-contact-help'));
const rows = computed(() => {
    if (!profile.value) return [];
    return [
        { label: 'Full name', icon: 'user', value: profile.value.name || 'Not provided' },
        { label: 'Email address', icon: '', value: profile.value.email || 'Not provided' },
        { label: 'Role', icon: 'user-cog', value: profile.value.role || 'Not provided' },
        { label: 'Barangay', icon: 'pin', value: profile.value.barangay_name || 'Not assigned' },
        { label: 'Registry reference', icon: 'clipboard', value: profile.value.registry_reference || 'Not provided' },
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
    try {
        profile.value = (await window.axios.patch(`${apiBase}/profile`, {
            contact_number: contact.value || null,
        })).data.data;
        applyResidentAccount(profile.value);
        savedContact.value = profile.value.contact_number || '';
        contact.value = savedContact.value;
        message.value = 'Contact details updated.';
    } catch (exception) {
        const errors = exception.response?.data?.errors?.contact_number;
        fieldError.value = errors?.[0] || exception.response?.data?.message || 'The contact number could not be saved.';
    } finally {
        saving.value = false;
    }
}

function placeMenu(fallbackHeight = 0, fallbackWidth = 0) {
    const button = menuTrigger.value;
    if (!button) return;
    const rect = button.getBoundingClientRect();
    const menuHeight = menuEl.value?.offsetHeight || fallbackHeight;
    const menuWidth = menuEl.value?.offsetWidth || fallbackWidth;
    const nav = document.querySelector('.resident-bottom-nav');
    const navVisible = nav && getComputedStyle(nav).display !== 'none';
    const limit = navVisible ? nav.getBoundingClientRect().top : window.innerHeight;
    let top = rect.bottom + 8;
    if (top + menuHeight > limit - 8) top = rect.top - menuHeight - 8;
    top = Math.max(72, top);
    let left = rect.right - menuWidth;
    left = Math.max(8, Math.min(left, window.innerWidth - menuWidth - 8));
    menuPosition.value = { top, left };
}

async function toggleMenu(event) {
    photoControl.value?.close();
    if (menuOpen.value) {
        closeMenu(true);
        return;
    }
    menuTrigger.value = event.currentTarget;
    placeMenu(64, 180);
    menuOpen.value = true;
    await nextTick();
    placeMenu();
    menuEl.value?.querySelector('[role="menuitem"]')?.focus();
}

function closeMenu(restoreFocus) {
    const trigger = menuTrigger.value;
    menuOpen.value = false;
    if (restoreFocus) trigger?.focus();
}

function onMenuKeydown(event) {
    const items = [...(menuEl.value?.querySelectorAll('[role="menuitem"]') || [])];
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu(true);
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        items[index < 0 ? 0 : index]?.focus();
    }
}

function onPointerDown(event) {
    if (!menuOpen.value) return;
    if (menuEl.value?.contains(event.target) || menuTrigger.value?.contains(event.target)) return;
    closeMenu(false);
}

function onViewport() {
    if (menuOpen.value) placeMenu();
}

function onLogout(event) {
    if (!dirty.value) return;
    event.preventDefault();
    closeMenu(false);
    requestDiscardConfirmation(() => logoutForm.value?.submit());
}

function requestDiscardConfirmation(onAccept, onReject = () => {}) {
    confirm.require({
        header: 'Discard contact changes?',
        message: 'Your contact number has not been saved.',
        acceptLabel: 'Discard changes',
        rejectLabel: 'Cancel',
        defaultFocus: 'reject',
        acceptProps: { severity: 'danger' },
        rejectProps: { severity: 'secondary', outlined: true },
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
    load();
    window.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('resize', onViewport);
    window.addEventListener('scroll', onViewport, true);
});

onBeforeUnmount(() => {
    window.removeEventListener('pointerdown', onPointerDown);
    window.removeEventListener('resize', onViewport);
    window.removeEventListener('scroll', onViewport, true);
});
</script>

<template>
    <section v-if="loading" class="resident-profile resident-stack" aria-hidden="true">
        <div class="resident-card resident-profile-skeleton"></div>
        <div class="resident-profile-grid">
            <div class="resident-card resident-profile-skeleton"></div>
            <div class="resident-card resident-profile-skeleton is-short"></div>
        </div>
    </section>
    <p v-if="loading" class="resident-profile-sr">Loading your profile…</p>
    <div v-else-if="loadError" class="resident-profile-error">
        <Message severity="error" :closable="false" class="resident-error">{{ loadError }}</Message>
        <Button type="button" severity="secondary" variant="outlined" class="resident-secondary" @click="load">Retry</Button>
    </div>
    <section v-else-if="profile" class="resident-profile resident-stack">
        <article class="resident-card resident-profile-summary">
            <div class="resident-profile-identity">
                <ProfilePhotoControl ref="photoControl" :name="profile.name" @menu-open="closeMenu(false)" />
                <div>
                    <h2>{{ profile.name }}</h2>
                    <p class="resident-profile-role">{{ profile.role || 'Not provided' }}</p>
                    <p class="resident-profile-place"><MapIcon name="pin" /> <span>{{ profile.barangay_name || 'Not assigned' }}</span></p>
                </div>
            </div>
            <Button
                type="button"
                class="resident-profile-actions"
                aria-haspopup="menu"
                aria-label="Account actions"
                :aria-expanded="menuOpen"
                @click="toggleMenu"
            >
                <template #icon>
                    <MapIcon name="more" />
                    <MapIcon name="chevron" class="is-chevron" />
                </template>
            </Button>
            <p class="resident-profile-intro">View your account information and update your contact details.</p>
            <Message
                v-if="residentAccount.photoNotice"
                :severity="residentAccount.photoNoticeError ? 'error' : 'success'"
                :closable="false"
                :class="residentAccount.photoNoticeError ? 'resident-error' : 'resident-ok'"
                :role="residentAccount.photoNoticeError ? 'alert' : 'status'"
            >{{ residentAccount.photoNotice }}</Message>
        </article>
        <p v-if="!profile.reporting_open" class="resident-reason">Your barangay is not assigned yet. Ask barangay staff to assign it before reporting.</p>
        <div class="resident-profile-filter">
            <label>
                <span><MapIcon name="layers" /> Show section</span>
                <Select v-model="section" input-id="resident-profile-section" :options="sections" option-label="label" option-value="value" />
            </label>
        </div>
        <div class="resident-profile-grid">
            <section v-show="section !== 'contact'" class="resident-card resident-profile-account">
                <h3><MapIcon name="user" /> Account Information</h3>
                <dl class="resident-profile-rows">
                    <div v-for="row in rows" :key="row.label">
                        <span class="resident-profile-row-icon" aria-hidden="true">
                            <MapIcon v-if="row.icon" :name="row.icon" />
                        </span>
                        <dt>{{ row.label }}</dt>
                        <dd>{{ row.value }}</dd>
                    </div>
                </dl>
            </section>
            <section v-show="section !== 'account'" class="resident-card resident-profile-contact">
                <h3><MapIcon name="user" /> Contact Details</h3>
                <p class="resident-profile-note">Keep your contact number up to date.</p>
                <form @submit.prevent="save">
                    <label for="resident-contact-number">Contact number</label>
                    <InputText
                        id="resident-contact-number"
                        v-model="contact"
                        type="tel"
                        maxlength="32"
                        autocomplete="tel"
                        :aria-invalid="fieldError ? 'true' : 'false'"
                        :aria-describedby="describedBy"
                    />
                    <p id="resident-contact-help" class="resident-profile-help">Up to 32 characters. Leading zeros and country codes are kept as entered.</p>
                    <Message v-if="fieldError" id="resident-contact-error" severity="error" :closable="false" class="resident-error">{{ fieldError }}</Message>
                    <div class="resident-profile-contact-actions">
                        <Button type="submit" class="resident-primary" :disabled="saving || !dirty">
                            <MapIcon name="save" /> {{ saving ? 'Saving…' : 'Save Changes' }}
                        </Button>
                        <Button v-if="dirty" type="button" text class="resident-profile-discard" @click="discard">Discard changes</Button>
                    </div>
                </form>
                <Message v-if="message" severity="success" :closable="false" class="resident-ok" role="status">{{ message }}</Message>
            </section>
        </div>
        <Teleport to="body">
            <div
                v-if="menuOpen"
                ref="menuEl"
                class="resident-profile-menu"
                role="menu"
                :style="{ top: `${menuPosition.top}px`, left: `${menuPosition.left}px` }"
                @keydown="onMenuKeydown"
            >
                <form ref="logoutForm" method="POST" :action="logoutUrl" @submit="onLogout">
                    <!-- native-ok: logout form submits the CSRF token -->
                    <input type="hidden" name="_token" :value="csrf">
                    <Button type="submit" text role="menuitem"><MapIcon name="logout" /> Sign out</Button>
                </form>
            </div>
        </Teleport>
    </section>
</template>
