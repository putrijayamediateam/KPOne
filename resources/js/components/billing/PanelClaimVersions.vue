<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatStatusLabel } from '@/lib/presentation';
import type { PanelAdjustmentClaim } from '@/types/billing';
import { myr } from '@/types/billing';

/**
 * PCA-2: a claim's kind and links ("Supplementary to claim …", its supplementary claims) and the recorded versions of
 * its pack totals. Versions are never rewritten; the newest is first.
 */
defineProps<{ claim: PanelAdjustmentClaim }>();
</script>

<template>
    <section class="grid gap-2 text-sm" data-testid="panel-claim-versions">
        <p
            v-if="claim.parent"
            class="font-medium"
            data-testid="panel-claim-supplementary-label"
        >
            Supplementary to
            <Link :href="claim.parent.url" class="underline"
                >claim {{ claim.parent.claimNo }}</Link
            >
        </p>
        <ul v-if="claim.supplementary.length" class="grid gap-1">
            <li v-for="child in claim.supplementary" :key="child.claimNo">
                Supplementary claim
                <Link :href="child.url" class="underline">{{
                    child.claimNo
                }}</Link>
                · {{ myr(child.amountSen) }} ·
                {{ formatStatusLabel(child.statusKey) }}
            </li>
        </ul>
        <details v-if="claim.versions.length > 1">
            <summary class="min-h-11 cursor-pointer content-center">
                Earlier versions ({{ claim.versions.length - 1 }})
            </summary>
            <ul class="mt-2 grid gap-1 text-muted-foreground tabular-nums">
                <li
                    v-for="version in claim.versions.slice(1)"
                    :key="version.version"
                >
                    Version {{ version.version }} · {{ version.recordedAt }} ·
                    claim {{ myr(version.claimAmountSen) }} · Panel share
                    {{ myr(version.panelShareSen) }} · invoice
                    {{ myr(version.invoiceTotalSen) }}
                </li>
            </ul>
        </details>
    </section>
</template>
