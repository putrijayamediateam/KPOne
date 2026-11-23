<script setup lang="ts">
import { computed } from 'vue';

/**
 * PNL-25: the label a frozen Panel claim pack prints under its letterhead: ORIGINAL, COPY or REVISED (version n), the
 * version and when it was issued, and any note (a later version exists). The live page, which is not an issued copy,
 * prints PREVIEW instead. Plain text and a plain box: it must read in black on white paper.
 */
export type PackIssued = {
    label: 'original' | 'revised' | 'copy';
    recordedLabel: 'original' | 'revised' | 'copy';
    version: number;
    issuedAt: string;
    printedAt: string;
    printedByYou: boolean;
    notes: string[];
};

const props = defineProps<{ issued: PackIssued | null }>();
const labelText = computed(() =>
    props.issued === null
        ? ''
        : props.issued.label === 'original'
          ? 'ORIGINAL'
          : props.issued.label === 'revised'
            ? `REVISED (version ${props.issued.version})`
            : 'COPY',
);
</script>

<template>
    <div v-if="issued" class="mb-4 text-center" data-testid="pack-issued-label">
        <p
            class="inline-block border-[1.5px] border-current px-3 py-0.5 text-xs font-bold tracking-wider uppercase"
        >
            {{ labelText }}
        </p>
        <p class="mt-1 text-[11px]">
            Version {{ issued.version }} · issued {{ issued.issuedAt }} (branch
            time)
        </p>
        <p
            v-for="note in issued.notes"
            :key="note"
            class="mx-auto mt-2 max-w-xl border px-3 py-1 text-xs"
        >
            {{ note }}
        </p>
    </div>
    <p
        v-else
        class="mb-4 border px-3 py-1 text-center text-xs font-bold"
        data-testid="pack-preview-label"
    >
        PREVIEW — not an issued copy. Print the pack from the claim page or the
        Panel Claims list to issue one.
    </p>
</template>
