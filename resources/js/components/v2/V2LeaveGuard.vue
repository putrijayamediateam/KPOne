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
 * Browser Back and Forward are held too (the page stays as it is and the question is asked); `beforeunload` covers reload and closing the tab. Put it once on the page: `<V2LeaveGuard :dirty="form.isDirty" />`.
 * The page decides `dirty`; after a save the redirect to the next page is part of the save and is not asked about.
 *
 * In-page actions: a same-path action (a tab, "Add another", opening a different record in the same page) is never caught by
 * the router rule, so the page says so: `<V2LeaveGuard ref="guard" :dirty="form.isDirty" @discard="form.reset()" />` and a
 * trigger does `guard.ask(() => (tab = 'x'))`. (1) While the question is open, a second click on any trigger does nothing,
 * and the focus stays on Keep editing; (2) Esc is Keep editing; (3) a clean form runs at once and asks nothing, and a
 * successful save followed by a tab change asks nothing (the page resets what `dirty` reads from after a save, for example
 * `form.defaults()`); (4) `ask` is for in-page actions that throw away unsaved work (tabs, "Add another", opening a
 * different record in the same page), never for Save; (5) after Discard changes the page resets before `run`, so the next
 * tab starts clean (`discard` is emitted first, then `run`).
 */
const props = defineProps<{ dirty: boolean }>();
const emit = defineEmits<{ discard: [] }>();

const open = ref(false);
let pending: string | null = null;
let pendingRun: (() => void) | null = null;

const { leave } = useLeaveGuard(
    () => props.dirty,
    (target) => {
        pending = target;
        open.value = true;
    },
);

// An in-page action that throws away unsaved work: runs at once when nothing has changed, else asks first.
const ask = (run: () => void) => {
    if (!props.dirty) {
        run();

        return;
    }

    // The question is already open: a second click does nothing.
    if (open.value) {
        return;
    }

    pendingRun = run;
    open.value = true;
};

defineExpose({ ask });

const keepEditing = () => {
    pending = null;
    pendingRun = null;
    open.value = false;
};

const discard = () => {
    const target = pending;
    const run = pendingRun;

    pending = null;
    pendingRun = null;
    open.value = false;
    // The page resets first, so whatever comes next starts clean.
    emit('discard');

    if (target) {
        leave(target);
    } else if (run) {
        run();
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
