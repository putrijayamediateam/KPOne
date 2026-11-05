<script setup lang="ts">
import { computed } from 'vue';

// REG-5: tells staff that other records were merged into this one. Plain metadata text, not a status badge.
const props = defineProps<{ numbers: string[] }>();

// Up to three numbers are shown in full (visible to keyboard and touch users too); beyond that, "and N more".
const VISIBLE = 3;
const shown = computed(() => props.numbers.slice(0, VISIBLE));
const more = computed(() => Math.max(0, props.numbers.length - VISIBLE));
</script>

<template>
    <span
        v-if="numbers.length"
        class="block text-xs break-words text-muted-foreground"
        :title="more > 0 ? numbers.join(', ') : undefined"
        data-test="merged-from-note"
    >
        <span aria-hidden="true"
            >Merged from <span class="font-mono">{{ shown.join(', ') }}</span
            ><template v-if="more > 0"> and {{ more }} more</template></span
        >
        <span class="sr-only">Merged from {{ numbers.join(', ') }}</span>
    </span>
</template>
