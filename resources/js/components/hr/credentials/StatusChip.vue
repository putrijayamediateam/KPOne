<script setup lang="ts">
// HR-5a status chip (Daniel's early look, HR-PLAN 12.6): the time is in the words and the date beside them; Expired
// carries stronger text and a small alert icon so it never reads the same as Expiring, and is still never red.
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { StatusBadge } from '@/components/ui/status';
import type { CredentialStatus, RecordView } from './types';
import { statusLine, statusTone } from './types';

const props = defineProps<{
    status: CredentialStatus;
    record: RecordView | null;
    /** Short words for the register matrix ("21 days"); the full sentence goes to screen readers. */
    short?: boolean;
    /** For the screen-reader sentence, e.g. "APC". */
    name?: string;
}>();

const line = computed(() => statusLine(props.status, props.record));
const spoken = computed(() =>
    props.name
        ? `${props.name}, ${line.value.replace(' · ', ', ')}`
        : line.value,
);
</script>

<template>
    <span class="inline-flex items-center gap-1" data-test="credential-status">
        <TriangleAlert
            v-if="status.code === 'expired'"
            class="size-3.5 shrink-0 text-warning-strong"
            aria-hidden="true"
        />
        <StatusBadge
            :status="status.code"
            :label="short ? status.short : line"
            :tone="statusTone(status.code)"
            :class="status.code === 'expired' ? 'font-semibold' : undefined"
            :aria-hidden="short || name ? 'true' : undefined"
        />
        <span v-if="short || name" class="sr-only">{{ spoken }}</span>
    </span>
</template>
