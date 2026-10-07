<script setup lang="ts">
// PRANK: presentation joke only. Never merge this branch. Reads and writes no data.
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ caseId: string }>();

const progress = ref(0);
const showEscape = ref(false);
const timers: number[] = [];

const toError = () => router.visit(`/prank/${props.caseId}/error`);

onMounted(() => {
    // Climb to 99% in about six seconds, then stall there.
    const climb = window.setInterval(() => {
        if (progress.value >= 99) {
            window.clearInterval(climb);

            return;
        }

        progress.value = Math.min(99, progress.value + 3);
    }, 180);
    timers.push(climb);
    // The bar stays at 99% for 20 seconds before the fake error.
    timers.push(window.setTimeout(toError, 26_000));
    timers.push(window.setTimeout(() => (showEscape.value = true), 10_000));
});

onBeforeUnmount(() => timers.forEach((id) => window.clearTimeout(id)));
</script>

<template>
    <Head title="KPOne Dispensary compromised" />
    <div class="pm">
        <div class="pm-ticker" aria-hidden="true">
            <span
                >KPOne EXPRESS DISPENSE HAS TAKEN OVER THIS DISPENSARY!!! THE
                PARACETAMOL SHELF IS NOW PROTECTED BY THE STOCK GOBLINS!!! DO
                NOT TOUCH THE DISPENSARY!!!</span
            >
        </div>

        <main class="pm-window">
            <h1 class="pm-title">KPOne Dispensary has been HACKED!!!</h1>
            <p class="pm-sub">
                A totally real cyber incident, brought to you by the Stock
                Goblins
            </p>

            <p class="pm-loading">
                Injecting Express Dispense into KPOne... {{ progress }}%
            </p>
            <div
                class="pm-bar"
                role="progressbar"
                :aria-valuenow="progress"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div class="pm-bar-fill" :style="{ width: `${progress}%` }" />
            </div>
            <p v-if="progress >= 99" class="pm-stuck">
                Bypassing the pharmacist firewall... (this is normal)
            </p>

            <button
                type="button"
                class="pm-banner"
                data-testid="prank-speed-up"
                @click="toError"
            >
                CLICK HERE TO UNLOCK YOUR MEDICINE FASTER!!!
            </button>
            <p class="pm-fine">
                Best experienced while pretending to stay calm.
            </p>
        </main>

        <Link
            v-if="showEscape"
            :href="`/dispensary/${caseId}`"
            class="pm-escape"
            data-testid="prank-escape"
            >Back to real dispensary</Link
        >
    </div>
</template>

<style scoped>
.pm {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    overflow: auto;
    font-family: 'Comic Sans MS', 'Comic Sans', 'Chalkboard SE', cursive;
    color: #fff;
    background-color: #5b2a86;
    background-image:
        linear-gradient(45deg, #7a3fb0 25%, transparent 25%),
        linear-gradient(-45deg, #7a3fb0 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #7a3fb0 75%),
        linear-gradient(-45deg, transparent 75%, #7a3fb0 75%);
    background-size: 40px 40px;
    background-position:
        0 0,
        0 20px,
        20px -20px,
        -20px 0;
}
.pm-ticker {
    width: 100%;
    overflow: hidden;
    white-space: nowrap;
    background: #ffeb3b;
    color: #d500f9;
    font-weight: 700;
    font-size: 1.1rem;
    padding: 0.4rem 0;
}
.pm-ticker span {
    display: inline-block;
    padding-left: 100%;
    animation: pm-scroll 18s linear infinite;
}
@keyframes pm-scroll {
    to {
        transform: translateX(-100%);
    }
}
.pm-window {
    margin: 3rem 1rem;
    width: min(46rem, calc(100% - 2rem));
    padding: 2rem;
    text-align: center;
    background: #c0c0c0;
    color: #111;
    border: 4px outset #fff;
    box-shadow: 8px 8px 0 #2a0f45;
}
.pm-title {
    font-size: clamp(1.6rem, 4vw, 2.6rem);
    font-weight: 700;
    background: linear-gradient(
        90deg,
        #e53935,
        #fb8c00,
        #fdd835,
        #43a047,
        #1e88e5,
        #8e24aa
    );
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    text-shadow: none;
}
.pm-sub {
    margin-top: 0.25rem;
    font-style: italic;
}
.pm-loading {
    margin-top: 2rem;
    font-weight: 700;
}
.pm-bar {
    height: 2rem;
    margin-top: 0.5rem;
    border: 3px inset #fff;
    background: #fff;
}
.pm-bar-fill {
    height: 100%;
    background: repeating-linear-gradient(
        90deg,
        #000080 0 14px,
        #c0c0c0 14px 18px
    );
    transition: width 0.18s linear;
}
.pm-stuck {
    margin-top: 0.5rem;
    color: #b71c1c;
    animation: pm-blink 1s steps(2, start) infinite;
}
@keyframes pm-blink {
    to {
        visibility: hidden;
    }
}
.pm-banner {
    display: block;
    width: 100%;
    margin-top: 2rem;
    padding: 1rem;
    font: inherit;
    font-size: 1.3rem;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    background: linear-gradient(90deg, #ff1744, #ff9100);
    border: 4px outset #ffd180;
    animation: pm-shake 0.6s ease-in-out infinite;
}
@keyframes pm-shake {
    0%,
    100% {
        transform: rotate(-1deg);
    }
    50% {
        transform: rotate(1deg);
    }
}
.pm-fine {
    margin-top: 1.5rem;
    font-size: 0.8rem;
    color: #444;
}
.pm-escape {
    margin-bottom: 2rem;
    padding: 0.5rem 1rem;
    font-size: 0.9rem;
    color: #fff;
    text-decoration: underline;
}
@media (prefers-reduced-motion: reduce) {
    .pm-ticker span,
    .pm-banner,
    .pm-stuck {
        animation: none;
    }
}
</style>
