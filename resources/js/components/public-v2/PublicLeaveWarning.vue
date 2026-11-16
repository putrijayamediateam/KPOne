<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';

/**
 * Public intake form: the browser asks before the patient leaves the form with typed answers (browser Back, reload,
 * closing the tab). It is a template-level helper so the Classic page script stays untouched. While a submit is in
 * flight, and for a few seconds after, it stands down so a successful submit can move to the status page without a
 * question; a refused submit re-arms it. Nothing is stored.
 */
const props = defineProps<{ dirty: boolean; submitting: boolean }>();

const STAND_DOWN_MS = 4_000;
let standDownUntil = 0;
const onBeforeUnload = (event: BeforeUnloadEvent) => {
    if (!props.dirty || Date.now() < standDownUntil) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
};

watch(
    () => props.submitting,
    (value) => {
        if (value) {
            standDownUntil = Date.now() + 60_000;
        } else {
            standDownUntil = Date.now() + STAND_DOWN_MS;
        }
    },
);
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));
onBeforeUnmount(() =>
    window.removeEventListener('beforeunload', onBeforeUnload),
);
</script>

<template>
    <span class="hidden" data-test="leave-warning" aria-hidden="true"></span>
</template>
