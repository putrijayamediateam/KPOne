<script setup lang="ts">
defineProps<{
    title: string;
    summary: string;
    points: { hour: number; value: number }[];
    money?: boolean;
}>();

const formatValue = (value: number, money: boolean) =>
    money
        ? new Intl.NumberFormat('en-MY', {
              style: 'currency',
              currency: 'MYR',
              maximumFractionDigits: 2,
          }).format(value / 100)
        : `${value} min`;

const height = (value: number, max: number) =>
    value <= 0 || max <= 0 ? 2 : Math.max(4, (value / max) * 100);
</script>

<template>
    <section class="rounded-xl border border-border bg-card p-4 md:p-5">
        <div class="mb-4">
            <h2 class="text-sm font-semibold text-foreground">{{ title }}</h2>
            <p class="mt-1 text-xs text-muted-foreground">{{ summary }}</p>
        </div>
        <div
            class="grid h-44 grid-cols-24 items-end gap-1 border-b border-border px-1"
            role="img"
            :aria-label="`${title}, hourly chart for today`"
        >
            <div
                v-for="point in points"
                :key="point.hour"
                class="flex h-full min-w-0 items-end"
                :title="`${String(point.hour).padStart(2, '0')}:00 — ${formatValue(point.value, money ?? false)}`"
            >
                <span
                    class="w-full rounded-t-sm bg-primary/80"
                    :style="{
                        height: `${height(point.value, Math.max(...points.map((item) => item.value)))}%`,
                    }"
                />
            </div>
        </div>
        <div
            class="mt-2 grid grid-cols-5 text-[10px] text-muted-foreground"
            aria-hidden="true"
        >
            <span>12 AM</span><span class="text-center">6 AM</span
            ><span class="text-center">12 PM</span
            ><span class="text-center">6 PM</span
            ><span class="text-right">11 PM</span>
        </div>
    </section>
</template>
