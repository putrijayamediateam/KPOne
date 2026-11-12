<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Camera,
    CalendarClock,
    CalendarDays,
    Check,
    Clock,
    Minus,
    Receipt,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ClockPhotoNotice from '@/components/spaces/ClockPhotoNotice.vue';
import { useClockShortcut } from '@/composables/useClockShortcut';

type Ring = {
    state: 'in' | 'out' | 'done';
    canPress: boolean;
    refusal: string | null;
    needsAttendancePage: boolean;
    serverNow: string;
    timezone: string;
    clockedInAt: string | null;
    clockedInLabel: string | null;
    doneLabel: string | null;
    shiftLabel: string | null;
    rosteredMinutes: number | null;
    onTime: boolean | null;
    lateMinutes: number | null;
};

type Week = {
    days: Array<{ day: string; date: string; result: string; word: string }>;
    summary: string;
};

type Tile = { title: string; text: string; href: string } | null;

const props = defineProps<{
    greeting: { text: string; date: string } | null;
    ring: Ring | null;
    week: Week | null;
    tiles: {
        leave: Tile;
        claims: Tile;
        roster: Tile;
        attendance: Tile;
    };
    serverNow: string | null;
    timezone: string | null;
}>();

// The browser only ticks a display. It starts from the server's own "now", so a wrong device clock cannot change it,
// and nothing the page records or shows as a fact comes from this clock.
const offset = props.serverNow
    ? new Date(props.serverNow).getTime() - Date.now()
    : 0;
const nowMs = ref(Date.now() + offset);
let timer: number | undefined;
onMounted(() => {
    timer = window.setInterval(() => {
        nowMs.value = Date.now() + offset;
    }, 1000);
});
onBeforeUnmount(() => window.clearInterval(timer));

const time = computed(() =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: props.timezone ?? undefined,
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    })
        .format(new Date(nowMs.value))
        .replace(/\s?([ap])m/i, (_m, x: string) => ` ${x.toUpperCase()}M`),
);

const workedMinutes = computed(() =>
    props.ring?.clockedInAt
        ? Math.max(
              0,
              Math.floor(
                  (nowMs.value - new Date(props.ring.clockedInAt).getTime()) /
                      60000,
              ),
          )
        : 0,
);
const workedText = computed(() => {
    const h = Math.floor(workedMinutes.value / 60);
    const m = workedMinutes.value % 60;

    return h > 0 ? `${h}h ${m}m` : `${m}m`;
});
const percent = computed(() => {
    const rostered = props.ring?.rosteredMinutes;

    return rostered && rostered > 0
        ? Math.min(100, Math.round((workedMinutes.value / rostered) * 100))
        : null;
});

const RADIUS = 92;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const dash = computed(
    () => `${((percent.value ?? 0) / 100) * CIRCUMFERENCE} ${CIRCUMFERENCE}`,
);

const status = computed(() => {
    const r = props.ring;

    if (!r) {
        return null;
    }

    if (r.state === 'in') {
        const at = (r.clockedInLabel ?? 'Clocked in').replace(' at ', ' ');
        const how =
            r.onTime === true
                ? ', on time'
                : r.lateMinutes
                  ? `, late ${r.lateMinutes} min`
                  : '';

        return {
            word: `${at}${how}`,
            line: null,
            tone: r.lateMinutes ? 'warn' : 'ok',
        };
    }

    if (r.state === 'done') {
        return {
            word: r.doneLabel ?? 'Done for today',
            line: null,
            tone: 'ok',
        };
    }

    return {
        word: 'Not clocked in',
        line: r.shiftLabel ? `Your shift ${r.shiftLabel}` : 'No shift set',
        tone: 'idle',
    };
});

// The ring never presses. Until HR's photo flow exists it opens the Attendance page (the press and its checks live there).
const { press } = useClockShortcut();
const notice = ref(false);
const tileIcons = {
    leave: CalendarDays,
    claims: Receipt,
    roster: CalendarClock,
    attendance: Clock,
} as const;
const tileList = computed(() =>
    (['leave', 'claims', 'roster', 'attendance'] as const).flatMap((key) =>
        props.tiles[key] ? [{ key, ...props.tiles[key]! }] : [],
    ),
);
</script>

