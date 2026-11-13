<script setup lang="ts">
import { computed, ref } from 'vue';
import type { AttentionItem } from '@/lib/queue-v2';

/**
 * The "needs attention" list of the Version 2 Queue board: one row per patient with the reasons in words, the helper
 * line once at the top, at most 5 rows and then "Show all N". Counted from the entries on screen only.
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
            Long waits, flags and finished treatments. From the entries on this
            screen only.
        </p>
        <ul class="flex flex-col">
            <li
                v-for="item in shown"
                :key="item.id"
                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2"
            >
                <p class="min-w-0 text-sm break-words">
                    <span class="font-medium whitespace-normal"
                        ><span class="whitespace-nowrap">{{ item.label }}</span>
                        {{ item.patientName }}</span
                    >
                    <span
                        v-for="reason in item.reasons"
                        :key="reason"
                        class="block text-xs text-muted-foreground"
                        >{{ reason }}</span
                    >
                </p>
                <button
                    type="button"
                    class="inline-flex h-11 items-center text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    :aria-label="`View ${item.label} ${item.patientName}`"
                    @click="$emit('view', item.visitNumber)"
                >
                    View
                </button>
            </li>
        </ul>
        <button
            v-if="items.length > LIMIT"
            type="button"
            class="mx-4 mb-3 inline-flex h-11 items-center self-start text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
            @click="all = !all"
        >
            {{ all ? 'Show fewer' : `Show all ${items.length}` }}
        </button>
    </div>
</template>
