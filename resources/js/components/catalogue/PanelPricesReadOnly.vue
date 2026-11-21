<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

export type PanelPriceLine = {
    name: string;
    isDefault: boolean;
    amountRm: string | null;
    scheduled: { amountRm: string; label: string } | null;
};

// Panel prices are changed by proposal and approval (owner decision 27), so the catalogue screens only show them: the
// price in effect now and, when there is one, the next price that starts later (as on Price check).
const props = defineProps<{
    lines: PanelPriceLine[];
    canPropose: boolean;
}>();
const others = computed(() => props.lines.filter((line) => !line.isDefault));
</script>

<template>
    <div class="grid gap-2 text-sm" data-testid="panel-prices-read-only">
        <ul v-if="others.length" class="grid gap-1">
            <li v-for="line in others" :key="line.name" class="grid gap-0.5">
                <div class="flex justify-between gap-3">
                    <span class="min-w-0 wrap-anywhere">{{ line.name }}</span>
                    <span class="tabular-nums">{{
                        line.amountRm ? `RM ${line.amountRm}` : 'No price yet'
                    }}</span>
                </div>
                <span
                    v-if="line.scheduled"
                    class="text-xs text-muted-foreground tabular-nums"
                    >From {{ line.scheduled.label }}: RM
                    {{ line.scheduled.amountRm }}</span
                >
            </li>
        </ul>
        <p v-else class="text-muted-foreground">No Panel-specific prices.</p>
        <p class="text-xs text-muted-foreground">
            Panel prices are changed by proposal.
            <Link
                v-if="canPropose"
                href="/panel-price-changes"
                class="font-medium text-brand-strong underline underline-offset-2"
                >Propose a change</Link
            >
        </p>
    </div>
</template>
