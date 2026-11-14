<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    hasPolygon: { type: Boolean, default: false },
    registryOpen: { type: Boolean, default: false },
    mapLayersOpen: { type: Boolean, default: false },
    hasMapLayers: { type: Boolean, default: false },
});

const emit = defineEmits(['command']);
const open = defineModel('open', { type: Boolean, default: false });

const trigger = ref(null);
const menuEl = ref(null);
const position = ref({ top: 0, left: 0 });

const areaItems = computed(() => [
    { command: 'draw', label: 'Draw area', icon: 'polygon', disabled: false },
    { command: 'edit', label: 'Edit area', icon: 'pencil', disabled: !props.hasPolygon },
    { command: 'clear-area', label: 'Clear area', icon: 'eraser', disabled: !props.hasPolygon },
    { command: 'fit', label: 'Fit to area', icon: 'focus', disabled: !props.hasPolygon },
]);

const controlItems = computed(() => {
    const items = [
        { command: 'registry', label: props.registryOpen ? 'Hide registry layers' : 'Show registry layers', icon: 'layers', disabled: false },
    ];
    if (props.hasMapLayers) {
        items.push({
            command: 'map-layers',
            label: props.mapLayersOpen ? 'Hide Map Layers' : 'Show Map Layers',
            icon: 'layers',
            disabled: false,
        });
    }
    items.push(
        { command: 'reset', label: 'Reset view', icon: 'reset', disabled: false },
        { command: 'filters', label: 'Clear filters', icon: 'filter-reset', disabled: false },
    );
    return items;
});

function enabledItems() {
    return [...(menuEl.value?.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])') || [])];
}

function placeMenu() {
    const button = trigger.value;
    const menu = menuEl.value;
    if (!button || !menu) return;
    const rect = button.getBoundingClientRect();
    const menuHeight = menu.offsetHeight;
    const menuWidth = menu.offsetWidth;
    const nav = document.querySelector('.field-bottom-nav');
    const navVisible = nav && getComputedStyle(nav).display !== 'none';
    const limit = navVisible ? nav.getBoundingClientRect().top : window.innerHeight;
    let top = rect.bottom + 8;
    if (top + menuHeight > limit - 8) top = rect.top - menuHeight - 8;
    top = Math.max(8, Math.min(top, limit - menuHeight - 8));
    let left = rect.right - menuWidth;
    left = Math.max(8, Math.min(left, window.innerWidth - menuWidth - 8));
    position.value = { top, left };
}

function close(restoreFocus) {
    const button = trigger.value;
    open.value = false;
    if (restoreFocus) button?.focus();
}

function toggle() {
    if (open.value) close(true);
    else open.value = true;
}

function choose(item) {
    if (item.disabled) return;
    close(true);
    emit('command', item.command);
}

function onPointerDown(event) {
    if (!open.value) return;
    if (menuEl.value?.contains(event.target) || trigger.value?.contains(event.target)) return;
    close(false);
}

function onMenuKeydown(event) {
    if (!open.value) return;
    const items = enabledItems();
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close(true);
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        items[index < 0 ? 0 : (index + 1) % items.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        items[index <= 0 ? items.length - 1 : index - 1]?.focus();
    } else if (event.key === 'Home') {
        event.preventDefault();
        items[0]?.focus();
    } else if (event.key === 'End') {
        event.preventDefault();
        items[items.length - 1]?.focus();
    }
}

function listen(active) {
    const method = active ? 'addEventListener' : 'removeEventListener';
    document[method]('pointerdown', onPointerDown);
    window[method]('keydown', onMenuKeydown);
    window[method]('resize', placeMenu);
    window[method]('scroll', placeMenu, true);
}

watch(open, async (isOpen) => {
    listen(false);
    if (!isOpen) return;
    listen(true);
    await nextTick();
    placeMenu();
    enabledItems()[0]?.focus();
});

onBeforeUnmount(() => listen(false));
</script>

<template>
    <div class="bdrrm-map-actions">
        <button
            ref="trigger"
            type="button"
            class="mapr-button mapr-button-secondary map-tool-btn bdrrm-map-actions-trigger"
            aria-haspopup="menu"
            :aria-expanded="open"
            @click="toggle"
        >
            <MapIcon name="polygon" />
            Map Actions
            <MapIcon name="chevron" class="bdrrm-chevron-down" />
        </button>
        <Teleport to="body">
            <div
                v-if="open"
                ref="menuEl"
                class="bdrrm-map-actions-menu"
                role="menu"
                aria-label="Map Actions"
                :style="{ top: `${position.top}px`, left: `${position.left}px` }"
                @mousedown.prevent
            >
                <div role="group" aria-label="Area selection">
                    <p class="bdrrm-menu-label">Area selection</p>
                    <button
                        v-for="item in areaItems"
                        :key="item.command"
                        type="button"
                        role="menuitem"
                        :aria-disabled="item.disabled ? 'true' : 'false'"
                        :title="item.disabled ? 'Draw an area first.' : undefined"
                        @click="choose(item)"
                    >
                        <MapIcon :name="item.icon" />
                        {{ item.label }}
                    </button>
                </div>
                <div role="group" aria-label="Map controls">
                    <p class="bdrrm-menu-label">Map controls</p>
                    <button
                        v-for="item in controlItems"
                        :key="item.command"
                        type="button"
                        role="menuitem"
                        @click="choose(item)"
                    >
                        <MapIcon :name="item.icon" />
                        {{ item.label }}
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
