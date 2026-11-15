<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { requestJson } from '@/lib/json-client';
import type {
    InvoiceNote,
    InvoiceNotesContext,
    InvoiceNotesPage,
} from '@/types/invoiceNotes';

const props = defineProps<{
    context: InvoiceNotesContext | null | undefined;
    drawer?: boolean;
}>();
const emit = defineEmits<{ saved: [] }>();
const page = usePage();
const open = ref(false);
const mode = ref<'history' | 'added' | 'corrected' | 'retracted'>('history');
const entries = ref<InvoiceNote[]>([]);
const version = ref(0);
const nextCursor = ref<string | null>(null);
const loaded = ref(false);
const canAdd = ref(false);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const stale = ref(false);
const body = ref('');
const target = ref('');
const reason = ref('entered_in_error');
const key = ref(crypto.randomUUID());
const contextBlocked = ref(false);
const blocked = computed(
    () =>
        contextBlocked.value ||
        !props.context ||
        props.context.branchId !== page.props.branchContext?.active?.id,
);
let generation = 0;
let controller: AbortController | null = null;
const clear = () => {
    generation++;
    controller?.abort();
    controller = null;
    entries.value = [];
    body.value = '';
    target.value = '';
    nextCursor.value = null;
    error.value = '';
    loaded.value = false;
    loading.value = false;
    busy.value = false;
    stale.value = false;
    canAdd.value = false;
    version.value = 0;
    key.value = crypto.randomUUID();
};
const load = async (more = false) => {
    if (
        !open.value ||
        blocked.value ||
        !props.context ||
        loading.value ||
        busy.value ||
        (more && !nextCursor.value)
    ) {
        return;
    }

    const current = generation;
    const cursor = more ? nextCursor.value : null;
    loading.value = true;
    error.value = '';
    controller = new AbortController();
    const query = new URLSearchParams({
        expected_branch_id: String(props.context.branchId),
    });

    if (cursor) {
        query.set('cursor', cursor);
    }

    try {
        const result = await requestJson<InvoiceNotesPage>(
            `${props.context.url}?${query}`,
            { signal: controller.signal },
        );

        if (current !== generation || !open.value || blocked.value) {
            return;
        }

        entries.value = more
            ? [
                  ...entries.value,
                  ...result.data.filter(
                      (row) =>
                          !entries.value.some(
                              (existing) => existing.id === row.id,
                          ),
                  ),
              ]
            : result.data;
        version.value = result.version;
        nextCursor.value = result.nextCursor;
        canAdd.value = result.canAdd;
        loaded.value = true;
        stale.value = false;

        if (mode.value === 'added' && !result.canAdd) {
            mode.value = 'history';
        }
    } catch {
        if (current === generation) {
            entries.value = [];
            body.value = '';
            target.value = '';
            loaded.value = false;
            canAdd.value = false;
            nextCursor.value = null;
            error.value =
                'Note history could not be loaded. Refresh to check access and try again.';
        }
    } finally {
        if (current === generation) {
            loading.value = false;
        }
    }
};
const show = (value: 'history' | 'added') => {
    clear();
    mode.value = value;
    open.value = true;
    void load();
};
const prepare = (value: 'corrected' | 'retracted', note: InvoiceNote) => {
    mode.value = value;
    target.value = note.id;
    body.value = '';
    reason.value = value === 'corrected' ? 'replaced' : 'entered_in_error';
    key.value = crypto.randomUUID();
    error.value = '';
};
const save = async () => {
    if (
        blocked.value ||
        !props.context ||
        !loaded.value ||
        busy.value ||
        loading.value ||
        stale.value ||
        mode.value === 'history'
    ) {
        return;
    }

    if (
        mode.value !== 'retracted' &&
        (!body.value.trim() || body.value.length > 500)
    ) {
        error.value = 'Enter a billing note of 1–500 characters.';

        return;
    }

    const current = generation;
    busy.value = true;
    error.value = '';
    controller = new AbortController();
    const csrf =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '';

    try {
        await requestJson(props.context.url, {
            method: 'POST',
            signal: controller.signal,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                expected_branch_id: props.context.branchId,
                lock_version: version.value,
                idempotency_key: key.value,
                operation: mode.value,
                ...(mode.value === 'retracted' ? {} : { body: body.value }),
                ...(mode.value === 'added'
                    ? {}
                    : { target: target.value, reason_code: reason.value }),
            }),
        });

        if (current !== generation || !open.value || blocked.value) {
            return;
        }

        body.value = '';
        target.value = '';
        key.value = crypto.randomUUID();
        mode.value = 'history';
        emit('saved');
    } catch (caught) {
        if (current !== generation) {
            return;
        }

        const response = caught as {
            status?: number;
            payload?: { errors?: Record<string, string[]> };
        };

        if (response.status === 403 || response.status === 404) {
            open.value = false;
            clear();

            return;
        }

        const errors = response.payload?.errors ?? {};
        stale.value = !!errors.lock_version || !!errors.target;
        error.value =
            Object.values(errors).flat()[0] ??
            'The note could not be saved. Retry without changing the draft, or cancel and refresh to review.';
    } finally {
        if (current === generation) {
            busy.value = false;
        }
    }

    if (current === generation && mode.value === 'history' && !error.value) {
        await load();
    }
};
watch(
    open,
    (value) => {
        if (!value) {
            clear();
        }
    },
    { flush: 'sync' },
);
watch(
    () => [
        props.context?.url,
        props.context?.branchId,
        page.props.branchContext?.active?.id,
    ],
    () => {
        open.value = false;
        clear();
        contextBlocked.value = false;
    },
);
const stop = router.on('start', () => {
    contextBlocked.value = true;
    open.value = false;
    clear();
});
onBeforeUnmount(() => {
    stop();
    clear();
});
const title = computed(
    () =>
        ({
            history: 'Invoice note history',
            added: 'Add invoice note',
            corrected: 'Correct invoice note',
            retracted: 'Retract invoice note',
        })[mode.value],
);
const reasons: Record<string, string> = {
    duplicate: 'Duplicate',
    entered_in_error: 'Entered in error',
    replaced: 'Replaced',
};
</script>

