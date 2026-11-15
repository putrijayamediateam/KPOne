<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PanelAdjustmentClaim } from '@/types/billing';
import { myr } from '@/types/billing';

/**
 * PCA-2 (Daniel's board v10, section 2): a supplementary claim names its original, and the claim pack's recorded
 * versions are listed newest first, each viewable. Versions are never rewritten.
 */
const props = defineProps<{ claim: PanelAdjustmentClaim; publicId: string }>();
const viewUrl = (version: number) =>
    `/panel-claims/pack?${new URLSearchParams([
        ['claims[]', props.publicId],
        ['version', String(version)],
        ['from', props.publicId],
    ]).toString()}`;
</script>

<template>
    <section class="grid gap-2 text-sm" data-testid="panel-claim-versions">
        <p
            v-if="claim.parent"
            class="font-medium"
            data-testid="panel-claim-supplementary-label"
        >
            {{ claim.number }} · Supplementary to
            <Link :href="claim.parent.url" class="underline">{{
                claim.parent.number
            }}</Link
            ><template v-if="claim.reason"> · {{ claim.reason }}</template>
        </p>
        <div v-if="claim.versions.length > 1" class="grid gap-2">
            <h3 class="font-medium" data-testid="panel-claim-version-count">
                Claim pack Version {{ claim.versions[0].version }} of
                {{ claim.versions.length }}
            </h3>
            <ul class="grid gap-1 tabular-nums">
                <li
                    v-for="(version, index) in claim.versions"
                    :key="version.version"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span
                        >Version {{ version.version }} ·
                        {{
                            version.version === 1
                                ? 'when created'
                                : 'after the adjustment'
                        }}, Panel share
                        <span class="whitespace-nowrap">{{
                            myr(version.panelShareSen)
                        }}</span>
                        · {{ version.recordedAt }}</span
                    >
                    <Link
                        v-if="index > 0"
                        :href="viewUrl(version.version)"
                        class="inline-flex min-h-11 items-center underline"
                        >View version {{ version.version }}</Link
                    >
                </li>
            </ul>
            <p class="text-muted-foreground">
                Earlier versions are kept and can be viewed. They are never
                rewritten.
            </p>
        </div>
    </section>
</template>
