<script setup>
import { computed, inject, nextTick, onBeforeUnmount, ref } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import Menu from 'primevue/menu';
import MapIcon from '../../shared/components/MapIcon.vue';
import ResidentAvatar from './ResidentAvatar.vue';
import { applyResidentAccount, residentAccount } from '../accountState';

defineProps({
    name: { type: String, default: '' },
});

const apiBase = inject('apiBase');
const menuOpen = ref(false);
const menuTrigger = ref(null);
const photoMenu = ref(null);
const pointerMotion = ref(false);
const fileInput = ref(null);
const cancelButton = ref(null);
const dialogVisible = ref(false);
const previewUrl = ref('');
const pendingFile = ref(null);
const photoError = ref('');
const saving = ref(false);
const progress = ref(0);
const removing = ref(false);

const hasPhoto = computed(() => Boolean(residentAccount.photoUrl));

const menuItems = computed(() => [
    { label: 'Upload photo', command: chooseFile },
    ...(hasPhoto.value ? [{ label: 'Remove photo', disabled: removing.value, command: removePhoto }] : []),
]);

function toggleMenu(event) {
    pointerMotion.value = event.detail > 0;
    menuTrigger.value = event.currentTarget;
    photoMenu.value?.toggle(event);
}

function chooseFile(event) {
    pointerMotion.value = event.originalEvent.detail > 0;
    photoMenu.value?.hide();
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
    photoError.value = '';
    dialogVisible.value = false;
    menuTrigger.value?.focus();
}

function onDialogVisibilityChange(visible) {
    if (visible) {
        dialogVisible.value = true;
        return;
    }
    cancelPreview();
}

function focusCancelButton() {
    const button = cancelButton.value?.$el || cancelButton.value;
    button?.focus?.();
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
        dialogVisible.value = true;
        nextTick(focusCancelButton);
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
        dialogVisible.value = false;
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
    photoMenu.value?.hide();
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
        residentAccount.photoNotice = exception.response?.data?.message || 'The profile photo could not be removed.';
        residentAccount.photoNoticeError = true;
    } finally {
        removing.value = false;
    }
}

onBeforeUnmount(clearPreview);
</script>

<template>
    <div class="resident-profile-photo">
        <ResidentAvatar :name="name" :src="residentAccount.photoUrl || ''" />
        <Button
            type="button"
            size="small"
            :disabled="saving || removing"
            class="resident-profile-photo-edit"
            aria-label="Change profile photo"
            aria-haspopup="menu"
            aria-controls="resident-photo-menu"
            :aria-expanded="menuOpen"
            @click="toggleMenu"
        >
            <template #icon><MapIcon name="camera" /></template>
        </Button>
        <Menu
            id="resident-photo-menu"
            ref="photoMenu"
            :model="menuItems"
            popup
            append-to=".resident-app"
            class="resident-profile-photo-menu"
            :pt="{ transition: { css: false } }"
            @show="menuOpen = true"
            @hide="menuOpen = false"
        />
        <!-- native-ok: hidden input backs the custom profile photo picker -->
        <input
            ref="fileInput"
            class="resident-profile-file"
            type="file"
            hidden
            aria-label="Choose profile photo"
            accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
            @change="onFile"
        >
    </div>
    <Dialog
        :visible="dialogVisible"
        modal
        :closable="false"
        :close-on-escape="!saving"
        :draggable="false"
        :show-header="false"
        append-to=".resident-app"
        class="resident-photo-dialog"
        :class="{ 'resident-photo-motion': pointerMotion }"
        :pt="{ transition: { name: 'resident-photo', css: pointerMotion } }"
        aria-labelledby="resident-photo-title"
        @update:visible="onDialogVisibilityChange"
        @show="focusCancelButton"
        @after-hide="clearPreview"
    >
        <h2 id="resident-photo-title">Save profile photo</h2>
        <img v-if="previewUrl" :src="previewUrl" alt="Selected profile photo preview">
        <p v-if="saving" class="resident-profile-note">Uploading {{ progress }}%</p>
        <Message v-if="photoError" severity="error" :closable="false" class="resident-error">{{ photoError }}</Message>
        <div class="resident-modal-actions">
            <Button size="small" ref="cancelButton" type="button" severity="secondary" variant="outlined" class="resident-secondary" :disabled="saving" autofocus @click="cancelPreview">Cancel</Button>
            <Button size="small" type="button" class="resident-primary" :disabled="saving || !pendingFile" @click="savePhoto">
                <MapIcon name="save" /> {{ saving ? 'Saving…' : 'Save photo' }}
            </Button>
        </div>
    </Dialog>
</template>
