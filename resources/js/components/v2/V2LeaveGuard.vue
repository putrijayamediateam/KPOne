<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import V2Dialog from './V2Dialog.vue';

/**
 * Version 2 leave guard: the shared "unsaved work" question for a form page. While `dirty` is true, an in-app link or
 * button that would take the person to ANOTHER page (for example Cancel, a crumb, the menu) first asks "Discard your
 * changes?" with "Keep editing" and "Discard changes" (the same words as V2Dialog's own guard), and only leaves on
 * "Discard changes". Closing the browser tab or reloading shows the browser's own warning while `dirty`.
 *
 * Only GET visits to a different path are held. The form's own save (a PATCH or POST), a visit to the same path, and
 * everything when `dirty` is false are never held. Put it once on the page: `<V2LeaveGuard :dirty="form.isDirty" />`.
 * The page decides `dirty`; after a save the redirect to the next page is part of the save and is not asked about.
 */
const props = defineProps<{ dirty: boolean }>();

const open = ref(false);
let pending: string | null = null;
let bypass = false;

const stop = router.on('before', (event) => {
    if (bypass || !props.dirty) {
        return;
    }

    const visit = event.detail.visit;

    if (visit.method !== 'get') {
        return;
    }

    const target = new URL(visit.url.toString(), window.location.origin);

    if (target.pathname === window.location.pathname) {
        return;
    }

    pending = `${target.pathname}${target.search}`;
    open.value = true;

    return false;
});

const warnOnUnload = (event: BeforeUnloadEvent) => {
    if (props.dirty && !bypass) {
        event.preventDefault();
        event.returnValue = '';
    }
};

window.addEventListener('beforeunload', warnOnUnload);

onBeforeUnmount(() => {
    stop();
    window.removeEventListener('beforeunload', warnOnUnload);
});

const keepEditing = () => {
    pending = null;
    open.value = false;
};

const discard = () => {
    const target = pending;

    pending = null;
    open.value = false;

    if (target) {
        bypass = true;
        router.visit(target, { onFinish: () => (bypass = false) });
    }
};
</script>

<template>
    <V2Dialog
        :open="open"
        title="Discard your changes?"
        description="If you leave now, what you entered here is lost."
        confirm-label="Discard changes"
        cancel-label="Keep editing"
        tone="danger"
        @update:open="(value: boolean) => !value && keepEditing()"
        @confirm="discard"
    />
</template>
