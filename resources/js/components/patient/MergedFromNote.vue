<script setup lang="ts">
import { computed } from 'vue';

// REG-5: tells staff that other records were merged into this one. Plain metadata text, not a status badge.
const props = defineProps<{ numbers: string[]; lowercase?: boolean }>();

const more = computed(() => props.numbers.length - 1);
</script>

<template>
    <span
        v-if="numbers.length"
        class="block text-xs break-words text-muted-foreground"
        :title="numbers.length > 1 ? numbers.join(', ') : undefined"
        data-test="merged-from-note"
    >
        <span aria-hidden="true"
            >{{ lowercase ? 'merged' : 'Merged' }} from
            <span class="font-mono">{{ numbers[0] }}</span
            ><template v-if="more > 0"> and {{ more }} more</template></span
        >
        <span class="sr-only"
            >{{ lowercase ? 'merged' : 'Merged' }} from
            {{ numbers.join(', ') }}</span
        >
    </span>
</template>
