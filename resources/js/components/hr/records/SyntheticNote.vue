<script setup lang="ts">
// One shared muted line on every HR-4 page (Daniel): same words each time, never a coloured banner. Payroll pages pass
// the accountant line (HR-PLAN 14.6).
withDefaults(defineProps<{ text?: string }>(), {
    text: 'Synthetic data only until the PDPA review.',
});
</script>

<template>
    <p class="text-xs text-muted-foreground" data-test="synthetic-note">
        {{ text }}
    </p>
</template>
