<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import { myrNoBreak } from '@/types/billing';

// BIL-30 (owner P7, answer 2): the signed-in doctor's own monthly sales target. The server sends only this doctor's
// line; there is no branch total and no other doctor here.

const props = defineProps<{
    target: {
        month: string;
        branch: string;
        targetSen: number | null;
        achievedSen: number;
        balanceSen: number | null;
        overSen: number | null;
        neededPerDaySen: number | null;
        daysLeft: number | null;
        percent: number | null;
        reached: boolean;
    };
}>();

const month = computed(() =>
    new Date(`${props.target.month}-01T00:00:00`).toLocaleDateString('en-MY', {
        month: 'long',
        year: 'numeric',
    }),
);
const open = ref(false);
const bar = computed(() =>
    Math.min(100, Math.max(0, props.target.percent ?? 0)),
);
</script>

<template>
    <section
        class="rounded-lg border bg-card px-3 py-2 text-[13px]"
        aria-labelledby="my-sales-target-title"
        data-testid="my-sales-target"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h2
                id="my-sales-target-title"
                class="min-w-0 basis-full font-medium lg:basis-auto"
            >
                My sales target · {{ month }}
                <span
                    class="block font-normal tabular-nums lg:inline"
                    data-testid="my-sales-target-summary"
                    ><span class="max-lg:hidden">· </span
                    >{{ myrNoBreak(target.achievedSen) }} of
                    {{ myrNoBreak(target.targetSen ?? 0) }} ({{
                        target.percent ?? 0
                    }}%)</span
                >
            </h2>
            <div
                class="h-1.5 min-w-24 flex-1 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="bar"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="`${target.percent ?? 0}% of the target reached`"
            >
                <div class="h-full bg-brand" :style="{ width: `${bar}%` }" />
            </div>
            <button
                type="button"
                class="inline-flex h-11 shrink-0 items-center gap-1 rounded-md border px-3 text-xs font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-7 lg:px-2"
                :aria-expanded="open ? 'true' : 'false'"
                aria-controls="my-sales-target-details"
                data-testid="my-sales-target-toggle"
                @click="open = !open"
            >
                Details
                <ChevronDown
                    class="size-3.5 transition-transform"
                    :class="open ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </button>
        </div>
        <dl
            v-if="open"
            id="my-sales-target-details"
            class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1"
            data-testid="my-sales-target-details"
        >
            <div>
                <dt class="text-xs text-muted-foreground">Balance</dt>
                <dd class="tabular-nums" data-testid="my-sales-target-balance">
                    {{
                        target.reached
                            ? `Target reached, ${myrNoBreak(target.overSen ?? 0)} over`
                            : myrNoBreak(target.balanceSen ?? 0)
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    Needed per day<template
                        v-if="!target.reached && target.daysLeft"
                    >
                        · {{ target.daysLeft }}
                        {{ target.daysLeft === 1 ? 'day' : 'days' }}
                        left</template
                    >
                </dt>
                <dd class="whitespace-nowrap tabular-nums">
                    {{
                        target.reached || target.neededPerDaySen === null
                            ? '—'
                            : `${myrNoBreak(target.neededPerDaySen)} a day`
                    }}
                </dd>
            </div>
        </dl>
    </section>
</template>
