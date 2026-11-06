<script setup lang="ts">
// Your own sensitive value on My HR details (Daniel, HR-4a "OK with changes"): hidden by default on a shared clinic
// computer, shown on Show with no approval, hidden again on Hide or when the page is hidden. Nothing is copied.
import { onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    label: string;
    field: string;
    mask: string;
    value: string;
    last4: string;
}>();

const shown = ref(false);
const onVisibility = () => {
    if (document.visibilityState === 'hidden') {
        shown.value = false;
    }
};
document.addEventListener('visibilitychange', onVisibility);
onBeforeUnmount(() =>
    document.removeEventListener('visibilitychange', onVisibility),
);
const spoken = `${props.label}, ending ${props.last4.split('').join(' ')}, hidden`;
</script>

<template>
    <div
        class="flex min-w-0 flex-wrap items-center gap-2"
        data-test="own-value"
    >
        <template v-if="!shown">
            <span class="font-medium tabular-nums" aria-hidden="true">{{
                mask
            }}</span>
            <span class="sr-only">{{ spoken }}</span>
        </template>
        <span
            v-else
            class="min-w-0 font-medium break-words"
            aria-live="polite"
            data-test="own-shown"
            >{{ value }}</span
        >
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="max-lg:min-h-11"
            :data-test="shown ? 'own-hide' : 'own-show'"
            @click="shown = !shown"
        >
            {{ shown ? `Hide ${field}` : `Show ${field}` }}
        </Button>
    </div>
</template>
