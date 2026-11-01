<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';
import ResidentAvatar from './ResidentAvatar.vue';
import { applyResidentAccount, residentAccount } from '../accountState';

const props = defineProps({
    name: { type: String, default: '' },
});

const emit = defineEmits(['menu-open']);
const apiBase = inject('apiBase');
const menuOpen = ref(false);
const menuTrigger = ref(null);
const menuEl = ref(null);
const menuPosition = ref({ top: 0, left: 0 });
const fileInput = ref(null);
const dialog = ref(null);
const cancelButton = ref(null);
const previewUrl = ref('');
const pendingFile = ref(null);
const photoError = ref('');
const saving = ref(false);
const progress = ref(0);
const removing = ref(false);

const hasPhoto = computed(() => Boolean(residentAccount.photoUrl));

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
    top = Math.max(72, Math.min(top, window.innerHeight - menuHeight - 8));
    let left = rect.left;
    left = Math.max(8, Math.min(left, window.innerWidth - menuWidth - 8));
    menuPosition.value = { top, left };
}

async function toggleMenu(event) {
    if (menuOpen.value) {
        closeMenu(true);
        return;
    }
    emit('menu-open');
    menuTrigger.value = event.currentTarget;
    placeMenu(120, 180);
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
        return;
    }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    if (!items.length) return;
    if (event.key === 'Home') items[0].focus();
    else if (event.key === 'End') items[items.length - 1].focus();
    else if (event.key === 'ArrowDown') items[(index + 1) % items.length].focus();
    else items[(index <= 0 ? items.length : index) - 1].focus();
}

function onPointerDown(event) {
    if (!menuOpen.value) return;
    if (menuEl.value?.contains(event.target) || menuTrigger.value?.contains(event.target)) return;
    closeMenu(false);
}

function onViewport() {
    if (menuOpen.value) placeMenu();
}

function chooseFile() {
    closeMenu(false);
    fileInput.value?.click();
}

function clearPreview() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = '';
    pendingFile.value = null;
    progress.value = 0;
}

function cancelPreview() {
    if (saving.value) return;
    clearPreview();
    photoError.value = '';
    dialog.value?.close();
    menuTrigger.value?.focus();
}

function rejectFile(message) {
    photoError.value = message;
    residentAccount.photoNotice = message;
    residentAccount.photoNoticeError = true;
    if (fileInput.value) fileInput.value.value = '';
}

function onFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    photoError.value = '';
    residentAccount.photoNotice = '';
    residentAccount.photoNoticeError = false;
    if (!file) return;
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (file.type === 'image/svg+xml' || /\.svg$/i.test(file.name)) {
        rejectFile('SVG images are not accepted.');
        return;
    }
    if (!allowed.includes(file.type)) {
        rejectFile('Choose a JPEG, PNG, or WEBP image.');
        return;
    }
    if (file.size < 1) {
        rejectFile('The profile photo is empty.');
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        rejectFile('The profile photo must be 2 MB or smaller.');
        return;
    }
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
        if (image.naturalWidth > 4000 || image.naturalHeight > 4000 || image.naturalWidth < 1) {
            URL.revokeObjectURL(url);
            rejectFile('The profile photo must be 4000 pixels or smaller on each side.');
            return;
        }
        clearPreview();
        pendingFile.value = file;
        previewUrl.value = url;
        dialog.value?.showModal();
        nextTick(() => cancelButton.value?.focus());
    };
    image.onerror = () => {
        URL.revokeObjectURL(url);
        rejectFile('The file is not a valid image.');
    };
    image.src = url;
}

async function savePhoto() {
    if (!pendingFile.value || saving.value) return;
    saving.value = true;
    progress.value = 0;
    photoError.value = '';
    const body = new FormData();
    body.append('photo', pendingFile.value);
    try {
        const response = await window.axios.post(`${apiBase}/profile/photo`, body, {
            onUploadProgress(event) {
                if (event.total) progress.value = Math.min(100, Math.round((event.loaded / event.total) * 100));
            },
        });
        applyResidentAccount(response.data.data);
        residentAccount.photoNotice = 'Profile photo updated.';
        residentAccount.photoNoticeError = false;
        clearPreview();
        dialog.value?.close();
        menuTrigger.value?.focus();
    } catch (exception) {
        photoError.value = exception.response?.data?.errors?.photo?.[0]
            || exception.response?.data?.message
            || 'The profile photo could not be saved.';
    } finally {
        saving.value = false;
    }
}

async function removePhoto() {
    if (!hasPhoto.value || removing.value) return;
    closeMenu(false);
    removing.value = true;
    photoError.value = '';
    residentAccount.photoNotice = '';
    try {
        const response = await window.axios.delete(`${apiBase}/profile/photo`);
        applyResidentAccount(response.data.data);
        residentAccount.photoNotice = 'Profile photo removed.';
        residentAccount.photoNoticeError = false;
        menuTrigger.value?.focus();
    } catch (exception) {
        photoError.value = exception.response?.data?.message || 'The profile photo could not be removed.';
    } finally {
        removing.value = false;
    }
}

onMounted(() => {
    window.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('resize', onViewport);
    window.addEventListener('scroll', onViewport, true);
});

onBeforeUnmount(() => {
    clearPreview();
    window.removeEventListener('pointerdown', onPointerDown);
    window.removeEventListener('resize', onViewport);
    window.removeEventListener('scroll', onViewport, true);
});

defineExpose({ close: () => closeMenu(false) });
</script>

<template>
    <div class="resident-profile-photo">
        <ResidentAvatar :name="name" :src="residentAccount.photoUrl || ''" />
        <button
            type="button"
            class="resident-profile-photo-edit"
            aria-label="Change profile photo"
            aria-haspopup="menu"
            :aria-expanded="menuOpen"
            @click="toggleMenu"
        >
            <MapIcon name="camera" />
        </button>
        <input
            ref="fileInput"
            class="resident-profile-file"
            type="file"
            accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
            @change="onFile"
        >
    </div>
    <Teleport to="body">
        <div
            v-if="menuOpen"
            ref="menuEl"
            class="resident-profile-menu resident-profile-photo-menu"
            role="menu"
            :style="{ top: `${menuPosition.top}px`, left: `${menuPosition.left}px` }"
            @keydown="onMenuKeydown"
        >
            <button type="button" role="menuitem" @click="chooseFile">
                <MapIcon name="camera" /> Upload photo
            </button>
            <button v-if="hasPhoto" type="button" role="menuitem" :disabled="removing" @click="removePhoto">
                <MapIcon name="eraser" /> {{ removing ? 'Removing…' : 'Remove photo' }}
            </button>
        </div>
        <dialog ref="dialog" class="resident-dialog resident-photo-dialog" aria-modal="true" aria-labelledby="resident-photo-title" @cancel.prevent="cancelPreview">
            <h2 id="resident-photo-title">Save profile photo</h2>
            <img v-if="previewUrl" :src="previewUrl" alt="Selected profile photo preview">
            <p v-if="saving" class="resident-profile-note">Uploading {{ progress }}%</p>
            <p v-if="photoError" class="resident-error" role="alert">{{ photoError }}</p>
            <div class="resident-dialog-actions">
                <button ref="cancelButton" type="button" class="resident-secondary" :disabled="saving" @click="cancelPreview">Cancel</button>
                <button type="button" class="resident-primary" :disabled="saving || !pendingFile" @click="savePhoto">
                    <MapIcon name="save" /> {{ saving ? 'Saving…' : 'Save Photo' }}
                </button>
            </div>
        </dialog>
    </Teleport>
</template>
