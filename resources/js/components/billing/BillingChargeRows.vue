<script setup lang="ts">
import { myr } from '@/types/billing';
import type { BillingPage } from '@/types/billing';

defineProps<{
    lines: NonNullable<BillingPage['invoice']>['lines'];
}>();
</script>

<template>
    <ul
        v-if="lines.length"
        class="divide-y"
        data-testid="billing-charge-rows"
        aria-label="Invoice charges"
    >
        <li
            v-for="(line, index) in lines"
            :key="index"
            class="flex items-start justify-between gap-4 px-4 py-4"
            data-testid="billing-charge-row"
        >
            <div class="min-w-0">
                <div class="font-semibold break-words">{{ line.name }}</div>
                <div class="mt-1 text-xs text-muted-foreground">
                    {{ line.quantity }} {{ line.unit }} ×
                    {{ myr(line.unitPriceSen) }}
                </div>
            </div>
            <span class="shrink-0 tabular-nums">{{ myr(line.totalSen) }}</span>
        </li>
    </ul>
    <p v-else class="px-4 py-6 text-xs text-muted-foreground">
        No itemized lines in this authorized view.
    </p>
</template>
