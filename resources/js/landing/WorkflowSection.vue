<script setup>
const steps = [
    {
        title: 'Report',
        copy: 'Residents submit observations and supporting photographs.',
    },
    {
        title: 'Assess',
        copy: 'BDRRM personnel review reports and use AI-assisted classification to support initial assessment.',
    },
    {
        title: 'Verify',
        copy: 'RDANA assessors validate building information, locations, and damage findings.',
    },
    {
        title: 'Consolidate',
        copy: 'CDRRMO personnel review verified assessments and generate geographic summaries and consolidated reports.',
    },
];
</script>

<template>
    <section id="workflow" class="section" aria-labelledby="workflow-title">
        <div class="mapr-wrap">
            <div data-reveal>
                <p class="kicker">How it works</p>
                <h2 id="workflow-title">Four steps from a report to a consolidated assessment</h2>
            </div>
            <ol class="steps">
                <li v-for="(step, index) in steps" :key="step.title" data-reveal :style="{ '--reveal': index }">
                    <span>{{ index + 1 }}</span>
                    <div>
                        <h3>{{ step.title }}</h3>
                        <p>{{ step.copy }}</p>
                    </div>
                </li>
            </ol>
        </div>
    </section>
</template>

<style scoped>
.section {
    padding: 2rem 0 3rem;
}

.kicker {
    margin: 0 0 0.6rem;
    color: #2563eb;
    font-size: 0.85rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

h2 {
    margin: 0;
    max-width: 40rem;
    color: #0f172a;
    font-size: clamp(1.75rem, 3vw, 2.5rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.15;
}

.steps {
    display: grid;
    gap: 0.9rem;
    margin: 1.6rem 0 0;
    padding: 0;
    list-style: none;
}

li {
    display: flex;
    gap: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 1.15rem;
    background: #ffffff;
    padding: 1.1rem 1.15rem;
}

li span {
    display: grid;
    width: 2.2rem;
    height: 2.2rem;
    flex: none;
    place-items: center;
    border-radius: 999px;
    background: #2563eb;
    color: #ffffff;
    font-weight: 700;
}

h3 {
    margin: 0;
    color: #0f172a;
    font-size: 1.1rem;
}

p {
    margin: 0.35rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

@media (min-width: 900px) {
    .steps {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
