<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const slides = [
    {
        src: '/images/hero-1.webp',
        width: 896,
        height: 1344,
        alt: 'A resident photographs a damaged building while a location card marks the site for assessment.',
    },
    {
        src: '/images/hero-2.webp',
        width: 896,
        height: 1344,
        alt: 'An RDANA assessor photographs a damaged building while reviewing preliminary damage classifications.',
    },
    {
        src: '/images/hero-3.webp',
        width: 896,
        height: 1344,
        alt: 'A CDRRMO staff member reviews a map-based barangay assessment on a desktop monitor.',
    },
];

const count = slides.length;
const frames = [...slides, slides[0]];
const index = ref(0);
const frameWidth = ref(0);
const viewportEl = ref(null);
const motion = ref(true);
const playing = ref(true);
const hovering = ref(false);
const keyboardHold = ref(false);
let timer = 0;
let transitionTimer = 0;
let busy = false;
let lastPointer = 'mouse';
let startX = null;

const activeDot = computed(() => index.value % count);
const trackStyle = computed(() => ({
    transform: `translate3d(-${index.value * frameWidth.value}px, 0, 0)`,
    transition: motion.value ? 'transform 600ms ease' : 'none',
}));

function measure() {
    frameWidth.value = viewportEl.value?.clientWidth ?? 0;
}

function reducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function canPlay() {
    return playing.value && !hovering.value && !keyboardHold.value && !document.hidden;
}

function clearTimer() {
    window.clearTimeout(timer);
    timer = 0;
}

function schedule() {
    clearTimer();

    if (!canPlay()) {
        return;
    }

    timer = window.setTimeout(() => {
        timer = 0;
        showNext();
    }, 3000);
}

function finishTransition() {
    window.clearTimeout(transitionTimer);
    transitionTimer = 0;
    busy = false;

    if (index.value === count) {
        motion.value = false;
        index.value = 0;
    }
}

function go(nextIndex) {
    if (busy) {
        return;
    }

    motion.value = !reducedMotion();
    index.value = nextIndex;

    if (!motion.value) {
        finishTransition();

        return;
    }

    busy = true;
    window.clearTimeout(transitionTimer);
    transitionTimer = window.setTimeout(finishTransition, 650);
}

function showNext() {
    go(index.value + 1);
    schedule();
}

function showPrevious() {
    if (busy) {
        schedule();

        return;
    }

    if (index.value === 0) {
        motion.value = false;
        index.value = count;
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                go(count - 1);
            });
        });
    } else {
        go(index.value - 1);
    }

    schedule();
}

function showSlide(target) {
    if (target === activeDot.value && index.value !== count) {
        schedule();

        return;
    }

    go(target);
    schedule();
}

function play() {
    keyboardHold.value = false;
    playing.value = true;
    schedule();
}

function pause() {
    playing.value = false;
    clearTimer();
}

function onEnter() {
    hovering.value = true;
    clearTimer();
}

function onLeave() {
    hovering.value = false;
    schedule();
}

function onFocusIn() {
    if (lastPointer === 'key') {
        keyboardHold.value = true;
        clearTimer();
    }
}

function onVisibility() {
    if (document.hidden) {
        clearTimer();

        return;
    }

    schedule();
}

function onKeyDown(event) {
    if (event.key === 'Tab' || event.key.startsWith('Arrow')) {
        lastPointer = 'key';
    }
}

function onPointerActivity() {
    lastPointer = 'mouse';
}

function onSwipeStart(event) {
    if (event.pointerType === 'mouse' && event.button !== 0) {
        return;
    }

    startX = event.clientX;
}

function cancelSwipe() {
    startX = null;
}

function onSwipeEnd(event) {
    if (startX === null) {
        return;
    }

    const delta = event.clientX - startX;
    startX = null;

    if (Math.abs(delta) < 48) {
        return;
    }

    if (delta < 0) {
        showNext();
    } else {
        showPrevious();
    }
}

let resizeObserver;

