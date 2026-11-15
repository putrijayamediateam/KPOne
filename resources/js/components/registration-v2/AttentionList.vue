<script setup lang="ts">
import { computed, ref } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import type { AttentionItem } from '@/lib/registration-v2';

/**
 * The "needs attention" list of the Version 2 Registration board: one row per patient, the helper line once at the
 * top, at most 5 rows and then "Show all N". Counted from the visits on screen only (the helper says so). A row always
 * stacks (name and wait text on line 1 with "Show in list" at the end, the chip on line 2), because the list sits in a narrow
 * rail: a name gets the full width of its line and wraps by word, never by letter. Every row is on the current page, so
 * "Show in list" always has a row to scroll to.
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
                class="flex flex-col gap-1 px-4 py-2"
            >
                <div
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3"
                >
                    <p class="min-w-0 text-sm break-words">
                        <span class="font-medium"
                            >{{ item.label }} {{ item.patientName }}</span
                        >
                        <span
                            v-if="item.waitText"
                            class="text-muted-foreground"
                        >
                            · {{ item.waitText }}</span
                        >
                    </p>
                    <button
                        type="button"
                        class="inline-flex h-11 items-center text-sm font-medium whitespace-nowrap text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                        :aria-label="`Show ${item.label} ${item.patientName} in the list`"
                        @click="$emit('view', item.visitNumber)"
                    >
                        Show in list
                    </button>
                </div>
                <p
                    v-if="item.notReadyReason"
                    class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground"
                >
                    <V2Chip tone="warning" label="Not ready" />
                    <span class="min-w-0 break-words">{{
                        item.notReadyReason
                    }}</span>
                </p>
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
