<script setup lang="ts">
import { computed, ref } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import type { AttentionItem } from '@/lib/registration-v2';

/**
 * The "needs attention" list of the Version 2 Registration board: one row per patient, the helper line once at the
 * top, at most 5 rows and then "Show all N". Counted from the visits on screen only (the helper says so).
 */
const props = defineProps<{ items: AttentionItem[] }>();
defineEmits<{ view: [visitNumber: string] }>();

const LIMIT = 5;
const all = ref(false);
const shown = computed(() =>
    all.value ? props.items : props.items.slice(0, LIMIT),
);
</script>

<template>
    <div class="flex flex-col">
        <p class="px-4 pb-2 text-[13px] text-muted-foreground">
            Call these patients or tell them why they are waiting. From the
            visits on this screen only.
        </p>
        <ul class="flex flex-col">
            <li
                v-for="item in shown"
                :key="item.id"
                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2 sm:grid-cols-[minmax(0,1fr)_auto_auto]"
            >
                <p class="min-w-0 text-sm break-words">
                    <span class="font-medium"
                        >{{ item.label }} {{ item.patientName }}</span
                    >
                    <span v-if="item.waitText" class="text-muted-foreground">
                        · {{ item.waitText }}</span
                    >
                </p>
                <V2Chip
                    v-if="item.notReadyReason"
                    class="max-sm:col-span-2 max-sm:row-start-2 max-sm:justify-self-start"
                    tone="warning"
                    :label="`Not ready — ${item.notReadyReason}`"
                />
                <button
                    type="button"
                    class="inline-flex h-11 items-center text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:col-start-2 max-sm:row-start-1 lg:h-9"
                    :aria-label="`View ${item.label} ${item.patientName}`"
                    @click="$emit('view', item.visitNumber)"
                >
                    View
                </button>
            </li>
        </ul>
        <button
            v-if="!all && items.length > LIMIT"
            type="button"
            class="mx-4 mt-1 mb-2 inline-flex h-11 items-center self-start text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
            @click="all = true"
        >
            Show all {{ items.length }}
        </button>
    </div>
</template>
