<script setup>
import { computed, ref, watch } from 'vue';
import Button from 'primevue/button';
import MapIcon from './MapIcon.vue';
import UserAvatar from './UserAvatar.vue';

const logoUrl = '/images/mapr-logo.png';
const props = defineProps({
    label: { type: String, default: 'Resident' },
    navigation: { type: Array, default: () => [] },
    badges: { type: Object, default: () => ({}) },
    photoUrl: { type: String, default: '' },
    homeUrl: { type: String, default: '/' },
    userName: { type: String, default: '' },
    jobTitle: { type: String, default: 'Resident' },
    logoutUrl: { type: String, default: '/logout' },
    csrfToken: { type: String, default: '' },
    collapsed: { type: Boolean, default: false },
});
defineEmits(['navigate']);

const openGroups = ref({});

watch(() => props.navigation, (items) => {
    const next = { ...openGroups.value };
    for (const item of items) {
        if (!item.children?.length) continue;
        const key = item.key || item.label;
        if (item.active || next[key] === undefined) {
            next[key] = true;
        }
    }
    openGroups.value = next;
}, { immediate: true, deep: true });

const groups = computed(() => props.navigation.map((item) => {
    if (!item.children?.length) return item;
    const key = item.key || item.label;
    return {
        ...item,
        key,
        expanded: Boolean(openGroups.value[key] || item.active),
    };
}));

function toggleGroup(key) {
    openGroups.value = {
        ...openGroups.value,
        [key]: !openGroups.value[key],
    };
}
</script>

<template>
    <aside id="workspace-sidebar" class="resident-sidebar" :class="{ 'is-collapsed': collapsed }" :aria-label="`${label} navigation`">
        <div class="resident-brand">
            <a :href="homeUrl" :aria-label="`MapR ${label} home`" :title="`MapR ${label} home`">
                <img :src="logoUrl" alt="MapR logo" width="40" height="40">
                <span class="resident-label resident-brand-copy"><strong>MapR</strong><span>{{ label }} workspace</span></span>
            </a>
        </div>
        <nav aria-label="Main navigation">
            <p class="resident-nav-heading resident-label">Workspace</p>
            <template v-for="item in groups" :key="item.key || item.to">
                <div v-if="item.children?.length" class="workspace-nav-group" :class="{ 'is-expanded': item.expanded, 'is-active': item.active }">
                    <button
                        type="button"
                        class="workspace-nav-parent"
                        :class="{ 'router-link-active': item.active }"
                        :aria-expanded="item.expanded ? 'true' : 'false'"
                        :aria-controls="`workspace-nav-${item.key}`"
                        :title="item.label"
                        @click="toggleGroup(item.key)"
                    >
                        <MapIcon :name="item.icon" />
                        <span class="resident-label">{{ item.label }}</span>
                        <MapIcon name="chevron" class="workspace-nav-chevron resident-label" />
                    </button>
                    <div v-show="item.expanded" :id="`workspace-nav-${item.key}`" class="workspace-nav-children">
                        <router-link
                            v-for="child in item.children"
                            :key="child.to"
                            :to="child.to"
                            :aria-label="child.label"
                            :title="child.label"
                            :aria-current="child.active ? 'page' : undefined"
                            active-class=""
                            exact-active-class="router-link-active"
                            :class="{ 'router-link-active': child.active }"
                            @click="$emit('navigate')"
                        >
                            <MapIcon :name="child.icon" />
                            <span class="resident-label">{{ child.label }}</span>
                            <span v-if="badges[child.badge]" class="resident-label workspace-nav-badge" :title="badges.badge_meaning">{{ badges[child.badge] }}</span>
                        </router-link>
                    </div>
                </div>
                <router-link
                    v-else
                    :to="item.to"
                    :aria-label="item.label"
                    :title="item.label"
                    :aria-current="item.active ? 'page' : undefined"
                    active-class=""
                    exact-active-class="router-link-active"
                    :class="{ 'router-link-active': item.active }"
                    @click="$emit('navigate')"
                >
                    <MapIcon :name="item.icon" /><span class="resident-label">{{ item.label }}</span>
                    <span v-if="badges[item.badge]" class="resident-label workspace-nav-badge" :title="badges.badge_meaning">{{ badges[item.badge] }}</span>
                </router-link>
            </template>
        </nav>
        <div class="resident-account-area">
            <div class="resident-account-identity" :title="userName || jobTitle">
                <UserAvatar :name="userName" :src="photoUrl" />
                <span class="resident-label resident-account-copy"><strong class="resident-account-name">{{ userName || jobTitle }}</strong><span>{{ jobTitle }}</span></span>
            </div>
            <form novalidate method="POST" :action="logoutUrl">
                <!-- native-ok: hidden CSRF token must accompany the native POST logout form. -->
                <input type="hidden" name="_token" :value="csrfToken">
                <Button
                    type="submit"
                    class="resident-logout"
                    size="small"
                    title="Sign out"
                    severity="secondary"
                    text
                    aria-label="Sign out"
                >
                    <MapIcon name="logout" />
                    <span class="resident-label">Sign out</span>
                </Button>
            </form>
        </div>
    </aside>
</template>
