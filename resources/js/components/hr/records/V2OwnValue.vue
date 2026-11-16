<script setup lang="ts">
// Version 2 of your own sensitive value on My HR details: hidden by default on a shared clinic computer, shown on Show
// with no approval and no request (it is your own value, already on this page), hidden again on Hide or when the tab
// is hidden. Nothing is copied. Same behaviour as Classic's OwnValue.
import { onBeforeUnmount, ref } from 'vue';

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
        <button
            type="button"
            class="inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
            :data-test="shown ? 'own-hide' : 'own-show'"
            @click="shown = !shown"
        >
            {{ shown ? `Hide ${field}` : `Show ${field}` }}
        </button>
    </div>
</template>
