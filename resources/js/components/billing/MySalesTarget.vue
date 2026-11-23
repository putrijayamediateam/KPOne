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
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="my-sales-target-title" class="font-medium">
                My sales target, {{ month }}
            </h2>
            <span class="text-xs text-muted-foreground">{{
                target.branch
            }}</span>
        </div>
        <div
            class="mt-2 h-2 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="bar"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="`${target.percent ?? 0}% of the target reached`"
        >
            <div class="h-full bg-primary" :style="{ width: `${bar}%` }" />
        </div>
        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
            <div>
                <dt class="text-xs text-muted-foreground">Target</dt>
                <dd class="tabular-nums">{{ myr(target.targetSen ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Achieved</dt>
                <dd class="tabular-nums">
                    {{ myr(target.achievedSen) }} · {{ target.percent ?? 0 }}%
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Balance</dt>
                <dd class="tabular-nums" data-testid="my-sales-target-balance">
                    {{
                        target.reached
                            ? `Target reached, ${myr(target.overSen ?? 0)} over`
                            : myr(target.balanceSen ?? 0)
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Needed per day</dt>
                <dd class="tabular-nums">
                    {{
                        target.reached || target.neededPerDaySen === null
                            ? '—'
                            : `${myr(target.neededPerDaySen)} for ${target.daysLeft} ${target.daysLeft === 1 ? 'day' : 'days'}`
                    }}
                </dd>
            </div>
        </dl>
    </section>
</template>
