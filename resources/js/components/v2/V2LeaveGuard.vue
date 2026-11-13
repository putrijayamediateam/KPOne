<script setup lang="ts">
import { ref } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import V2Dialog from './V2Dialog.vue';

/**
 * Version 2 leave guard: the shared "unsaved work" question for a form page. While `dirty` is true, an in-app link or
 * button that would take the person to ANOTHER page (for example Cancel, a crumb, the menu) first asks "Discard your
 * changes?" with "Keep editing" and "Discard changes" (the same words as V2Dialog's own guard), and only leaves on
 * "Discard changes". Closing the browser tab or reloading shows the browser's own warning while `dirty`.
 *
 * One mechanism: this and V2Dialog's open-sheet guard both use `useLeaveGuard` (rule in `lib/v2-leave-rule.ts`). Only GET
 * visits to a different path are held. The form's own save (a PATCH or POST), a visit to the same path (a reload, a partial
 * reload, a polling refresh) and everything when `dirty` is false are never held; the listeners go when the page unmounts.
 * Browser Back stays a known limit (no history trap); `beforeunload` covers reload and closing the tab. Put it once on the page: `<V2LeaveGuard :dirty="form.isDirty" />`.
 * The page decides `dirty`; after a save the redirect to the next page is part of the save and is not asked about.
 */
const props = defineProps<{ dirty: boolean }>();

const open = ref(false);
let pending: string | null = null;

const { leave } = useLeaveGuard(
    () => props.dirty,
    (target) => {
        pending = target;
        open.value = true;
    },
);

const keepEditing = () => {
    pending = null;
    open.value = false;
};

const discard = () => {
    const target = pending;

    pending = null;
    open.value = false;

    if (target) {
        leave(target);
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
