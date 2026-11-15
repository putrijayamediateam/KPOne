<script setup lang="ts">
import { computed, ref } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import type { AttentionItem } from '@/lib/queue-v2';

/**
 * The "needs attention" list of the Version 2 Queue board (the Registration pattern): one row per patient, the
 * call to action once at the top, at most 5 rows and then "Show all N". Counted from the entries on screen only
 * (the helper says so). One stacked layout everywhere (this list lives only in the narrow rail and the phone sheet):
 * line 1 is the number, name and wait with "Show in list" at the end, line 2 the flags. "Show in list" scrolls to that
 * patient's row on this screen and focuses it; every item comes from the rows on screen, so the row is always there.
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
            entries on this screen only.
        </p>
        <ul class="flex flex-col">
            <li
                v-for="item in shown"
                :key="item.id"
                class="flex flex-col gap-1.5 px-4 py-2"
            >
                <div
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3"
                >
                    <p class="min-w-0 text-sm break-words">
                        <span class="font-medium"
                            ><span class="whitespace-nowrap">{{
                                item.label
                            }}</span>
                            {{ item.patientName }}</span
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
                <span
                    v-if="item.chips.length"
                    class="flex flex-col items-start gap-1.5"
                >
                    <span
                        v-for="chip in item.chips"
                        :key="chip.label"
                        class="flex flex-wrap items-center gap-x-2 gap-y-0.5"
                    >
                        <V2Chip :tone="chip.tone" :label="chip.label" />
                        <span
                            v-if="chip.detail"
                            class="text-[13px] break-words text-muted-foreground"
                            >{{ chip.detail }}</span
                        >
                    </span>
                </span>
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
