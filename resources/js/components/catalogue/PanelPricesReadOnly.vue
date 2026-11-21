<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

// Panel prices are changed by proposal and approval (owner decision 27), so the catalogue screens only show them.
defineProps<{
    defaultRm: string | null;
    overrides: { panelName: string; amountRm: string | null }[];
    canPropose: boolean;
}>();
</script>

<template>
    <div class="grid gap-2 text-sm" data-testid="panel-prices-read-only">
        <dl class="grid gap-1">
            <div class="flex justify-between gap-3">
                <dt>Default Panel price</dt>
                <dd class="tabular-nums">
                    {{ defaultRm ? `RM ${defaultRm}` : 'No price yet' }}
                </dd>
            </div>
            <div
                v-for="override in overrides"
                :key="override.panelName"
                class="flex justify-between gap-3"
            >
                <dt class="min-w-0 wrap-anywhere">{{ override.panelName }}</dt>
                <dd class="tabular-nums">
                    {{
                        override.amountRm
                            ? `RM ${override.amountRm}`
                            : 'No price yet'
                    }}
                </dd>
            </div>
        </dl>
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