<template>
    <div
        v-if="context && !blocked"
        class="flex flex-wrap gap-2"
        data-testid="invoice-notes-actions"
    >
        <Button
            v-if="context.canAdd"
            type="button"
            variant="outline"
            class="h-[30px] px-2 text-xs"
            @click="show('added')"
            >Add note</Button
        >
        <Button
            type="button"
            variant="outline"
            class="h-[30px] px-2 text-xs"
            @click="show('history')"
            >Note history</Button
        >
    </div>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90dvh] w-[calc(100%-2rem)] max-w-[560px] overflow-y-auto"
            data-testid="invoice-notes-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription
                    >Invoice {{ context?.invoiceNumber }}. Internal billing
                    administration only. Do not enter diagnoses, medicines,
                    clinical notes or identity-document numbers. Notes are
                    excluded from prints, claims and reports.</DialogDescription
                >
            </DialogHeader>
            <p
                v-if="error"
                role="alert"
                class="rounded border border-destructive/40 p-3 text-sm text-destructive"
            >
                {{ error }}
            </p>
            <p v-if="loading && !loaded" role="status">Loading note history…</p>
            <template v-if="mode === 'history' && loaded">
                <p
                    v-if="!entries.length"
                    role="status"
                    class="rounded border p-4 text-sm text-muted-foreground"
                >
                    No invoice notes yet.
                </p>
                <ol class="grid gap-3" aria-label="Invoice note history">
                    <li
                        v-for="note in entries"
                        :key="note.id"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <div
                            class="flex flex-wrap justify-between gap-1 text-xs text-muted-foreground"
                        >
                            <span>{{ note.actor }}</span
                            ><time>{{ note.occurredAt }}</time>
                        </div>
                        <p class="mt-2 font-medium">
                            {{
                                note.kind === 'retracted'
                                    ? 'Note retracted'
                                    : note.kind === 'corrected'
                                      ? 'Note corrected'
                                      : 'Note added'
                            }}<span
                                v-if="note.resolved"
                                class="ml-2 text-xs font-normal text-muted-foreground"
                                >{{
                                    note.resolution === 'corrected'
                                        ? 'Replaced'
                                        : 'Retracted'
                                }}</span
                            >
                        </p>
                        <details v-if="note.resolved && note.body" class="mt-2">
                            <summary class="cursor-pointer text-xs">
                                View retained original
                            </summary>
                            <p class="mt-2 break-words whitespace-pre-wrap">
                                {{ note.body }}
                            </p>
                        </details>
                        <p
                            v-else-if="note.body"
                            class="mt-2 break-words whitespace-pre-wrap"
                        >
                            {{ note.body }}
                        </p>
                        <p
                            v-if="note.reasonCode"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{ reasons[note.reasonCode] }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Button
                                v-if="note.canCorrect"
                                type="button"
                                variant="outline"
                                class="h-7 text-xs"
                                @click="prepare('corrected', note)"
                                >Correct</Button
                            ><Button
                                v-if="note.canRetract"
                                type="button"
                                variant="outline"
                                class="h-7 text-xs"
                                @click="prepare('retracted', note)"
                                >Retract</Button
                            >
                        </div>
                    </li>
                </ol>
                <Button
                    v-if="nextCursor"
                    type="button"
                    variant="outline"
                    :disabled="loading"
                    @click="load(true)"
                    >Load more</Button
                >
            </template>
            <form
                v-else-if="loaded"
                id="invoice-note-form"
                @submit.prevent="save"
            >
                <template v-if="mode !== 'retracted'"
                    ><label
                        for="invoice-note-body"
                        class="mb-2 block text-sm font-medium"
                        >Billing note</label
                    ><textarea
                        id="invoice-note-body"
                        v-model="body"
                        rows="5"
                        maxlength="500"
                        :disabled="busy"
                        class="w-full rounded border bg-background p-3 text-sm"
                        aria-describedby="invoice-note-limit"
                    />
                    <p
                        id="invoice-note-limit"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ body.length }}/500 characters. Original content is
                        retained after correction or retraction.
                    </p></template
                >
                <template v-if="mode !== 'added'"
                    ><label
                        for="invoice-note-reason"
                        class="mt-3 mb-2 block text-sm"
                        >Reason</label
                    ><select
                        id="invoice-note-reason"
                        v-model="reason"
                        class="w-full rounded border bg-background p-2 text-sm"
                        :disabled="busy"
                    >
                        <option
                            v-for="(label, value) in reasons"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <p class="mt-2 text-xs text-muted-foreground">
                        This records a correction or retraction; the original is
                        never deleted.
                    </p></template
                >
            </form>
            <DialogFooter class="flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy || loading"
                    @click="load()"
                    >Refresh</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    @click="open = false"
                    >Close</Button
                >
                <Button
                    v-if="mode !== 'history' && loaded"
                    type="submit"
                    form="invoice-note-form"
                    :disabled="
                        busy ||
                        loading ||
                        stale ||
                        (mode !== 'retracted' && !canAdd)
                    "
                    >{{
                        busy
                            ? 'Saving…'
                            : mode === 'retracted'
                              ? 'Retract note'
                              : 'Save note'
                    }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
