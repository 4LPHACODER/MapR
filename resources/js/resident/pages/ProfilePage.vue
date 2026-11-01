<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ProfilePhotoControl from '../components/ProfilePhotoControl.vue';
import { applyResidentAccount, residentAccount } from '../accountState';

const apiBase = inject('apiBase');
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
const leavePrompt = ref(null);
const photoControl = ref(null);

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
    leavePrompt.value = { kind: 'logout' };
}

function confirmLeave() {
    const prompt = leavePrompt.value;
    leavePrompt.value = null;
    discard();
    if (prompt?.kind === 'route') prompt.resolve(true);
    if (prompt?.kind === 'logout') logoutForm.value?.submit();
}

function cancelLeave() {
    const prompt = leavePrompt.value;
    leavePrompt.value = null;
    prompt?.resolve?.(false);
}

onBeforeRouteLeave(() => {
    if (!dirty.value) return true;
    return new Promise((resolve) => {
        leavePrompt.value = { kind: 'route', resolve };
    });
});

onMounted(() => {
    load();
    window.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('resize', onViewport);
    window.addEventListener('scroll', onViewport, true);
});

onBeforeUnmount(() => {
    leavePrompt.value?.resolve?.(false);
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
    <div v-else-if="loadError" class="resident-profile-error" role="alert">
        <p class="resident-error">{{ loadError }}</p>
        <button type="button" class="resident-secondary" @click="load">Retry</button>
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
            <button
                type="button"
                class="resident-profile-actions"
                aria-haspopup="menu"
                aria-label="Account actions"
                :aria-expanded="menuOpen"
                @click="toggleMenu"
            >
                <MapIcon name="more" />
                <MapIcon name="chevron" class="is-chevron" />
            </button>
            <p class="resident-profile-intro">View your account information and update your contact details.</p>
            <p v-if="residentAccount.photoNotice" :class="residentAccount.photoNoticeError ? 'resident-error' : 'resident-ok'" :role="residentAccount.photoNoticeError ? 'alert' : 'status'">{{ residentAccount.photoNotice }}</p>
        </article>
        <p v-if="!profile.reporting_open" class="resident-reason">Your barangay is not assigned yet. Ask barangay staff to assign it before reporting.</p>
        <div class="resident-profile-filter">
            <label>
                <span><MapIcon name="layers" /> Show section</span>
                <select v-model="section">
                    <option value="all">All information</option>
                    <option value="account">Account information</option>
                    <option value="contact">Contact details</option>
                </select>
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
                    <input
                        id="resident-contact-number"
                        v-model="contact"
                        type="tel"
                        maxlength="32"
                        autocomplete="tel"
                        :aria-invalid="fieldError ? 'true' : 'false'"
                        :aria-describedby="describedBy"
                    >
                    <p id="resident-contact-help" class="resident-profile-help">Up to 32 characters. Leading zeros and country codes are kept as entered.</p>
                    <p v-if="fieldError" id="resident-contact-error" class="resident-error" role="alert">{{ fieldError }}</p>
                    <div class="resident-profile-contact-actions">
                        <button type="submit" class="resident-primary" :disabled="saving || !dirty">
                            <MapIcon name="save" /> {{ saving ? 'Saving…' : 'Save Changes' }}
                        </button>
                        <button v-if="dirty" type="button" class="resident-profile-discard" @click="discard">Discard changes</button>
                    </div>
                </form>
                <p v-if="message" class="resident-ok">{{ message }}</p>
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
                    <input type="hidden" name="_token" :value="csrf">
                    <button type="submit" role="menuitem"><MapIcon name="logout" /> Sign out</button>
                </form>
            </div>
        </Teleport>
        <ConfirmDialog
            v-if="leavePrompt"
            title="Discard contact changes?"
            message="Your contact number has not been saved."
            confirm-label="Discard changes"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </section>
</template>
