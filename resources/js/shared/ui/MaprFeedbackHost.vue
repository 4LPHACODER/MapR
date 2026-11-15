<script setup>
import ConfirmDialog from 'primevue/confirmdialog';
import Toast from 'primevue/toast';

// A toast is 22rem wide and sits 2rem from the right edge: 384px, wider than a 375px phone.
// Below 480px it spans the viewport minus a 0.75rem gutter instead.
const toastBreakpoints = {
    '480px': { width: 'calc(100vw - 1.5rem)', right: '0.75rem', top: '0.75rem' },
};

// Same footprint as the old resident dialog: 28rem, or the viewport minus 1rem each side.
// The footer wraps so long button labels stay on screen instead of running past the edge.
const confirmPt = {
    root: { style: { width: 'min(28rem, calc(100vw - 2rem))' } },
    footer: { style: { flexWrap: 'wrap' } },
};
</script>

<template>
    <Toast position="top-right" :breakpoints="toastBreakpoints" />
    <ConfirmDialog :draggable="false" :pt="confirmPt" />
</template>
