<script setup lang="ts">
// HR-6 receipts: photos as thumbnails on a neutral bordered surface, a PDF as a file tile (never a broken image).
// Opening one is recorded on the server before any byte is sent.
import { FileText } from '@lucide/vue';
import type { Receipt } from './types';

defineProps<{ receipts: Receipt[]; thumbnails?: boolean }>();
</script>

<template>
    <ul
        v-if="receipts.length"
        class="flex flex-wrap gap-2"
        data-test="claim-receipts"
    >
        <li v-for="r in receipts" :key="r.id">
            <a
                :href="r.url"
                target="_blank"
                rel="noopener"
                class="flex min-h-11 items-center gap-2 rounded-lg border bg-muted p-1.5 text-sm underline-offset-4 hover:underline"
                :aria-label="`Open ${r.label}`"
            >
                <img
                    v-if="thumbnails && r.kind !== 'pdf'"
                    :src="r.url"
                    :alt="r.label"
                    class="size-16 rounded object-cover"
                    loading="lazy"
                />
                <span
                    v-else
                    class="flex size-16 items-center justify-center rounded bg-card"
                    aria-hidden="true"
                    ><FileText class="size-6 text-muted-foreground"
                /></span>
                <span class="pr-1">{{ r.label }}</span>
            </a>
        </li>
    </ul>
    <p v-else class="text-sm text-muted-foreground">No receipt.</p>
</template>
