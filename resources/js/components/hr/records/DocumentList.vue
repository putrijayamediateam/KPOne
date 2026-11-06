<script setup lang="ts">
// HR-4b documents (HR-PLAN 11.3 rule 10; Daniel: type, issue date and an expiry chip in words). Opening a document is
// recorded before any byte is sent, as Show is. No original file name is kept or shown.
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
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
</script>

<template>
    <EmptyState
        v-if="!documents.length"
        :title="emptyTitle"
        :description="emptyDescription"
    />
    <ul v-else class="flex flex-col gap-3" aria-label="Documents">
        <li
            v-for="d in documents"
            :key="d.id"
            class="flex flex-wrap items-start justify-between gap-3 rounded-xl border bg-card p-4 text-sm"
            data-test="document"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ d.categoryLabel }}</span>
                    <StatusBadge
                        v-if="d.expiry"
                        status="expiry"
                        :label="d.expiry"
                        tone="warning"
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
                <Button
                    v-if="canOpen"
                    as="a"
                    :href="`/hr/records/${workerId}/documents/${d.id}`"
                    target="_blank"
                    rel="noopener"
                    variant="outline"
                    size="sm"
                    class="max-lg:min-h-11"
                    data-test="open-document"
                    >Open {{ d.categoryLabel.toLowerCase() }}</Button
                >
                <Button
                    v-if="canRemove"
                    variant="outline"
                    size="sm"
                    class="max-lg:min-h-11"
                    data-test="remove-document"
                    @click="emit('remove', d)"
                    >Remove</Button
                >
            </div>
        </li>
    </ul>
    <p v-if="documents.length && canOpen" class="text-xs text-muted-foreground">
        Opening a document is recorded. A PDF downloads; a photo opens in a new
        tab.
    </p>
</template>
