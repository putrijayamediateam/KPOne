<script setup lang="ts">
import { computed } from 'vue';
import type { Avatar, FeedAgent } from './office-layout';
import { LIGHT_COLOURS, LIGHT_LABELS } from './office-layout';

const props = defineProps<{
    agent: FeedAgent;
    avatar: Avatar;
    x: number;
    y: number;
    seated: boolean;
    walking: boolean;
    selected: boolean;
    bubble: string | null;
}>();

const emit = defineEmits<{ select: [code: string] }>();

const mood = computed(() => {
    if (!props.agent.isEnabled) {
        return 'off';
    }

    if (props.walking) {
        return 'walking';
    }

    if (props.agent.light === 'green') {
        return 'working';
    }

    if (props.agent.light === 'red') {
        return 'failed';
    }

    if (props.agent.light === 'yellow') {
        return 'waiting';
    }

    return 'idle';
});
const tagWidth = computed(() =>
    Math.max(64, props.agent.displayName.length * 6.8 + 30),
);
const bubbleWidth = computed(() =>
    props.bubble ? Math.min(210, props.bubble.length * 5.3 + 18) : 0,
);
const label = computed(
    () =>
        `${props.agent.displayName}, ${props.agent.jobTitle}. ${
            props.agent.isEnabled ? LIGHT_LABELS[props.agent.light] : 'Off'
        }.`,
);
const select = () => emit('select', props.agent.code);
const pants = '#334155';
</script>