<template>
    <div class="me-home">
        <div class="me-top">
            <p v-if="greeting" class="me-greet">{{ greeting.text }}</p>
            <p v-if="greeting" class="me-date">{{ greeting.date }}</p>
            <p class="me-time" data-test="me-time">{{ time }}</p>

            <template v-if="ring">
                <div class="me-ring" :class="`is-${ring.state}`">
                    <svg
                        class="me-ring-svg"
                        viewBox="0 0 192 192"
                        aria-hidden="true"
                    >
                        <circle
                            class="me-ring-track"
                            cx="96"
                            cy="96"
                            :r="RADIUS"
                        />
                        <circle
                            v-if="ring.state === 'in' && percent !== null"
                            class="me-ring-fill"
                            cx="96"
                            cy="96"
                            :r="RADIUS"
                            :stroke-dasharray="dash"
                        />
                    </svg>
                    <button
                        v-if="ring.state === 'out'"
                        type="button"
                        class="me-clock-button"
                        data-test="me-clock-in"
                        @click="press()"
                    >
                        <Camera class="me-clock-icon" aria-hidden="true" />
                        <span>Clock in</span>
                    </button>
                    <div v-else class="me-worked" data-test="me-worked">
                        <template v-if="ring.state === 'in'">
                            <strong>Worked {{ workedText }}</strong>
                            <span v-if="percent !== null"
                                >{{ percent }}% of your shift</span
                            >
                        </template>
                        <strong v-else>Done for today</strong>
                    </div>
                </div>
                <div
                    v-if="status"
                    class="me-status"
                    :class="`is-${status.tone}`"
                >
                    <span class="me-dot" aria-hidden="true" />
                    <span>{{ status.word }}</span>
                    <span v-if="status.line" class="me-status-line">{{
                        status.line
                    }}</span>
                </div>
                <p v-if="ring.refusal" class="me-refusal">{{ ring.refusal }}</p>
                <div v-if="ring.state === 'in'" class="me-actions">
                    <button
                        type="button"
                        class="me-clock-out"
                        data-test="me-clock-out"
                        @click="press()"
                    >
                        Clock out
                    </button>
                </div>
            </template>
        </div>

        <section
            v-if="week"
            class="me-week"
            aria-labelledby="me-week-title"
            data-test="me-week"
        >
            <div class="me-week-head">
                <h2 id="me-week-title">This week</h2>
                <span class="me-week-summary">{{ week.summary }}</span>
            </div>
            <ul class="me-week-days">
                <li
                    v-for="d in week.days"
                    :key="d.date"
                    :class="`is-${d.result}`"
                >
                    <span class="me-week-mark" aria-hidden="true">
                        <Check v-if="d.result === 'ontime'" />
                        <TriangleAlert
                            v-else-if="
                                d.result === 'late' || d.result === 'missed'
                            "
                        />
                        <Minus v-else />
                    </span>
                    <span class="me-week-day">{{ d.day }}</span>
                    <span class="me-week-word">{{ d.word }}</span>
                </li>
            </ul>
        </section>

        <ul v-if="tileList.length" class="me-tiles" data-test="me-tiles">
            <li v-for="t in tileList" :key="t.key">
                <Link :href="t.href" :class="`me-tile is-${t.key}`">
                    <span class="me-tile-icon" aria-hidden="true">
                        <component :is="tileIcons[t.key]" />
                    </span>
                    <span class="me-tile-body">
                        <strong>{{ t.title }}</strong>
                        <span>{{ t.text }}</span>
                    </span>
                </Link>
            </li>
        </ul>

        <p v-if="ring" class="me-photo-link">
            <button
                type="button"
                data-test="about-photos"
                @click="notice = true"
            >
                About clock-in photos
            </button>
        </p>
        <ClockPhotoNotice v-model:open="notice" mode="info" />
    </div>
</template>
