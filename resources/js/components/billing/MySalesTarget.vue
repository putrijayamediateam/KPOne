<script setup lang="ts">
import { computed } from 'vue';
import { myr } from '@/types/billing';

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
            <h2 id="my-sales-target-title" class="min-w-0 font-medium">
                My sales target, {{ month }}
                <span class="font-normal tabular-nums"
                    >· {{ myr(target.achievedSen) }} of
                    {{ myr(target.targetSen ?? 0) }} ({{
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
            <details class="group" data-testid="my-sales-target-details">
                <summary
                    class="inline-flex min-h-11 cursor-pointer items-center text-xs font-medium text-muted-foreground hover:text-foreground lg:min-h-0"
                >
                    Details
                </summary>
                <dl class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1">
                    <div>
                        <dt class="text-xs text-muted-foreground">Balance</dt>
                        <dd
                            class="tabular-nums"
                            data-testid="my-sales-target-balance"
                        >
                            {{
                                target.reached
                                    ? `Target reached, ${myr(target.overSen ?? 0)} over`
                                    : myr(target.balanceSen ?? 0)
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
                                target.reached ||
                                target.neededPerDaySen === null
                                    ? '—'
                                    : `${myr(target.neededPerDaySen)} a day`
                            }}
                        </dd>
                    </div>
                </dl>
            </details>
        </div>
    </section>
</template>