<template>
    <g
        class="figure"
        :class="[`mood-${mood}`, { seated, selected }]"
        :style="{ transform: `translate(${x}px, ${y}px)` }"
        role="button"
        tabindex="0"
        :aria-label="label"
        :data-test="`office-agent-${agent.code}`"
        @click="select"
        @keydown.enter.prevent="select"
        @keydown.space.prevent="select"
    >
        <ellipse class="shadow" cx="0" cy="0" rx="13" ry="5.5" />
        <ellipse
            v-if="selected"
            class="ring"
            cx="0"
            cy="0"
            rx="19"
            ry="8"
            fill="none"
            stroke-width="2"
        />

        <g class="body-wrap" :transform="seated ? 'translate(0 9)' : undefined">
            <g class="bob">
                <!-- Chair back, seen behind the agent at a desk. -->
                <rect
                    v-if="seated"
                    class="chair"
                    x="-12"
                    y="-44"
                    width="24"
                    height="30"
                    rx="5"
                />
                <g v-if="!seated" class="legs">
                    <g class="leg leg-left">
                        <rect
                            x="-6.5"
                            y="-18"
                            width="5.5"
                            height="16"
                            rx="2"
                            :fill="pants"
                        />
                        <ellipse
                            cx="-4"
                            cy="-1.5"
                            rx="4"
                            ry="2"
                            fill="#1f2937"
                        />
                    </g>
                    <g class="leg leg-right">
                        <rect
                            x="1"
                            y="-18"
                            width="5.5"
                            height="16"
                            rx="2"
                            :fill="pants"
                        />
                        <ellipse
                            cx="4"
                            cy="-1.5"
                            rx="4"
                            ry="2"
                            fill="#1f2937"
                        />
                    </g>
                </g>

                <g class="arm arm-left">
                    <rect
                        x="-13.5"
                        y="-36"
                        width="5"
                        height="16"
                        rx="2.5"
                        :fill="avatar.shirt"
                    />
                    <circle cx="-11" cy="-19.5" r="2.6" :fill="avatar.skin" />
                </g>
                <g class="arm arm-right">
                    <rect
                        x="8.5"
                        y="-36"
                        width="5"
                        height="16"
                        rx="2.5"
                        :fill="avatar.shirt"
                    />
                    <circle cx="11" cy="-19.5" r="2.6" :fill="avatar.skin" />
                    <rect
                        v-if="mood === 'waiting' && !seated"
                        x="9"
                        y="-30"
                        width="9"
                        height="11"
                        rx="1"
                        fill="#ffffff"
                        stroke="#cbd5e1"
                        stroke-width="0.8"
                    />
                </g>

                <rect
                    class="torso"
                    x="-9.5"
                    y="-38"
                    width="19"
                    height="22"
                    rx="6"
                    :fill="avatar.shirt"
                />
                <path
                    v-if="avatar.extra === 'tie'"
                    d="M0 -37 L-2 -33 L0 -24 L2 -33 Z"
                    fill="#b91c1c"
                />

                <g class="head">
                    <path
                        v-if="avatar.style === 'hijab'"
                        d="M-11 -40 Q-12 -58 0 -58 Q12 -58 11 -40 Q8 -34 0 -33 Q-8 -34 -11 -40 Z"
                        :fill="avatar.hair"
                    />
                    <path
                        v-if="avatar.style === 'long'"
                        d="M-9.5 -47 Q-11 -30 -6 -31 L6 -31 Q11 -30 9.5 -47 Z"
                        :fill="avatar.hair"
                    />
                    <circle cx="0" cy="-46" r="8.5" :fill="avatar.skin" />
                    <path
                        v-if="avatar.style === 'short'"
                        d="M-8.6 -47 Q-9 -56.5 0 -56 Q9 -56.5 8.6 -47 Q6 -52 0 -52 Q-6 -52 -8.6 -47 Z"
                        :fill="avatar.hair"
                    />
                    <path
                        v-if="avatar.style === 'long'"
                        d="M-8.8 -46 Q-9.5 -56.5 0 -56 Q9.5 -56.5 8.8 -46 Q4 -52 -2 -51 Q-6 -50 -8.8 -46 Z"
                        :fill="avatar.hair"
                    />
                    <g v-if="avatar.style === 'bun'">
                        <circle cx="0" cy="-57" r="4.2" :fill="avatar.hair" />
                        <path
                            d="M-8.7 -46 Q-9 -56 0 -55.5 Q9 -56 8.7 -46 Q5 -51 0 -51 Q-5 -51 -8.7 -46 Z"
                            :fill="avatar.hair"
                        />
                    </g>
                    <g v-if="avatar.style === 'curly'" :fill="avatar.hair">
                        <circle cx="-6" cy="-52" r="4" />
                        <circle cx="0" cy="-55" r="4.5" />
                        <circle cx="6" cy="-52" r="4" />
                        <circle cx="-8" cy="-48" r="2.6" />
                        <circle cx="8" cy="-48" r="2.6" />
                    </g>
                    <path
                        v-if="avatar.style === 'hijab'"
                        d="M-8.2 -45 Q-8.5 -54.5 0 -54.5 Q8.5 -54.5 8.2 -45 Q6 -50 0 -50 Q-6 -50 -8.2 -45 Z"
                        :fill="avatar.hair"
                        opacity="0.85"
                    />

                    <g class="eyes" fill="#1f2937">
                        <ellipse cx="-3.1" cy="-45.5" rx="1.1" ry="1.4" />
                        <ellipse cx="3.1" cy="-45.5" rx="1.1" ry="1.4" />
                    </g>
                    <g
                        v-if="avatar.extra === 'glasses'"
                        fill="none"
                        stroke="#1f2937"
                        stroke-width="0.9"
                    >
                        <circle cx="-3.1" cy="-45.5" r="2.6" />
                        <circle cx="3.1" cy="-45.5" r="2.6" />
                        <path d="M-0.5 -45.5 L0.5 -45.5" />
                    </g>
                    <path
                        :d="
                            mood === 'failed'
                                ? 'M-2.5 -40.5 Q0 -42.3 2.5 -40.5'
                                : 'M-2.5 -41.8 Q0 -39.6 2.5 -41.8'
                        "
                        fill="none"
                        stroke="#7f1d1d"
                        stroke-width="1"
                        stroke-linecap="round"
                    />
                    <g v-if="avatar.extra === 'headset'">
                        <path
                            d="M-9 -46 Q-9.5 -56.5 0 -56.5 Q9.5 -56.5 9 -46"
                            fill="none"
                            stroke="#111827"
                            stroke-width="1.6"
                        />
                        <rect
                            x="-11"
                            y="-49"
                            width="3.5"
                            height="6"
                            rx="1.5"
                            fill="#111827"
                        />
                        <path
                            d="M-9 -44 Q-8 -40 -3 -40"
                            fill="none"
                            stroke="#111827"
                            stroke-width="1"
                        />
                    </g>
                </g>
            </g>
        </g>

        <!-- Name tag: always says "AI agent". -->
        <g
            class="tag"
            :transform="`translate(${-tagWidth / 2} ${seated ? -84 : -93})`"
        >
            <rect
                class="tag-bg"
                x="0"
                y="0"
                :width="tagWidth"
                height="27"
                rx="7"
            />
            <circle
                class="light"
                :class="{ pulse: agent.isEnabled && agent.light !== 'grey' }"
                cx="10"
                cy="10"
                r="4"
                :fill="agent.isEnabled ? LIGHT_COLOURS[agent.light] : '#64748b'"
            />
            <text class="tag-name" x="19" y="13.5">{{
                agent.displayName
            }}</text>
            <text class="tag-sub" x="19" y="23">
                {{ agent.isEnabled ? 'AI agent' : 'AI agent · Off' }}
            </text>
        </g>

        <!-- The agent's newest message. -->
        <g v-if="bubble" :transform="`translate(-24 ${seated ? -114 : -123})`">
            <g class="bubble">
                <rect
                    x="0"
                    y="0"
                    :width="bubbleWidth"
                    height="22"
                    rx="10"
                    class="bubble-bg"
                />
                <path :d="`M19 21.5 L24 28 L29 21.5 Z`" class="bubble-bg" />
                <text
                    :x="bubbleWidth / 2"
                    y="14.5"
                    text-anchor="middle"
                    class="bubble-text"
                >
                    {{ bubble }}
                </text>
            </g>
        </g>

        <g
            v-if="mood === 'failed'"
            class="alert"
            :transform="`translate(17 ${seated ? -44 : -52})`"
        >
            <circle r="7" fill="#ef4444" />
            <text
                y="3.6"
                text-anchor="middle"
                fill="#ffffff"
                font-size="10"
                font-weight="700"
                >!</text
            >
        </g>
    </g>