onMounted(() => {
    measure();
    if (viewportEl.value) {
        resizeObserver = new ResizeObserver(measure);
        resizeObserver.observe(viewportEl.value);
    }
    playing.value = !reducedMotion();
    slides.slice(1).forEach((slide) => {
        const image = new Image();
        image.src = slide.src;
    });
    window.addEventListener('keydown', onKeyDown, true);
    window.addEventListener('pointerdown', onPointerActivity, true);
    document.addEventListener('visibilitychange', onVisibility);
    schedule();
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    clearTimer();
    window.clearTimeout(transitionTimer);
    window.removeEventListener('keydown', onKeyDown, true);
    window.removeEventListener('pointerdown', onPointerActivity, true);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <section
        class="carousel"
        aria-roledescription="carousel"
        aria-label="Illustrative workflow visuals"
        @mouseenter="onEnter"
        @mouseleave="onLeave"
        @focusin="onFocusIn"
    >
        <div ref="viewportEl" class="viewport" @pointerdown="onSwipeStart" @pointerup="onSwipeEnd" @pointercancel="cancelSwipe">
            <div class="track" :style="trackStyle">
                <div
                    v-for="(slide, frameIndex) in frames"
                    :key="frameIndex"
                    class="slide"
                    :style="frameWidth ? { width: `${frameWidth}px` } : undefined"
                    :aria-hidden="frameIndex === count || frameIndex !== index ? 'true' : 'false'"
                    :inert="frameIndex === count || frameIndex !== index"
                >
                    <img
                        :src="slide.src"
                        :alt="slide.alt"
                        :width="slide.width"
                        :height="slide.height"
                        :fetchpriority="frameIndex === 0 ? 'high' : 'low'"
                        decoding="async"
                    >
                </div>
            </div>
            <button type="button" class="nav prev" aria-label="Previous visual" @click="showPrevious">
                <span aria-hidden="true">‹</span>
            </button>
            <button type="button" class="nav next" aria-label="Next visual" @click="showNext">
                <span aria-hidden="true">›</span>
            </button>
        </div>

        <div class="toolbar">
            <div class="dots" role="group" aria-label="Choose a visual">
                <button
                    v-for="(slide, dotIndex) in slides"
                    :key="slide.src"
                    type="button"
                    class="dot"
                    :aria-label="`Show visual ${dotIndex + 1} of ${count}`"
                    :aria-current="activeDot === dotIndex ? 'true' : undefined"
                    @click="showSlide(dotIndex)"
                ></button>
            </div>
            <button
                type="button"
                class="play"
                :aria-pressed="playing ? 'true' : 'false'"
                :aria-label="playing ? 'Pause automatic playback' : 'Play automatic playback'"
                @click="playing ? pause() : play()"
            >
                {{ playing ? 'Pause' : 'Play' }}
            </button>
        </div>
        <p class="caption">Illustrative workflow visuals.</p>
    </section>
</template>

<style scoped>
.carousel {
    width: min(100%, 22rem);
    max-width: 100%;
    margin-inline: auto;
    font-family: inherit;
}

.viewport {
    position: relative;
    aspect-ratio: 2 / 3;
    max-height: min(36rem, 62vh);
    overflow: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 1.5rem;
    background: #eff6ff;
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
    touch-action: pan-y;
}

.track {
    display: flex;
    height: 100%;
}

.slide {
    flex: 0 0 auto;
    width: 100%;
    min-width: 0;
    height: 100%;
}

.slide img {
    display: block;
    width: 100%;
    height: 100%;
    max-width: 100%;
    object-fit: contain;
    object-position: center;
}

.nav {
    position: absolute;
    top: 50%;
    display: grid;
    width: 2.5rem;
    height: 2.5rem;
    place-items: center;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.92);
    color: #0f172a;
    font-size: 1.6rem;
    line-height: 1;
    transform: translateY(-50%);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}

.prev {
    left: 0.75rem;
}

.next {
    right: 0.75rem;
}

.nav:hover {
    background: #ffffff;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 0.9rem;
}

.dots {
    display: flex;
    gap: 0.45rem;
}

.dot {
    width: 0.7rem;
    height: 0.7rem;
    border: 0;
    border-radius: 999px;
    background: #cbd5e1;
}

.dot[aria-current='true'] {
    width: 1.6rem;
    background: #2563eb;
}

.play,
.nav,
.dot {
    cursor: pointer;
}

.play {
    min-height: 2.25rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #ffffff;
    padding: 0.35rem 0.85rem;
    color: #123b70;
    font: inherit;
    font-size: 0.8125rem;
    font-weight: 600;
}

.caption {
    margin: 0.7rem 0 0;
    color: #64748b;
    font-size: 0.8125rem;
    text-align: center;
}

@media (prefers-reduced-motion: reduce) {
    .track {
        transition: none !important;
    }
}
</style>
