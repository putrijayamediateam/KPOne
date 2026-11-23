<script setup lang="ts">
import { StatusBadge } from '@/components/ui/status';

/**
 * BIL-29 (owner decision 12): a foreign (passport) patient is marked on the bill. SST is not calculated until the
 * accountant confirms the rate and rules, so no amount is shown or charged. Shown only when the server says so.
 */
defineProps<{ foreign?: boolean }>();
</script>

<template>
    <div v-if="foreign" class="mt-2 space-y-1" data-testid="foreign-patient">
        <StatusBadge
            status="foreign"
            tone="neutral"
            label="Foreign patient (passport)"
        />
        <p class="text-xs text-muted-foreground">
            SST is not charged yet.
        </p>
    </div>
</template>