</template>

<style scoped>
.figure {
    cursor: pointer;
    outline: none;
    transition: transform 2.4s ease-in-out;
}
.figure:focus-visible .tag-bg {
    stroke: var(--office-focus);
    stroke-width: 2.5;
}
.shadow {
    fill: rgb(15 23 42 / 0.18);
}
.ring {
    stroke: var(--office-focus);
}
.chair {
    fill: var(--office-chair);
}
.tag-bg {
    fill: var(--office-tag);
    stroke: var(--office-tag-border);
    stroke-width: 1;
    filter: drop-shadow(0 1px 1.5px rgb(15 23 42 / 0.18));
}
.selected .tag-bg {
    stroke: var(--office-focus);
    stroke-width: 2;
}
.tag-name {
    fill: var(--office-text);
    font-size: 11px;
    font-weight: 700;
}
.tag-sub {
    fill: var(--office-muted);
    font-size: 8.5px;
    font-weight: 600;
    letter-spacing: 0.02em;
}
.bubble-bg {
    fill: var(--office-bubble);
    stroke: var(--office-tag-border);
    stroke-width: 0.8;
}
.bubble-text {
    fill: var(--office-text);
    font-size: 9px;
}
.bubble {
    animation: bubble-in 0.5s ease-out;
}
.mood-off {
    opacity: 0.45;
    filter: grayscale(1);
}

.bob,
.head,
.arm,
.leg,
.eyes,
.light {
    transform-box: fill-box;
}
.mood-idle .bob,
.mood-waiting .bob {
    animation: breathe 3.6s ease-in-out infinite;
    transform-origin: 50% 100%;
}
.mood-idle .head {
    animation: look 7s ease-in-out infinite;
    transform-origin: 50% 100%;
}
.mood-working .arm-left {
    animation: type 0.35s ease-in-out infinite alternate;
    transform-origin: 50% 0%;
}
.mood-working .arm-right {
    animation: type 0.35s ease-in-out infinite alternate-reverse;
    transform-origin: 50% 0%;
}
.mood-working .head {
    animation: nod 1.8s ease-in-out infinite;
    transform-origin: 50% 100%;
}
.mood-walking .bob {
    animation: step-bob 0.4s ease-in-out infinite;
}
.mood-walking .leg-left {
    animation: swing 0.4s ease-in-out infinite alternate;
    transform-origin: 50% 0%;
}
.mood-walking .leg-right {
    animation: swing 0.4s ease-in-out infinite alternate-reverse;
    transform-origin: 50% 0%;
}
.mood-walking .arm-left {
    animation: swing 0.4s ease-in-out infinite alternate-reverse;
    transform-origin: 50% 0%;
}
.mood-walking .arm-right {
    animation: swing 0.4s ease-in-out infinite alternate;
    transform-origin: 50% 0%;
}
.mood-waiting:not(.seated) .arm-right {
    transform: rotate(-35deg);
    transform-origin: 50% 0%;
}
.mood-failed .head {
    transform: rotate(8deg) translateY(1px);
    transform-origin: 50% 100%;
}
.eyes {
    animation: blink 5s infinite;
    transform-origin: 50% 50%;
}
.light.pulse {
    animation: pulse 1.6s ease-in-out infinite;
    transform-origin: 50% 50%;
}

@keyframes breathe {
    0%,
    100% {
        transform: scaleY(1);
    }
    50% {
        transform: scaleY(1.025);
    }
}
@keyframes look {
    0%,
    40%,
    100% {
        transform: rotate(0deg);
    }
    50%,
    60% {
        transform: rotate(-7deg);
    }
    70%,
    80% {
        transform: rotate(6deg);
    }
}
@keyframes type {
    from {
        transform: rotate(-8deg);
    }
    to {
        transform: rotate(14deg);
    }
}
@keyframes nod {
    0%,
    100% {
        transform: rotate(0deg);
    }
    50% {
        transform: rotate(3deg) translateY(0.8px);
    }
}
@keyframes step-bob {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-1.6px);
    }
}
@keyframes swing {
    from {
        transform: rotate(-22deg);
    }
    to {
        transform: rotate(22deg);
    }
}
@keyframes blink {
    0%,
    94%,
    100% {
        transform: scaleY(1);
    }
    96% {
        transform: scaleY(0.1);
    }
}
@keyframes pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.45;
    }
}
@keyframes bubble-in {
    from {
        opacity: 0;
        transform: translateY(4px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .figure {
        transition: none;
    }
    .figure *,
    .figure {
        animation: none !important;
    }
}
</style>
