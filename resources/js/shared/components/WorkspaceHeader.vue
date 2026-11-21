<script setup>
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import Button from 'primevue/button';
import Popover from 'primevue/popover';
import MapIcon from './MapIcon.vue';
import UserAvatar from './UserAvatar.vue';

defineProps({
    label: { type: String, default: 'Resident' },
    photoUrl: { type: String, default: '' },
    attention: { type: Array, default: null },
    showMobileMenu: { type: Boolean, default: false },
    mobileOpen: { type: Boolean, default: false },
    title: { type: String, default: '' },
    collapsed: { type: Boolean, default: false },
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
});

defineEmits(['toggle-collapse', 'open-menu']);

const route = useRoute();
const notesOpen = ref(false);
const accountOpen = ref(false);
const notificationsPopover = ref(null);
const accountPopover = ref(null);

function togglePopover(popover, event) {
    popover?.toggle(event);
}

function closePopover(popover) {
    popover?.hide();
}

function popoverPassThrough(id, labelledBy) {
    return {
        root: {
            id,
            class: 'resident-header-popover',
            'aria-labelledby': labelledBy,
        },
        content: { class: 'resident-header-popover-content' },
    };
}

watch(() => route.fullPath, () => {
    closePopover(notificationsPopover.value);
    closePopover(accountPopover.value);
});
</script>

<template>
    <header class="resident-header">
        <div class="resident-header-heading">
            <Button
                type="button"
                class="resident-collapse p-0"
                severity="secondary"
                text
                :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                :aria-expanded="!collapsed"
                aria-controls="workspace-sidebar"
                :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                @click="$emit('toggle-collapse')"
            >
                <template #icon="{ class: iconClass }"><MapIcon name="panel" :class="iconClass" /></template>
            </Button>
            <Button v-if="showMobileMenu" class="workspace-menu-trigger" type="button" severity="secondary" text
                aria-label="Open navigation menu" aria-controls="workspace-mobile-sidebar" :aria-expanded="mobileOpen" @click="$emit('open-menu')">
                <template #icon="{ class: iconClass }"><MapIcon name="panel" :class="iconClass" /></template>
            </Button>
            <h1>{{ title }}</h1>
        </div>
        <div class="resident-header-tools">
            <slot name="actions" />
            <div v-if="attention" class="resident-notify">
                <Button
                    type="button"
                    class="resident-notify-trigger p-0"
                    severity="secondary"
                    variant="outlined"
                    rounded
                    :aria-label="attention.length ? `Notifications, ${attention.length} need attention` : 'Notifications'"
                    aria-controls="resident-notifications-popover"
                    :aria-expanded="notesOpen"
                    @click="togglePopover(notificationsPopover, $event)"
                >
                    <template #icon="{ class: iconClass }">
                        <MapIcon name="bell" :class="iconClass" />
                        <span v-if="attention.length" class="resident-notify-count">{{ attention.length }}</span>
                    </template>
                </Button>
                <Popover
                    ref="notificationsPopover"
                    append-to=".resident-app"
                    :pt="popoverPassThrough('resident-notifications-popover', 'resident-notifications-heading')"
                    @show="notesOpen = true"
                    @hide="notesOpen = false"
                >
                    <section class="resident-header-popover-view" aria-labelledby="resident-notifications-heading">
                        <header class="resident-header-popover-heading">
                            <div>
                                <h2 id="resident-notifications-heading">Needs your attention</h2>
                            </div>
                            <span v-if="attention.length" class="resident-header-popover-count">
                                {{ attention.length }} {{ attention.length === 1 ? 'update' : 'updates' }}
                            </span>
                        </header>
                        <div v-if="!attention.length" class="resident-header-empty" role="status">
                            <span class="resident-header-empty-icon"><MapIcon name="bell" /></span>
                            <strong>You're all caught up</strong>
                            <p>Updates about your reports will appear here.</p>
                        </div>
                        <nav v-else class="resident-header-update-list" aria-label="Report updates">
                            <router-link
                                v-for="item in attention"
                                :key="item.id"
                                class="resident-header-update"
                                :to="`/reports/${item.id}/track`"
                                @click="closePopover(notificationsPopover)"
                            >
                                <span class="resident-header-update-icon"><MapIcon name="reports" /></span>
                                <span class="resident-header-update-copy">
                                    <strong>{{ item.reference }}</strong>
                                    <span>{{ item.reason }}</span>
                                </span>
                                <MapIcon name="chevron" class="resident-header-update-chevron" />
                            </router-link>
                        </nav>
                    </section>
                </Popover>
            </div>
            <div class="resident-account-menu">
                <Button
                    type="button"
                    class="resident-account-trigger p-0"
                    severity="secondary"
                    variant="outlined"
                    rounded
                    :aria-label="`Account menu for ${userName || label}`"
                    aria-controls="resident-account-popover"
                    :aria-expanded="accountOpen"
                    @click="togglePopover(accountPopover, $event)"
                >
                    <UserAvatar :name="userName" :src="photoUrl" />
                </Button>
                <Popover
                    ref="accountPopover"
                    append-to=".resident-app"
                    :pt="popoverPassThrough('resident-account-popover', 'resident-account-heading')"
                    @show="accountOpen = true"
                    @hide="accountOpen = false"
                >
                    <section class="resident-header-popover-view" aria-labelledby="resident-account-heading">
                        <header class="resident-account-summary">
                            <UserAvatar :name="userName" :src="photoUrl" />
                            <div class="resident-account-summary-copy">
                                <h2 id="resident-account-heading">{{ userName || label }}</h2>
                                <span>{{ userEmail || `MapR ${label}` }}</span>
                            </div>
                        </header>
                        <router-link class="resident-header-profile-link" to="/profile" @click="closePopover(accountPopover)">
                            <span class="resident-header-profile-icon"><MapIcon name="user" /></span>
                            <span class="resident-header-profile-copy">
                                <strong>My profile</strong>
                                <span>View and update your details</span>
                            </span>
                            <MapIcon name="chevron" class="resident-header-update-chevron" />
                        </router-link>
                    </section>
                </Popover>
            </div>
        </div>
    </header>
</template>
