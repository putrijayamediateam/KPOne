<script setup lang="ts">
defineProps<{
    title: string;
    unit: string;
    current: number;
    previous: number;
    money?: boolean;
}>();

const format = (value: number, money: boolean, unit: string) =>
    money
        ? new Intl.NumberFormat('en-MY', {
              style: 'currency',
              currency: 'MYR',
              maximumFractionDigits: 2,
          }).format(value / 100)
        : `${value}${unit}`;
</script>

<template>
    <section class="rounded-xl border border-border bg-card p-4 md:p-5">
        <h2 class="text-xs font-medium text-muted-foreground">{{ title }}</h2>
        <p class="mt-2 text-sm font-semibold text-foreground">
            <template v-if="current === previous"
                >Maintained vs yesterday</template
            >
            <template v-else>
                {{ current > previous ? 'Up' : 'Down' }}
                {{ format(Math.abs(current - previous), money ?? false, unit) }}
                vs yesterday
            </template>
        </p>
        <div class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-lg bg-muted/60 p-3">
                <p class="text-[11px] text-muted-foreground">Today</p>
                <p class="mt-1 text-base font-semibold">
                    {{ format(current, money ?? false, unit) }}
                </p>
            </div>
            <div class="rounded-lg bg-muted/60 p-3">
                <p class="text-[11px] text-muted-foreground">Yesterday</p>
                <p class="mt-1 text-base font-semibold">
                    {{ format(previous, money ?? false, unit) }}
                </p>
            </div>
        </div>
    </section>
</template>
