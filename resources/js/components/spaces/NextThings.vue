<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { StatusBadge } from '@/components/ui/status';
import { requestJson } from '@/lib/json-client';

// Clean Look role homes: the "Needs you" card (spec section 7). One list of the next things for this person, a live strip of counts and
// the doctor's own sales line, all read from GET /my-day/next, which reuses each board's own permission and branch checks. The first
// paint comes from the page's `next` prop; the open page then polls. Patients show as a queue number and a short name only.
export type NextThing = {
    kind: string;
    title: string;
    detail: string;
    minutes: number;
    tone: 'plain' | 'amber' | 'red';
    notReady: string | null;
    href: string;
};
export type NextBlock = {
    live: Record<string, number> | null;
    things: NextThing[];
    thingsTotal: number;
    sales: {
        month: string;
        branch: string;
        targetSen: number | null;
        achievedSen: number;
        balanceSen: number | null;
        percent: number | null;
        reached: boolean;
        daysLeft: number | null;
    } | null;
};

const props = defineProps<{ next: NextBlock }>();
const block = ref<NextBlock>(props.next);
let timer: ReturnType<typeof setInterval> | undefined;
let controller: AbortController | undefined;

const liveLabels: Record<string, string> = {
    waiting: 'Waiting',
    serving: 'Serving',
    toDispense: 'To dispense',
    awaitingPayment: 'To collect',
    myWaiting: 'My waiting',
};
const strip = computed(() =>
    Object.entries(block.value.live ?? {})
        .filter(([key]) => key in liveLabels)
        .map(([key, count]) => ({ key, label: liveLabels[key], count })),
);
const more = computed(() => block.value.thingsTotal - block.value.things.length);
const ringgit = (sen: number) =>
    `RM ${(sen / 100).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const read = async () => {
    if (document.visibilityState === 'hidden') {
        return;
    }

    controller?.abort();
    controller = new AbortController();

    try {
        block.value = await requestJson<NextBlock>('/my-day/next', {
            signal: controller.signal,
        });
    } catch {
        // Keep the last good list; the next poll tries again.
    }
};

onMounted(() => {
    timer = setInterval(() => void read(), 15_000);
});
onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }

    controller?.abort();
});
</script>

<template>
    <div v-if="strip.length || next.things.length || block.sales" class="contents">
        <section
            v-if="strip.length"
            class="space-card shadow-card"
            aria-labelledby="day-live"
            data-test="my-day-live"
        >
            <h2 id="day-live">Right now</h2>
            <ul class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                <li v-for="s in strip" :key="s.key" class="flex items-baseline gap-2">
                    <span class="text-2xl font-semibold tabular-nums">{{ s.count }}</span>
                    <span class="space-muted text-sm">{{ s.label }}</span>
                </li>
            </ul>
        </section>

        <section
            v-if="block.things.length || strip.length"
            class="space-card shadow-card"
            aria-labelledby="day-next"
            data-test="my-day-next"
        >
            <h2 id="day-next">Your next things</h2>
            <p v-if="!block.things.length" class="space-muted" data-test="next-empty">
                Nothing is waiting for you right now.
            </p>
            <ul v-else class="space-list">
                <li v-for="(t, i) in block.things" :key="`${t.kind}-${i}-${t.href}`">
                    <Link :href="t.href" class="space-row" data-test="next-row">
                        <span class="space-row-body">
                            <span class="space-row-text">
                                <span class="space-row-main">{{ t.title }}</span>
                                <span class="space-row-sub">{{ t.detail }}</span>
                            </span>
                        </span>
                        <StatusBadge
                            v-if="t.notReady"
                            status="not_ready"
                            :label="t.notReady"
                            tone="warning"
                        />
                        <StatusBadge
                            v-else-if="t.tone !== 'plain'"
                            :status="t.tone"
                            :label="t.tone === 'red' ? 'Overdue' : 'Waiting long'"
                            :tone="t.tone === 'red' ? 'danger' : 'warning'"
                        />
                        <ChevronRight class="space-chevron" aria-hidden="true" />
                    </Link>
                </li>
            </ul>
            <p v-if="more > 0" class="space-muted mt-2 text-sm" data-test="next-more">
                and {{ more }} more on the boards
            </p>
        </section>

        <section
            v-if="block.sales && block.sales.targetSen !== null"
            class="space-card shadow-card"
            aria-labelledby="day-sales"
            data-test="my-day-sales"
        >
            <h2 id="day-sales">My sales</h2>
            <p class="space-muted text-sm">{{ block.sales.branch }} · {{ block.sales.month }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">
                {{ ringgit(block.sales.achievedSen) }}
                <span class="space-muted text-sm font-normal">of {{ ringgit(block.sales.targetSen) }}</span>
            </p>
            <div
                class="mt-2 h-2 rounded-full bg-muted"
                role="progressbar"
                aria-label="Progress to my sales target"
                :aria-valuenow="Math.min(block.sales.percent ?? 0, 100)"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-2 rounded-full bg-primary"
                    :style="{ width: `${Math.min(block.sales.percent ?? 0, 100)}%` }"
                />
            </div>
            <p class="space-muted mt-2 text-sm">
                {{
                    block.sales.reached
                        ? 'Target reached.'
                        : `${ringgit(block.sales.balanceSen ?? 0)} to go${block.sales.daysLeft !== null ? `, ${block.sales.daysLeft} days left` : ''}.`
                }}
            </p>
        </section>
    </div>
</template>
