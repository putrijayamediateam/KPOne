<script setup lang="ts">
// Version 2 documents list (HR-4b): type, issue date and an expiry chip in words. A document opens only through its
// existing audited link, when pressed: opening is recorded before any byte is sent, nothing loads ahead and no
// thumbnail is shown. No original file name is kept or shown.
import V2Chip from '@/components/v2/V2Chip.vue';
import type { StaffDocument } from './types';
import { dateLabel } from './types';

defineProps<{
    documents: StaffDocument[];
    workerId: string;
    canOpen: boolean;
    canRemove?: boolean;
    emptyTitle: string;
    emptyDescription: string;
}>();
const emit = defineEmits<{ remove: [document: StaffDocument] }>();
const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
</script>

<template>
    <div
        v-if="!documents.length"
        class="rounded-[14px] border bg-card p-6 text-center"
    >
        <p class="font-medium">{{ emptyTitle }}</p>
        <p class="text-sm text-muted-foreground">{{ emptyDescription }}</p>
    </div>
    <ul v-else class="flex flex-col gap-3" aria-label="Documents">
        <li
            v-for="d in documents"
            :key="d.id"
            class="flex flex-wrap items-start justify-between gap-3 rounded-[14px] border bg-card p-4 text-sm"
            data-test="document"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ d.categoryLabel }}</span>
                    <V2Chip
                        v-if="d.expiry"
                        tone="warning"
                        :label="d.expiry"
                        data-test="expiry-chip"
                    />
                </div>
                <p class="text-muted-foreground">
                    <template v-if="d.issuedOn"
                        >Issued {{ dateLabel(d.issuedOn) }} ·
                    </template>
                    <template v-if="d.expiresOn"
                        >Expires {{ dateLabel(d.expiresOn) }} ·
                    </template>
                    {{ d.kind === 'pdf' ? 'PDF' : 'Photo' }}, {{ d.size }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Added by {{ d.uploadedBy }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a
                    v-if="canOpen"
                    :href="`/hr/records/${workerId}/documents/${d.id}`"
                    target="_blank"
                    rel="noopener"
                    :class="button"
                    data-test="open-document"
                    >Open {{ d.categoryLabel.toLowerCase() }}</a
                >
                <button
                    v-if="canRemove"
                    type="button"
                    :class="`${button} text-destructive`"
                    data-test="remove-document"
                    @click="emit('remove', d)"
                >
                    Remove
                </button>
            </div>
        </li>
    </ul>
    <p v-if="documents.length && canOpen" class="text-xs text-muted-foreground">
        Opening a document is recorded. A PDF downloads; a photo opens in a new
        tab.
    </p>
</template>
