<script setup>
defineProps({
    name: { type: String, required: true },
});
</script>

<template>
    <svg class="cdrrmo-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path v-if="name === 'dashboard'" d="M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1v-10.5Z" />
        <path v-else-if="name === 'map'" d="M9 18 3 21V6l6-3 6 3 6-3v15l-6 3-6-3Z M3 6l6 3 6-3 6 3" />
        <path v-else-if="name === 'disaster'" d="M12 2v2M4.93 4.93l1.41 1.41M2 12h2M4.93 19.07l1.41-1.41M12 20v2M19.07 19.07l-1.41-1.41M20 12h2M19.07 4.93l-1.41 1.41M8 12a4 4 0 1 0 8 0 4 4 0 0 0-8 0Z" />
        <path v-else-if="name === 'users'" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
        <path v-else-if="name === 'family'" d="M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM17 10a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM3 20v-1.5A3.5 3.5 0 0 1 6.5 15H12a3.5 3.5 0 0 1 3.5 3.5V20M14.5 20v-1a3 3 0 0 1 3-3H19a2.5 2.5 0 0 1 2.5 2.5V20" />
        <path v-else-if="name === 'save'" d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z M17 21v-8H7v8M7 3v5h8" />
        <path v-else-if="name === 'reports'" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z M14 2v6h6" />
        <path v-else-if="name === 'pin-check'" d="M12 21s7-4.5 7-11a7 7 0 1 0-14 0c0 6.5 7 11 7 11ZM9 10l2 2 4-4" />
        <path v-else-if="name === 'user-cog'" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.26.604.852.997 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
        <path v-else-if="name === 'history'" d="M3 12a9 9 0 1 0 3-6.7M3 3v6h6M12 7v5l3 3" />
        <path v-else-if="name === 'user'" d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
        <path v-else-if="name === 'logout'" d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
        <path v-else-if="name === 'chevron'" d="m9 18 6-6-6-6" />
        <path v-else-if="name === 'impact'" d="M12 3v18M3 12h18M7 7l10 10M17 7 7 17" />
        <path v-else-if="name === 'bell'" d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0" />
        <path v-else-if="name === 'collapse'" d="M9 3H5a2 2 0 0 0-2 2v4M15 21h4a2 2 0 0 0 2-2v-4M3 9l4-4M21 15l-4 4" />
        <path v-else-if="name === 'building'" d="M4 21h16M6 21V5l6-2 6 2v16M10 9h.01M14 9h.01M10 13h.01M14 13h.01M10 17h4" />
        <path v-else-if="name === 'home'" d="M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1v-10.5Z" />
        <path v-else-if="name === 'polygon'" d="M12 3 20 7.5v9L12 21 4 16.5v-9L12 3Z" />
        <path v-else-if="name === 'pencil'" d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" />
        <path v-else-if="name === 'eraser'" d="M20 20H8L3 15l9-9 8 8-4 4ZM7 20l5-5" />
        <path v-else-if="name === 'focus'" d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM12 2v3M12 19v3M2 12h3M19 12h3" />
        <path v-else-if="name === 'layers'" d="m12 2 10 5-10 5L2 7l10-5ZM2 12l10 5 10-5M2 17l10 5 10-5" />
        <path v-else-if="name === 'reset'" d="M3 12a9 9 0 1 0 3-6.7M3 3v6h6" />
        <path v-else-if="name === 'filter-reset'" d="M3 5h18l-7 8v6l-4-2v-4L3 5ZM15 19l4 4M19 19l-4 4" />
        <path v-else-if="name === 'plus'" d="M12 5v14M5 12h14" />
        <path v-else-if="name === 'refresh'" d="M21 12a9 9 0 0 1-15.5 6.3M3 12A9 9 0 0 1 18.5 5.7M21 3v6h-6M3 21v-6h6" />
        <path v-else-if="name === 'eye'" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
        <path v-else-if="name === 'pin'" d="M12 21s7-4.5 7-11a7 7 0 1 0-14 0c0 6.5 7 11 7 11Z M12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />
        <path v-else-if="name === 'file'" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z M14 2v6h6M8 13h8M8 17h5" />
        <path v-else-if="name === 'x'" d="M6 6l12 12M18 6 6 18" />
        <path v-else-if="name === 'download'" d="M12 3v12M7 10l5 5 5-5M5 21h14" />
        <path v-else-if="name === 'search'" d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM21 21l-4.3-4.3" />
        <path v-else-if="name === 'clipboard'" d="M9 4h6a2 2 0 0 1 2 2v1H7V6a2 2 0 0 1 2-2ZM8 3h8v3H8V3ZM6 7h12v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7Z" />
        <path v-else-if="name === 'clipboard-check'" d="M9 4h6a2 2 0 0 1 2 2v1H7V6a2 2 0 0 1 2-2ZM8 3h8v3H8V3ZM6 7h12v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7ZM9 14l2 2 4-4" />
        <path v-else-if="name === 'file-pen'" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h7M14 2v6h6M14 2l6 6M16 18l4 1-1-4-6-6-3 3 6 6Z" />
        <path v-else-if="name === 'undo'" d="M9 14 4 9l5-5M4 9h10a6 6 0 1 1 0 12h-3" />
        <path v-else-if="name === 'send'" d="M22 2 11 13M22 2 15 22l-4-9-9-4 20-7Z" />
        <path v-else-if="name === 'user-alert'" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 8v6M19 18h.01" />
        <path v-else-if="name === 'flag'" d="M5 21V4M5 4h11l-2 4 2 4H5" />
        <path v-else-if="name === 'link'" d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1M14 11a5 5 0 0 0-7.1-.1l-2 2a5 5 0 0 0 7.1 7.1l1.1-1.1" />
        <path v-else-if="name === 'external'" d="M14 5h5v5M19 5l-9 9M10 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-4" />
        <path v-else-if="name === 'check'" d="M20 6 9 17l-5-5" />
        <path v-else-if="name === 'pen'" d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" />
        <path v-else-if="name === 'zoom-in'" d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM21 21l-4.3-4.3M11 8v6M8 11h6" />
        <path v-else-if="name === 'zoom-out'" d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM21 21l-4.3-4.3M8 11h6" />
        <path v-else-if="name === 'printer'" d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6v-7Z" />
        <path v-else-if="name === 'panel'" d="M4 4h16v16H4V4ZM15 4v16" />
        <path v-else-if="name === 'warning'" d="M12 3 2 20h20L12 3ZM12 9v5M12 17h.01" />
        <path v-else-if="name === 'lock'" d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12v9H6v-9Z" />
        <path v-else-if="name === 'camera'" d="M4 8h3l2-2h6l2 2h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z M12 16a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
        <path v-else-if="name === 'clock'" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 7v5l3 2" />
        <path v-else-if="name === 'alert-circle'" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 8v5M12 16h.01" />
        <path v-else-if="name === 'circle-check'" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM8.5 12.5 11 15l4.5-5" />
        <g v-else-if="name === 'more'" fill="currentColor" stroke="none">
            <circle cx="6" cy="12" r="1.2" />
            <circle cx="12" cy="12" r="1.2" />
            <circle cx="18" cy="12" r="1.2" />
        </g>
    </svg>
</template>
