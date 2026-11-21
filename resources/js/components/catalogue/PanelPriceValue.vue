<script setup lang="ts">
import type { PanelPriceLine } from '@/components/catalogue/PanelPricesReadOnly.vue';

// The default Panel price as plain text, with a later price as a muted second line.
defineProps<{ line: PanelPriceLine | undefined }>();
</script>

<template>
    <div class="grid gap-0.5 text-sm tabular-nums">
        <span>{{
            line?.amountRm ? `RM ${line.amountRm}` : 'No price yet'
        }}</span>
        <span v-if="line?.scheduled" class="text-xs text-muted-foreground"
            >From {{ line.scheduled.label }}: RM
            {{ line.scheduled.amountRm }}</span
        >
    </div>
</template>
