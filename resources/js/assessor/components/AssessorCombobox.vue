<script setup>
import { computed, ref, watch } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    options: { type: Array, default: () => [] },
    modelValue: { type: [Number, String, null], default: null },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: 'Type to search personnel...' },
    label: { type: String, default: 'Select RDANA Assessor Personnel' },
});

const emit = defineEmits(['update:modelValue']);

const query = ref('');
const menuOpen = ref(false);
const highlight = ref(0);
const listId = `assessor-combobox-${Math.random().toString(36).slice(2, 9)}`;

const selected = computed(() => props.options.find((row) => Number(row.id) === Number(props.modelValue)) || null);

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    return props.options.filter((row) => {
        if (!needle) return true;
        return String(row.name || '').toLowerCase().includes(needle);
    });
});

watch(selected, (person) => {
    if (person && !menuOpen.value) {
        query.value = person.name;
    }
}, { immediate: true });

watch(() => props.options, () => {
    if (selected.value && !menuOpen.value) {
        query.value = selected.value.name;
    }
});

function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return `${parts[0][0] || ''}${parts[parts.length - 1][0] || ''}`.toUpperCase();
}

function openMenu() {
    if (props.disabled || props.loading) return;
    menuOpen.value = true;
    highlight.value = 0;
}

function closeMenu() {
    window.setTimeout(() => {
        menuOpen.value = false;
        if (selected.value) {
            query.value = selected.value.name;
        }
    }, 120);
}

function choose(person) {
    emit('update:modelValue', Number(person.id));
    query.value = person.name;
    menuOpen.value = false;
}

function clearSelection() {
    emit('update:modelValue', null);
    query.value = '';
    menuOpen.value = true;
    highlight.value = 0;
}

function onInput() {
    menuOpen.value = true;
    highlight.value = 0;
    if (selected.value && query.value.trim().toLowerCase() !== String(selected.value.name || '').toLowerCase()) {
        emit('update:modelValue', null);
    }
}

function moveHighlight(step) {
    if (!filtered.value.length) return;
    menuOpen.value = true;
    highlight.value = (highlight.value + step + filtered.value.length) % filtered.value.length;
}

function chooseHighlighted() {
    const person = filtered.value[highlight.value];
    if (person) choose(person);
    else menuOpen.value = false;
}
</script>

<template>
    <div class="assessor-combobox" :data-open="menuOpen ? 'true' : 'false'">
        <label class="assessor-combobox-label" for="assessor-combobox-input">
            <MapIcon name="users-round" /> {{ label }}
        </label>
        <div class="assessor-combobox-field">
            <span class="assessor-combobox-search" aria-hidden="true"><MapIcon name="search" /></span>
            <input
                id="assessor-combobox-input"
                v-model="query"
                type="text"
                role="combobox"
                aria-autocomplete="list"
                :aria-controls="listId"
                :aria-expanded="menuOpen ? 'true' : 'false'"
                :aria-activedescendant="menuOpen && filtered[highlight] ? `${listId}-opt-${filtered[highlight].id}` : undefined"
                :placeholder="placeholder"
                :disabled="disabled || loading"
                autocomplete="off"
                @focus="openMenu"
                @input="onInput"
                @blur="closeMenu"
                @keydown.down.prevent="moveHighlight(1)"
                @keydown.up.prevent="moveHighlight(-1)"
                @keydown.enter.prevent="chooseHighlighted"
                @keydown.escape.prevent="menuOpen = false"
            >
            <button
                v-if="selected"
                type="button"
                class="assessor-combobox-clear"
                aria-label="Clear selected assessor"
                @mousedown.prevent="clearSelection"
            >
                <MapIcon name="x" />
            </button>
        </div>
        <p v-if="loading" class="assessor-combobox-hint">Loading personnel…</p>
        <ul
            v-else-if="menuOpen"
            :id="listId"
            class="assessor-combobox-menu"
            role="listbox"
            aria-label="RDANA Assessor Personnel"
        >
            <li v-if="!filtered.length" class="assessor-combobox-empty" role="presentation">
                No assessor matches “{{ query.trim() || 'your search' }}”.
            </li>
            <li v-for="(person, index) in filtered" :key="person.id" role="presentation">
                <button
                    :id="`${listId}-opt-${person.id}`"
                    type="button"
                    role="option"
                    :aria-selected="Number(person.id) === Number(modelValue) ? 'true' : 'false'"
                    :class="{ 'is-active': index === highlight, 'is-selected': Number(person.id) === Number(modelValue) }"
                    @mousedown.prevent="choose(person)"
                >
                    <span class="assessor-combobox-avatar" aria-hidden="true">{{ initials(person.name) }}</span>
                    <span class="assessor-combobox-copy">
                        <strong>{{ person.name }}</strong>
                        <span>RDANA Assessor Personnel</span>
                        <span class="assessor-combobox-workload">
                            {{ Number(person.pending_count || 0) }} Pending
                            · {{ Number(person.assessing_count || 0) }} Assessing
                        </span>
                    </span>
                    <span v-if="Number(person.id) === Number(modelValue)" class="assessor-combobox-check" aria-hidden="true">
                        <MapIcon name="circle-check" />
                    </span>
                </button>
            </li>
        </ul>
        <p v-if="selected && !menuOpen" class="assessor-combobox-selected">
            <MapIcon name="circle-check" />
            <span>{{ selected.name }} selected</span>
        </p>
    </div>
</template>
