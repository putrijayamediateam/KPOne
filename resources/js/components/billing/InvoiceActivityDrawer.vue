<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CircleCheck, Clock3, CreditCard, UserRound } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InvoiceNotes from '@/components/billing/InvoiceNotes.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { requestJson } from '@/lib/json-client';
import { myr } from '@/types/billing';
import type {
    InvoiceActivityEntry,
    InvoiceActivityLink,
    InvoiceActivityPage,
} from '@/types/invoiceActivity';
import type { InvoiceNotesContext } from '@/types/invoiceNotes';

const props = defineProps<{
    context: InvoiceActivityLink | null | undefined;
    notesContext?: InvoiceNotesContext | null;
}>();
const page = usePage();
const open = ref(false);
const entries = ref<InvoiceActivityEntry[]>([]);
const nextCursor = ref<string | null>(null);
const loaded = ref(false);
const loading = ref(false);
const error = ref('');
const contextBlocked = ref(false);
const blocked = computed(
    () =>
        contextBlocked.value ||
        props.context?.branchId !== page.props.branchContext?.active?.id,
);
let controller: AbortController | null = null;
let generation = 0;
const cancel = () => {
    generation++;
    controller?.abort();
    loading.value = false;
};
const clear = () => {
    cancel();
    entries.value = [];
    nextCursor.value = null;
    loaded.value = false;
    error.value = '';
};
const load = async (more = false) => {
    if (
        !open.value ||
        blocked.value ||
        !props.context ||
        loading.value ||
        (more && !nextCursor.value)
    ) {
        return;
    }

    const cursor = more ? nextCursor.value : null;

    if (!more) {
        clear();
    }

    const current = generation;
    controller = new AbortController();
    loading.value = true;
    error.value = '';
    const query = new URLSearchParams({
        expected_branch_id: String(props.context.branchId),
    });

    if (cursor) {
        query.set('cursor', cursor);
    }

    try {
        const result = await requestJson<InvoiceActivityPage>(
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
        nextCursor.value = result.nextCursor;
        loaded.value = true;
    } catch {
        if (current === generation) {
            clear();
            error.value =
                'The activity log could not be loaded. Refresh the log to try again.';
        }
    } finally {
        if (current === generation) {
            loading.value = false;
        }
    }
};
watch(open, (value) => {
    if (value) {
        void load();
    } else {
        clear();
    }
});
watch(
    () => [props.context, page.props.branchContext?.active?.id],
    () => {
        open.value = false;
        clear();
        contextBlocked.value = false;
    },
);
const stopStart = router.on('start', () => {
    contextBlocked.value = true;
    open.value = false;
    clear();
});
onBeforeUnmount(() => {
    clear();
    stopStart();
});
const icon = (entry: InvoiceActivityEntry) =>
    entry.amountSen !== null
        ? CreditCard
        : entry.message.endsWith('completed')
          ? CircleCheck
          : Clock3;
</script>

<template>
    <Sheet v-if="context && !blocked" v-model:open="open">
        <SheetTrigger as-child
            ><Button
                type="button"
                variant="outline"
                class="h-[30px] px-2 text-xs"
                >View activity log</Button
            ></SheetTrigger
        >
        <SheetContent
            side="right"
            class="w-full gap-0 sm:max-w-[480px]"
            data-testid="invoice-activity-drawer"
        >
            <SheetHeader class="shrink-0 border-b p-5 pr-12">
                <SheetTitle class="text-base">Logs</SheetTitle>
                <InvoiceNotes
                    v-if="notesContext"
                    :context="notesContext"
                    drawer
                    @saved="load()"
                />
                <SheetDescription class="text-xs"
                    >Activity entries are read-only. Visit milestones are
                    shared; financial entries belong to this invoice. Exact item
                    and price history is not reconstructed.</SheetDescription
                >
            </SheetHeader>
            <div
                class="min-h-0 flex-1 overflow-y-auto p-4 text-[13px]"
                :aria-busy="loading"
                data-testid="invoice-activity-scroll"
            >
                <p v-if="loading && !loaded" role="status">Loading activity…</p>
                <p
                    v-if="error"
                    role="alert"
                    class="mb-3 rounded-lg border border-destructive/30 p-3 text-destructive"
                >
                    {{ error }}
                </p>
                <p
                    v-if="loaded && !entries.length"
                    role="status"
                    class="rounded-lg border p-5 text-center text-muted-foreground"
                >
                    No activity is available for this invoice.
                </p>
                <ol
                    class="grid gap-3"
                    aria-label="Invoice activity"
                    data-testid="invoice-activity-entries"
                >
                    <li
                        v-for="entry in entries"
                        :key="entry.id"
                        class="relative flex gap-3 rounded-lg border bg-card p-3 before:absolute before:top-full before:left-6 before:h-3 before:border-l last:before:hidden"
                    >
                        <component
                            :is="icon(entry)"
                            class="mt-0.5 size-5 shrink-0 rounded-full bg-muted p-0.5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="break-words">
                                {{ entry.message
                                }}<span
                                    v-if="entry.amountSen !== null"
                                    class="ml-1 font-medium tabular-nums"
                                    >{{ myr(entry.amountSen) }}</span
                                >
                            </p>
                            <p
                                v-if="entry.method"
                                class="mt-1 text-xs break-words text-muted-foreground"
                            >
                                {{ entry.method }}
                            </p>
                            <p class="mt-1 text-[11px] text-muted-foreground">
                                {{ entry.scope }}
                            </p>
                            <p
                                class="mt-1 flex items-start gap-1 text-xs text-muted-foreground"
                            >
                                <UserRound
                                    class="mt-0.5 size-3 shrink-0"
                                    aria-hidden="true"
                                /><span class="break-words"
                                    >{{ entry.actor }} ·
                                    {{ entry.occurredAt }}</span
                                >
                            </p>
                        </div>
                    </li>
                </ol>
                <p v-if="loading && loaded" role="status" class="mt-3">
                    Loading more activity…
                </p>
            </div>
            <div
                class="flex shrink-0 items-center justify-between gap-3 border-t p-4"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="h-[30px] text-xs"
                    :disabled="loading"
                    @click="load()"
                    >Refresh</Button
                >
                <Button
                    v-if="nextCursor"
                    type="button"
                    variant="outline"
                    class="h-[30px] text-xs"
                    :disabled="loading || !!error"
                    @click="load(true)"
                    >Load more</Button
                >
                <span
                    v-else-if="loaded && entries.length"
                    class="text-xs text-muted-foreground"
                    role="status"
                    >All available activity loaded</span
                >
            </div>
        </SheetContent>
    </Sheet>
</template>
