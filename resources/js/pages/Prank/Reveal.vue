<script setup lang="ts">
// PRANK: presentation joke only. Never merge this branch. Reads and writes no data.
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ caseId: string }>();

const confetti = Array.from({ length: 40 }, (_, i) => ({
    left: `${(i * 37) % 100}%`,
    delay: `${(i % 10) * 0.35}s`,
    duration: `${3 + (i % 5) * 0.6}s`,
    color: ['#e91e63', '#ffc107', '#03a9f4', '#4caf50', '#9c27b0'][i % 5],
}));
</script>

<template>
    <Head title="It's a prank" />
    <div class="rv">
        <span
            v-for="(piece, index) in confetti"
            :key="index"
            class="rv-piece"
            aria-hidden="true"
            :style="{
                left: piece.left,
                background: piece.color,
                animationDelay: piece.delay,
                animationDuration: piece.duration,
            }"
        />
        <h1 class="rv-title" data-testid="prank-reveal">
            Its a PRANKKKKK!!!!!
        </h1>
        <p class="rv-sub">
            KPOne Dispensary was never hacked. Nothing was dispensed and no data
            was harmed.
        </p>
        <Link
            :href="`/dispensary/${caseId}`"
            class="rv-button"
            data-testid="prank-back"
            >Back to real dispensary</Link
        >
    </div>
</template>

<style scoped>
.rv {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    padding: 1rem;
    overflow: hidden;
    text-align: center;
    font-family: 'Comic Sans MS', 'Comic Sans', 'Chalkboard SE', cursive;
    color: #fff;
    background: linear-gradient(135deg, #e91e63, #ff9800);
}
.rv-title {
    font-size: clamp(2.2rem, 8vw, 5.5rem);
    font-weight: 700;
    line-height: 1.1;
    text-shadow: 4px 4px 0 rgb(0 0 0 / 0.25);
    text-wrap: balance;
}
.rv-sub {
    font-size: 1.15rem;
}
.rv-button {
    padding: 1rem 2.5rem;
    font-size: 1.5rem;
    font-weight: 700;
    color: #b0003a;
    text-decoration: none;
    background: #fff;
    border-radius: 0.75rem;
    box-shadow: 0 6px 0 rgb(0 0 0 / 0.25);
}
.rv-button:focus-visible {
    outline: 4px solid #fff;
    outline-offset: 4px;
}
.rv-piece {
    position: absolute;
    top: -2rem;
    width: 0.7rem;
    height: 1.2rem;
    opacity: 0.9;
    animation: rv-fall linear infinite;
}
@keyframes rv-fall {
    to {
        transform: translateY(110vh) rotate(540deg);
    }
}
@media (prefers-reduced-motion: reduce) {
    .rv-piece {
        animation: none;
        display: none;
    }
}
</style>
