<script setup lang="ts">
// PRANK: presentation joke only. Never merge this branch. Reads and writes no data.
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ caseId: string }>();

const seconds = ref(10);
const shaking = ref(false);
const showEscape = ref(false);
const timers: number[] = [];

const toReveal = () => router.visit(`/prank/${props.caseId}/reveal`);

// Every button in the fake dialog only shakes it.
const nope = () => {
    shaking.value = true;
    window.setTimeout(() => (shaking.value = false), 400);
};

onMounted(() => {
    const tick = window.setInterval(() => {
        seconds.value -= 1;

        if (seconds.value <= 0) {
            window.clearInterval(tick);
            toReveal();
        }
    }, 1_000);
    timers.push(tick);
    timers.push(window.setTimeout(() => (showEscape.value = true), 5_000));
});

onBeforeUnmount(() => timers.forEach((id) => window.clearTimeout(id)));
</script>

<template>
    <Head title="KPOne Dispensary hacked" />
    <div class="de">
        <div
            class="de-dialog"
            :class="{ 'de-shake': shaking }"
            role="alertdialog"
            aria-labelledby="de-title"
        >
            <div class="de-bar">
                <span id="de-title">KPOne</span>
                <span class="de-x" aria-hidden="true">x</span>
            </div>
            <div class="de-body">
                <div class="de-icon" aria-hidden="true">!</div>
                <div>
                    <p class="de-head">
                        KPOne Dispensary has been hacked by the Stock Goblins.
                    </p>
                    <p>
                        Error code: 0xPARACETAMOL (Medicine Cabinet Compromised)
                    </p>
                    <p class="de-count" data-testid="prank-countdown">
                        All stock will be converted to jelly beans in
                        {{ seconds }} second{{ seconds === 1 ? '' : 's' }}...
                    </p>
                </div>
            </div>
            <div class="de-buttons">
                <button type="button" @click="nope">OK</button>
                <button type="button" @click="nope">Cancel</button>
                <button type="button" @click="nope">Call IT</button>
                <button type="button" @click="nope">Panic</button>
            </div>
        </div>

        <Link
            v-if="showEscape"
            :href="`/dispensary/${caseId}`"
            class="de-escape"
            data-testid="prank-escape"
            >Back to real dispensary</Link
        >
    </div>
</template>

<style scoped>
.de {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    padding: 1rem;
    overflow: auto;
    font-family: Tahoma, 'Segoe UI', Arial, sans-serif;
    background: #008080;
}
.de-dialog {
    width: min(30rem, 100%);
    background: #c0c0c0;
    border: 3px outset #fff;
    box-shadow: 6px 6px 0 #00403f;
    color: #000;
}
.de-bar {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0.5rem;
    font-weight: 700;
    color: #fff;
    background: linear-gradient(90deg, #000080, #1084d0);
}
.de-x {
    padding: 0 0.4rem;
    color: #000;
    background: #c0c0c0;
}
.de-body {
    display: flex;
    gap: 1rem;
    padding: 1.25rem;
}
.de-body p + p {
    margin-top: 0.5rem;
}
.de-icon {
    flex: none;
    display: grid;
    place-items: center;
    width: 2.75rem;
    height: 2.75rem;
    font-size: 1.75rem;
    font-weight: 700;
    color: #fff;
    background: #d50000;
    border-radius: 50%;
}
.de-head {
    font-weight: 700;
}
.de-count {
    color: #b71c1c;
    font-weight: 700;
}
.de-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: center;
    padding: 0 1rem 1.25rem;
}
.de-buttons button {
    min-width: 5rem;
    padding: 0.35rem 1rem;
    cursor: pointer;
    background: #c0c0c0;
    border: 2px outset #fff;
}
.de-buttons button:active {
    border-style: inset;
}
.de-shake {
    animation: de-shake 0.4s;
}
@keyframes de-shake {
    0%,
    100% {
        transform: translateX(0);
    }
    25% {
        transform: translateX(-10px);
    }
    75% {
        transform: translateX(10px);
    }
}
.de-escape {
    color: #fff;
    font-size: 0.9rem;
    text-decoration: underline;
}
@media (prefers-reduced-motion: reduce) {
    .de-shake {
        animation: none;
    }
}
</style>
