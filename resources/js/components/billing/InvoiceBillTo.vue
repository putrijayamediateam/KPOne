<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BillToRecipient from '@/components/billing/BillToRecipient.vue';
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
    BillToContext,
    BillToPage,
    BillToRecipientData,
    BillToRevision,
} from '@/types/billTo';
const props = defineProps<{ context: BillToContext | null | undefined }>();
const page = usePage();
const open = ref(false);
const mode = ref<'change' | 'history' | 'clear'>('history');
const current = ref<BillToRecipientData | null>(null);
const entries = ref<BillToRevision[]>([]);
const version = ref(0);
const nextCursor = ref<string | null>(null);
const ready = ref(false);
const loaded = ref(false);
const canManage = ref(false);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const stale = ref(false);
const contextBlocked = ref(false);
const key = ref(crypto.randomUUID());
const empty = (): BillToRecipientData => ({
    party_type: 'person',
    display_name: '',
    company_name: '',
    address_line_1: '',
    address_line_2: '',
    city: '',
    state: '',
    postcode: '',
    country_code: '',
});
const draft = ref(empty());
const blocked = computed(
    () =>
        contextBlocked.value ||
        !props.context ||
        props.context.branchId !== page.props.branchContext?.active?.id,
);
const title = computed(() =>
    mode.value === 'history'
        ? 'Bill to revision history'
        : mode.value === 'clear'
          ? 'Clear Bill to'
          : 'Change Bill to',
);
const fields: Array<{
    key: Exclude<keyof BillToRecipientData, 'party_type'>;
    label: string;
    max: number;
}> = [
    { key: 'display_name', label: 'Display name', max: 200 },
    { key: 'company_name', label: 'Company name (optional)', max: 200 },
    { key: 'address_line_1', label: 'Address line 1 (optional)', max: 200 },
    { key: 'address_line_2', label: 'Address line 2 (optional)', max: 200 },
    { key: 'city', label: 'City (optional)', max: 100 },
    { key: 'state', label: 'State (optional)', max: 100 },
    { key: 'postcode', label: 'Postcode (optional)', max: 20 },
    { key: 'country_code', label: 'Country code (optional)', max: 2 },
];
let generation = 0;
let controller: AbortController | null = null;
const cancelPending = () => {
    generation++;
    controller?.abort();
    controller = null;
    loading.value = false;
    busy.value = false;
};
const clearDialog = () => {
    cancelPending();
    draft.value = empty();
    entries.value = [];
    nextCursor.value = null;
    loaded.value = false;
    error.value = '';
    stale.value = false;
    key.value = crypto.randomUUID();
};
const clearAll = () => {
    clearDialog();
    current.value = null;
    version.value = 0;
    ready.value = false;
    canManage.value = false;
};
const load = async (summary = false, more = false) => {
    if (
        blocked.value ||
        !props.context ||
        loading.value ||
        busy.value ||
        (more && !nextCursor.value)
    ) {
        return;
    }

    const token = generation;
    loading.value = true;
    error.value = '';
    controller = new AbortController();
    const query = new URLSearchParams({
        expected_branch_id: String(props.context.branchId),
        ...(summary ? { summary: '1' } : {}),
    });

    if (more && nextCursor.value) {
        query.set('cursor', nextCursor.value);
    }

    try {
        const result = await requestJson<BillToPage>(
            `${props.context.url}?${query}`,
            { signal: controller.signal },
        );

        if (token !== generation || blocked.value) {
            return;
        }

        current.value = result.current;
        version.value = result.version;
        canManage.value = result.canManage;
        ready.value = true;

        if (!summary) {
            entries.value = more
                ? [
                      ...entries.value,
                      ...result.data.filter(
                          (row) => !entries.value.some((e) => e.id === row.id),
                      ),
                  ]
                : result.data;
            nextCursor.value = result.nextCursor;
            loaded.value = true;

            if (!more && mode.value === 'change') {
                draft.value = result.current ? { ...result.current } : empty();
            }

            if (!more) {
                key.value = crypto.randomUUID();
                stale.value = false;
            }

            if (!result.canManage && mode.value !== 'history') {
                mode.value = 'history';
                draft.value = empty();
            }
        }
    } catch (caught) {
        if (token !== generation) {
            return;
        }

        const response = caught as { status?: number };
        clearAll();

        if (response.status === 403 || response.status === 404) {
            contextBlocked.value = true;
            open.value = false;
        } else {
            error.value =
                'Bill to could not be loaded. Refresh to check access and try again.';
        }
    } finally {
        if (token === generation) {
            loading.value = false;
        }
    }
};
const show = (value: 'change' | 'history') => {
    clearDialog();
    mode.value = value;
    open.value = true;
    void load();
};
const prepareClear = () => {
    mode.value = 'clear';
    key.value = crypto.randomUUID();
};
const save = async () => {
    if (
        !props.context ||
        blocked.value ||
        !loaded.value ||
        !canManage.value ||
        busy.value ||
        loading.value ||
        stale.value ||
        mode.value === 'history'
    ) {
        return;
    }

    if (mode.value === 'change' && !draft.value.display_name.trim()) {
        error.value = 'Enter a display name.';

        return;
    }

    const token = generation;
    busy.value = true;
    error.value = '';
    controller = new AbortController();

    try {
        await requestJson(props.context.url, {
            method: 'POST',
            signal: controller.signal,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify({
                expected_branch_id: props.context.branchId,
                lock_version: version.value,
                idempotency_key: key.value,
                operation: mode.value === 'clear' ? 'clear' : 'set',
                ...(mode.value === 'change' ? { recipient: draft.value } : {}),
            }),
        });

        if (token !== generation || !open.value || blocked.value) {
            return;
        }

        draft.value = empty();
        key.value = crypto.randomUUID();
        mode.value = 'history';
    } catch (caught) {
        if (token !== generation) {
            return;
        }

        const response = caught as {
            status?: number;
            payload?: { errors?: Record<string, string[]> };
        };

        if (response.status === 403 || response.status === 404) {
            contextBlocked.value = true;
            open.value = false;
            clearAll();

            return;
        }

        const errors = response.payload?.errors ?? {};
        stale.value = !!errors.lock_version || !!errors.idempotency_key;
        error.value =
            Object.values(errors).flat()[0] ??
            'Bill to could not be saved. Retry the unchanged draft, or cancel and refresh to review.';
    } finally {
        if (token === generation) {
            busy.value = false;
        }
    }

    if (token === generation && mode.value === 'history' && !error.value) {
        await load();
    }
};
watch(
    open,
    (value) => {
        if (!value) {
            clearDialog();

            // Closing can abort the history reload after a confirmed write.
            // Re-read the summary so an accepted change cannot leave old addressing on screen.
            if (!blocked.value) {
                current.value = null;
                ready.value = false;
                void load(true);
            }
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
        clearAll();
        contextBlocked.value = false;
        void load(true);
    },
    { immediate: true, flush: 'sync' },
);
const stop = router.on('start', () => {
    contextBlocked.value = true;
    open.value = false;
    clearAll();
});
onBeforeUnmount(() => {
    stop();
    clearAll();
});
</script>
<template>
    <section
        v-if="context && !blocked"
        aria-label="Bill to"
        class="min-w-0 text-sm"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-medium text-muted-foreground">Bill to</h3>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="context.canManage && canManage"
                    type="button"
                    variant="outline"
                    class="h-7 px-2 text-xs"
                    :disabled="!ready || loading"
                    @click="show('change')"
                    >Change</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    class="h-7 px-2 text-xs"
                    :disabled="loading"
                    @click="show('history')"
                    >History</Button
                >
            </div>
        </div>
        <BillToRecipient
            v-if="ready && current"
            :recipient="current"
            class="mt-2"
        />
        <p v-else-if="ready" class="mt-2 text-xs text-muted-foreground">
            Uses the existing patient recipient presentation.
        </p>
        <p v-else role="status" class="mt-2 text-xs text-muted-foreground">
            {{ error || 'Loading Bill to…' }}
        </p>
        <Dialog v-model:open="open">
            <DialogContent
                class="max-h-[90dvh] w-[calc(100%-2rem)] max-w-[640px] overflow-y-auto"
                data-testid="bill-to-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription
                        >Invoice {{ context.invoiceNumber }}. Document
                        addressing only; this does not change payment or Panel
                        responsibility. Invoice reprints show the current Bill
                        to, not proof of an earlier issue.</DialogDescription
                    >
                </DialogHeader>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded border border-destructive/40 p-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <p v-if="loading && !loaded" role="status">Loading Bill to…</p>
                <template v-if="loaded && mode === 'history'">
                    <p
                        v-if="!entries.length"
                        role="status"
                        class="rounded border p-4 text-sm text-muted-foreground"
                    >
                        No Bill to revisions yet. The existing patient recipient
                        presentation is used.
                    </p>
                    <ol aria-label="Bill to revisions" class="grid gap-3">
                        <li
                            v-for="revision in entries"
                            :key="revision.id"
                            class="rounded-lg border p-3 text-sm"
                        >
                            <div
                                class="flex flex-wrap justify-between gap-1 text-xs text-muted-foreground"
                            >
                                <span>{{ revision.actor }}</span
                                ><time>{{ revision.occurredAt }}</time>
                            </div>
                            <p class="my-2 font-medium">
                                Bill to {{ revision.kind }}
                            </p>
                            <BillToRecipient
                                v-if="revision.recipient"
                                :recipient="revision.recipient"
                            />
                            <p v-else class="text-xs text-muted-foreground">
                                Restored the existing patient recipient
                                presentation.
                            </p>
                        </li>
                    </ol>
                    <Button
                        v-if="nextCursor"
                        type="button"
                        variant="outline"
                        :disabled="loading"
                        @click="load(false, true)"
                        >Load more</Button
                    >
                </template>
                <form
                    v-else-if="loaded && mode === 'change'"
                    id="bill-to-form"
                    class="grid gap-3 sm:grid-cols-2"
                    @submit.prevent="save"
                >
                    <div class="sm:col-span-2">
                        <label
                            for="bill-to-party"
                            class="mb-1 block text-sm font-medium"
                            >Party type</label
                        ><select
                            id="bill-to-party"
                            v-model="draft.party_type"
                            :disabled="busy"
                            class="w-full rounded border bg-background p-2 text-sm"
                        >
                            <option value="person">Person</option>
                            <option value="company">Company</option>
                            <option value="panel">Panel</option>
                        </select>
                    </div>
                    <div
                        v-for="field in fields"
                        :key="field.key"
                        :class="
                            field.key === 'display_name' ||
                            field.key.startsWith('address_line')
                                ? 'sm:col-span-2'
                                : ''
                        "
                    >
                        <label
                            :for="'bill-to-' + field.key"
                            class="mb-1 block text-sm font-medium"
                            >{{ field.label }}</label
                        >
                        <input
                            :id="'bill-to-' + field.key"
                            v-model="draft[field.key]"
                            :maxlength="field.max"
                            :required="field.key === 'display_name'"
                            :disabled="busy"
                            :placeholder="
                                field.key === 'country_code'
                                    ? 'e.g. MY'
                                    : undefined
                            "
                            :autocomplete="'off'"
                            class="w-full rounded border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground sm:col-span-2">
                        Do not enter phone, email, identity-document,
                        registration or tax numbers. Nothing is copied from the
                        patient or Panel.
                    </p>
                </form>
                <p v-else-if="loaded && mode === 'clear'" class="text-sm">
                    Clear the current Bill to and restore the existing patient
                    recipient presentation? This appends a cleared revision;
                    earlier addressing remains in history.
                </p>
                <DialogFooter class="flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="busy || loading"
                        @click="load()"
                        >Refresh</Button
                    >
                    <Button
                        v-if="
                            loaded && canManage && current && mode === 'change'
                        "
                        type="button"
                        variant="outline"
                        :disabled="busy || loading"
                        @click="prepareClear"
                        >Clear Bill to</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="open = false"
                        >Close</Button
                    >
                    <Button
                        v-if="loaded && canManage && mode !== 'history'"
                        :type="mode === 'change' ? 'submit' : 'button'"
                        :form="mode === 'change' ? 'bill-to-form' : undefined"
                        :disabled="busy || loading || stale"
                        @click="mode === 'clear' && save()"
                        >{{
                            busy
                                ? 'Saving…'
                                : mode === 'clear'
                                  ? 'Confirm clear'
                                  : 'Save Bill to'
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
