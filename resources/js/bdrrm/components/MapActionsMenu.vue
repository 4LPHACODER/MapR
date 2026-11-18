<script setup>
import { computed, ref, useId } from 'vue';
import Button from 'primevue/button';
import Menu from 'primevue/menu';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    hasPolygon: { type: Boolean, default: false },
    registryOpen: { type: Boolean, default: false },
    mapLayersOpen: { type: Boolean, default: false },
    hasMapLayers: { type: Boolean, default: false },
    panelClass: { type: String, default: 'bdrrm-map-actions-menu' },
});

const emit = defineEmits(['command']);
const open = defineModel('open', { type: Boolean, default: false });
const menuId = useId();
const menu = ref(null);
const trigger = ref(null);

function action(label, icon, command, disabled = false) {
    return {
        label,
        icon,
        disabled,
        command: () => {
            trigger.value?.$el?.focus();
            emit('command', command);
        },
    };
}

const items = computed(() => [
    {
        label: 'Area selection',
        items: [
            action('Draw area', 'polygon', 'draw'),
            action('Edit area', 'pencil', 'edit', !props.hasPolygon),
            action('Clear area', 'eraser', 'clear-area', !props.hasPolygon),
            action('Fit to area', 'focus', 'fit', !props.hasPolygon),
        ],
    },
    {
        label: 'Map controls',
        items: [
            action(props.registryOpen ? 'Hide registry layers' : 'Show registry layers', 'layers', 'registry'),
            ...(props.hasMapLayers ? [action(props.mapLayersOpen ? 'Hide Map Layers' : 'Show Map Layers', 'layers', 'map-layers')] : []),
            action('Reset view', 'reset', 'reset'),
            action('Clear filters', 'filter-reset', 'filters'),
        ],
    },
]);
</script>

<template>
    <div class="shrink-0">
        <Button
            ref="trigger"
            type="button"
            size="small"
            severity="secondary"
            outlined
            class="map-tool-btn bdrrm-map-actions-trigger"
            aria-haspopup="menu"
            :aria-controls="menuId"
            :aria-expanded="open"
            @click="menu.toggle($event)"
        >
            <MapIcon name="polygon" />
            Map Actions
            <MapIcon name="chevron" class="bdrrm-chevron-down" />
        </Button>
        <Menu
            :id="menuId"
            ref="menu"
            :model="items"
            popup
            aria-label="Map Actions"
            :class="panelClass"
            :pt="{ transition: { css: false } }"
            @show="open = true"
            @hide="open = false"
        >
            <template #itemicon="{ item }"><MapIcon :name="item.icon" /></template>
        </Menu>
    </div>
</template>
