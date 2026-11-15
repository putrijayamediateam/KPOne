<script setup lang="ts">
/**
 * Version 2 copy of Classic's ReturnCheckPanel (Daniel, 2026-11-15). The script is Classic's, kept in step by
 * tests/Frontend/v2-dispensary-completed.test.mjs; only the V2 consts at the end are new. Each check (Dispose,
 * Quarantine, Refuse) opens its own sheet, and the sheet is the confirm step, so the page keeps one filled button.
 */
import { useForm } from '@inertiajs/vue3';
import { Ban, PackageOpen, Trash2 } from '@lucide/vue';
import { computed, inject, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import {
    fromThousandths,
    refuseReasonLabels,
    toThousandths,
} from '@/lib/dispensary-returns';
import type {
    DispensaryReturnLine,
    DispensaryReturnRow,
    DispensaryReturnsPanel,
} from '@/lib/dispensary-returns';
import { quantityWords } from './quantity-words';
import { RETURN_SHEET_CLOSED } from './return-sheet';

// DSP-7 step 2 (owner decisions 2026-11-05): a CA Supervisor who did not record the return checks it once. The
// server repeats every check (checker, controlled medicine, location, batch, already checked).
const props = defineProps<{
    returns: DispensaryReturnsPanel;
    line: DispensaryReturnLine;
    row: DispensaryReturnRow;
}>();
const emit = defineEmits<{ checked: [message: string] }>();

const outcomeText: Record<string, { label: string; help: string }> = {
    dispose: {
        label: 'Dispose',
        help: 'The returned units are destroyed. No stock goes back.',
    },
    quarantine: {
        label: 'Quarantine',
        help: 'The units go to a returns location, on hold for a later decision. They can never be dispensed from there.',
    },
    refuse: {
        label: 'Refuse',
        help: 'The return was recorded by mistake. Nothing changes in stock; the record stays as refused.',
    },
};

const id = (name: string) => `check-${props.row.publicId}-${name}`;
const locations = computed(() => props.returns.returnsLocations);
const form = useForm({
    outcome: 'dispose',
    refuse_reason: '',
    refuse_note: '',
    location_public_id:
        props.returns.returnsLocations.length === 1
            ? props.returns.returnsLocations[0].publicId
            : '',
    batch_public_id:
        props.line.batches.length === 1 ? props.line.batches[0].publicId : '',
    retry_key: crypto.randomUUID(),
});
const confirmOpen = ref(false);

// DSP-15: a line dispensed from two or more batches is split across them: one box per batch, empty means 0. The
// checker types what the boxes in hand show; "Fill from earliest expiry" only helps when they clearly match.
const isSplit = computed(() => props.line.batches.length > 1);
const shares = ref<Record<string, string>>(
    Object.fromEntries(props.line.batches.map((b) => [b.publicId, ''])),
);
const splitErrors = ref<Record<string, string>>({});
// The batches sent in the last request, in order, so a server message for batches.N lands under its own box.
const sentOrder = ref<string[]>([]);
const returned = computed(() => toThousandths(props.row.quantity) ?? 0);
const available = (publicId: string): number =>
    toThousandths(
        props.line.batches.find((b) => b.publicId === publicId)?.available ??
            '0',
    ) ?? 0;
const quarantineBlocked = computed(
    () =>
        props.line.quarantineAvailable !== null &&
        (toThousandths(props.line.quarantineAvailable) ?? 0) < returned.value,
);
const placed = computed(() =>
    Object.values(shares.value).reduce(
        (sum, value) => sum + Math.max(0, toThousandths(value) ?? 0),
        0,
    ),
);
const placedText = computed(() => {
    const base = `Placed ${fromThousandths(placed.value)} of ${props.row.quantity} ${props.line.unit}`;
    const difference = returned.value - placed.value;

    if (difference === 0) {
        return `${base} · All placed`;
    }

    return difference > 0
        ? `${base} · ${fromThousandths(difference)} left`
        : `${base} · ${fromThousandths(-difference)} too many`;
});
// What screen readers hear changes only after a pause in typing, so it does not chatter.
const placedAnnouncement = ref('');
let announceTimer: ReturnType<typeof setTimeout> | undefined;
watch(placedText, (text) => {
    clearTimeout(announceTimer);
    announceTimer = setTimeout(() => {
        placedAnnouncement.value = text;
    }, 500);
});
const anyTyped = computed(() =>
    Object.values(shares.value).some((value) => value.trim() !== ''),
);
const fillFromEarliestExpiry = () => {
    let left = returned.value;

    for (const batch of props.line.batches) {
        const take = Math.min(left, available(batch.publicId));
        shares.value[batch.publicId] = take > 0 ? fromThousandths(take) : '';
        left -= take;
    }

    clearTimeout(announceTimer);
    placedAnnouncement.value =
        'Filled from earliest expiry. Check the boxes match.';
};
const shareError = (publicId: string): string | undefined => {
    if (splitErrors.value[publicId]) {
        return splitErrors.value[publicId];
    }

    const index = sentOrder.value.indexOf(publicId);
    const errors = form.errors as Record<string, string | undefined>;

    return index === -1 ? undefined : errors[`batches.${index}.quantity`];
};
const splitError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        splitErrors.value.batches ??
        errors.batches ??
        Object.entries(errors).find(([key]) =>
            /^batches\.\d+\.batch_public_id$/.test(key),
        )?.[1]
    );
});
const sharesToSend = computed(() =>
    props.line.batches
        .map((b) => ({
            batch_public_id: b.publicId,
            quantity: (shares.value[b.publicId] ?? '').trim(),
        }))
        .filter((share) => (toThousandths(share.quantity) ?? 0) > 0),
);
// Typing in a box clears only the batch messages, never the location or a server alert.
watch(
    shares,
    () => {
        splitErrors.value = {};
        const batchKeys = Object.keys(form.errors).filter((key) =>
            key.startsWith('batches'),
        );

        if (batchKeys.length > 0) {
            form.clearErrors(...(batchKeys as (keyof typeof form.data)[]));
        }
    },
    { deep: true },
);
// Clear a field's error as soon as the field is corrected, so a fixed field never keeps its message.
watch(
    () => form.outcome,
    () => form.clearErrors(),
);

for (const field of [
    'refuse_reason',
    'refuse_note',
    'location_public_id',
    'batch_public_id',
] as const) {
    watch(
        () => form[field],
        () => form.clearErrors(field),
    );
}

const tileClass =
    'flex min-h-11 cursor-pointer items-start gap-2 rounded-md border px-3 py-2 text-sm focus-within:ring-3 focus-within:ring-ring/40 has-[:checked]:border-brand has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 lg:min-h-9';
// DSP-19 (Daniel): one-line reason tiles centre the radio and the words; outcome tiles keep a top-aligned description.
const reasonTileClass = tileClass.replace('items-start', 'items-center');
const locationName = computed(
    () =>
        locations.value.find((l) => l.publicId === form.location_public_id)
            ?.name ?? 'the returns location',
);
// DSP-15: the checker sees the batch or batches before confirming a quarantine.
const dialogBatches = computed(() =>
    isSplit.value
        ? sharesToSend.value.map((share) => ({
              publicId: share.batch_public_id,
              label:
                  props.line.batches.find(
                      (b) => b.publicId === share.batch_public_id,
                  )?.label ?? 'Batch',
              quantity: fromThousandths(toThousandths(share.quantity) ?? 0),
          }))
        : props.line.batches
              .filter((b) => b.publicId === form.batch_public_id)
              .map((b) => ({
                  publicId: b.publicId,
                  label: b.label,
                  quantity: props.row.quantity,
              })),
);
const validate = (): boolean => {
    form.clearErrors();

    splitErrors.value = {};

    if (form.outcome === 'quarantine') {
        if (!form.location_public_id) {
            form.setError('location_public_id', 'Choose a returns location.');
        }

        if (isSplit.value) {
            for (const batch of props.line.batches) {
                const value = toThousandths(shares.value[batch.publicId] ?? '');

                if (value === null) {
                    splitErrors.value[batch.publicId] =
                        'Enter a number with up to 3 decimals, for example 2.5.';
                } else if (value > available(batch.publicId)) {
                    splitErrors.value[batch.publicId] =
                        `At most ${batch.available} ${props.line.unit} from this batch.`;
                }
            }

            if (Object.keys(splitErrors.value).length === 0) {
                if (placed.value === 0) {
                    splitErrors.value.batches =
                        'Enter how many units went back to each batch.';
                } else if (placed.value !== returned.value) {
                    splitErrors.value.batches = `The batches add up to ${fromThousandths(placed.value)} ${props.line.unit}. They must add up to ${props.row.quantity} ${props.line.unit}.`;
                }
            }
        } else if (!form.batch_public_id) {
            form.setError(
                'batch_public_id',
                'Choose the batch the units came from.',
            );
        }
    }

    if (form.outcome === 'refuse') {
        if (!form.refuse_reason) {
            form.setError('refuse_reason', 'Choose a reason for refusing.');
        } else if (
            form.refuse_reason === 'other' &&
            form.refuse_note.trim() === ''
        ) {
            form.setError('refuse_note', 'Write a short note for Other.');
        }
    }

    return (
        Object.keys(form.errors).length === 0 &&
        Object.keys(splitErrors.value).length === 0
    );
};

const submit = () => {
    sentOrder.value =
        isSplit.value && form.outcome === 'quarantine'
            ? sharesToSend.value.map((share) => share.batch_public_id)
            : [];
    form.transform((data) => ({
        expected_branch_id: props.returns.branchId,
        retry_key: data.retry_key,
        outcome: data.outcome,
        refuse_reason: data.outcome === 'refuse' ? data.refuse_reason : null,
        refuse_note:
            data.outcome === 'refuse' && data.refuse_reason === 'other'
                ? data.refuse_note.trim()
                : null,
        location_public_id:
            data.outcome === 'quarantine' ? data.location_public_id : null,
        batch_public_id:
            data.outcome === 'quarantine' && !isSplit.value
                ? data.batch_public_id
                : null,
        batches:
            data.outcome === 'quarantine' && isSplit.value
                ? sharesToSend.value
                : null,
    })).post(
        `/dispensary/${props.returns.casePublicId}/returns/${props.row.publicId}/check`,
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmOpen.value = false;
                emit(
                    'checked',
                    form.outcome === 'quarantine'
                        ? `Return moved to ${locationName.value}.`
                        : form.outcome === 'refuse'
                          ? 'Return refused.'
                          : 'Return disposed.',
                );
            },
            onError: () => {
                confirmOpen.value = false;
            },
        },
    );
};

const serverAlert = computed(() => {
    const key = ['state', 'outcome', 'retry_key', 'expected_branch_id'].find(
        (k) => form.errors[k as keyof typeof form.errors],
    );

    return key ? form.errors[key as keyof typeof form.errors] : null;
});

// ---- V2 only (Daniel): one sheet per check. ----
const sheetOpen = ref(false);
const outcomeButtons = computed(() =>
    props.row.allowedOutcomes.filter(
        (outcome) => !(props.line.controlled && outcome === 'quarantine'),
    ),
);
const outcomeDisabledReason = (outcome: string): string | null => {
    if (outcome !== 'quarantine') {
        return null;
    }

    if (locations.value.length === 0) {
        return 'This branch has no returns location yet. Ask the Inventory team to set one up.';
    }

    return quarantineBlocked.value
        ? `Only ${quantityWords(props.line.quarantineAvailable ?? '0', props.line.unit)} can go back to this line's batches. Dispose the return, or refuse it if it was recorded wrongly.`
        : null;
};
const amountWords = computed(() =>
    quantityWords(props.row.quantity, props.line.unit),
);
const sheetText = computed(() => {
    if (form.outcome === 'quarantine') {
        return {
            title: `Move ${amountWords.value} to a returns location?`,
            confirm: 'Move to returns location',
            busy: 'Moving…',
            tone: 'default' as const,
            footer: `Moves ${amountWords.value} to ${locationName.value}. They can never be dispensed from there. This check cannot be undone.`,
        };
    }

    if (form.outcome === 'refuse') {
        return {
            title: 'Refuse this return?',
            confirm: 'Refuse return',
            busy: 'Refusing…',
            tone: 'danger' as const,
            footer: 'Nothing changes in stock. The return stays in the history as refused, and its quantity can be recorded again.',
        };
    }

    return {
        title: `Dispose ${amountWords.value}?`,
        confirm: `Dispose ${amountWords.value}`,
        busy: 'Disposing…',
        tone: 'danger' as const,
        footer: 'The units are destroyed. No stock change: nothing goes back into stock. This check cannot be undone.',
    };
});
const sheetDirty = computed(
    () =>
        form.refuse_reason !== '' ||
        form.refuse_note.trim() !== '' ||
        anyTyped.value,
);
const clearSheet = () => {
    form.refuse_reason = '';
    form.refuse_note = '';

    for (const key of Object.keys(shares.value)) {
        shares.value[key] = '';
    }

    splitErrors.value = {};
    form.clearErrors();
};
const openSheet = (outcome: string) => {
    clearSheet();
    form.outcome = outcome;
    sheetOpen.value = true;
};
// V2Dialog's own `dirty` guard asks "Discard your changes?" before this runs; a close while saving is ignored.
const notifySheetClosed = inject(RETURN_SHEET_CLOSED, () => {});
const onSheetOpen = (open: boolean) => {
    if (open || form.processing) {
        return;
    }

    clearSheet();
    sheetOpen.value = false;
    notifySheetClosed(props.row.publicId);
};
// Daniel: a stock action stays off, with the reason beside it, until it is valid (the server repeats every check).
const sheetBlockedReason = computed((): string | null => {
    if (form.outcome === 'quarantine') {
        if (!form.location_public_id) {
            return 'Choose a returns location to continue.';
        }

        if (isSplit.value) {
            return placed.value === returned.value &&
                props.line.batches.every(
                    (batch) =>
                        (toThousandths(shares.value[batch.publicId] ?? '') ??
                            -1) >= 0 &&
                        (toThousandths(shares.value[batch.publicId] ?? '') ??
                            0) <= available(batch.publicId),
                )
                ? null
                : `Place all ${amountWords.value} to continue.`;
        }

        return form.batch_public_id
            ? null
            : 'Choose the batch the units came from to continue.';
    }

    if (form.outcome === 'refuse') {
        if (!form.refuse_reason) {
            return 'Pick a reason to continue.';
        }

        return form.refuse_reason === 'other' && form.refuse_note.trim() === ''
            ? 'Write a short note for Other to continue.'
            : null;
    }

    return null;
});
// Daniel: the placed line in words ("Placed 0 of 18 tablets · 18 left"); Classic's placedText stays for its announcement.
const placedWords = computed(() => {
    const base = `Placed ${quantityWords(fromThousandths(placed.value), props.line.unit)} of ${amountWords.value}`;
    const difference = returned.value - placed.value;

    if (difference === 0) {
        return `${base} · All placed`;
    }

    return difference > 0
        ? `${base} · ${fromThousandths(difference)} left`
        : `${base} · ${fromThousandths(-difference)} too many`;
});
const confirmSheet = async () => {
    if (form.processing) {
        return;
    }

    if (validate()) {
        submit();

        return;
    }

    await nextTick();
    const root = document.getElementById(id('sheet'));
    (
        root?.querySelector<HTMLElement>(
            '[aria-invalid="true"], input[aria-invalid]',
        ) ??
        root?.querySelector<HTMLElement>(
            'input[data-field="check-share"]:not(:disabled)',
        )
    )?.focus();
};
</script>

<template>
    <div
        :id="id('panel')"
        class="mt-2 grid gap-2"
        data-testid="return-check-panel"
    >
        <p class="text-sm font-semibold">Check this return</p>
        <p
            v-if="serverAlert && !sheetOpen"
            role="alert"
            class="rounded-[10px] border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
        >
            {{ serverAlert }}
        </p>
        <p v-if="line.controlled" class="text-xs text-muted-foreground">
            Controlled medicines are always disposed. A CA Supervisor checks
            them.
        </p>
        <!-- V2 (Daniel): one outline button per check; each opens its own sheet. Refuse reads red. -->
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            <template v-for="outcome in outcomeButtons" :key="outcome">
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-disabled:cursor-not-allowed aria-disabled:opacity-60 lg:h-9"
                    :class="
                        outcome === 'refuse'
                            ? 'border-destructive/50 text-destructive'
                            : 'border-border dark:border-foreground/20'
                    "
                    :aria-disabled="
                        outcomeDisabledReason(outcome) ? 'true' : undefined
                    "
                    :aria-describedby="
                        outcomeDisabledReason(outcome)
                            ? id(`${outcome}-reason`)
                            : undefined
                    "
                    :data-action="`check-${outcome}`"
                    @click="
                        outcomeDisabledReason(outcome)
                            ? null
                            : openSheet(outcome)
                    "
                >
                    <Trash2
                        v-if="outcome === 'dispose'"
                        class="size-4"
                        aria-hidden="true"
                    /><PackageOpen
                        v-else-if="outcome === 'quarantine'"
                        class="size-4"
                        aria-hidden="true"
                    /><Ban v-else class="size-4" aria-hidden="true" />{{
                        outcomeText[outcome]?.label ?? outcome
                    }}
                </button>
            </template>
        </div>
        <template v-for="outcome in outcomeButtons" :key="`${outcome}-why`">
            <p
                v-if="outcomeDisabledReason(outcome)"
                :id="id(`${outcome}-reason`)"
                class="text-xs text-muted-foreground"
            >
                {{ outcomeDisabledReason(outcome) }}
            </p>
        </template>

        <V2Dialog
            :open="sheetOpen"
            :title="sheetText.title"
            :confirm-label="
                form.processing ? sheetText.busy : sheetText.confirm
            "
            :tone="sheetText.tone"
            :busy="form.processing"
            :dirty="sheetDirty"
            :confirm-disabled="sheetBlockedReason !== null"
            :confirm-disabled-reason="sheetBlockedReason ?? undefined"
            @update:open="onSheetOpen"
            @confirm="confirmSheet"
        >
            <div
                :id="id('sheet')"
                class="grid gap-3 text-sm"
                data-testid="return-check-sheet"
                :aria-busy="form.processing"
            >
                <!-- Summary first (Daniel): the medicine line, the quantity and the batch. -->
                <div
                    class="rounded-[10px] border bg-muted/40 px-3 py-2 dark:border-foreground/20"
                    data-testid="return-check-summary"
                >
                    <p class="font-medium break-words">{{ line.name }}</p>
                    <p class="tabular-nums">
                        {{ amountWords }} returned ·
                        {{ outcomeText[form.outcome]?.label ?? form.outcome }}
                    </p>
                    <p
                        v-if="line.batches.length"
                        class="text-xs [overflow-wrap:anywhere] text-muted-foreground"
                    >
                        {{
                            line.batches.length === 1
                                ? `Batch ${line.batches[0].label}`
                                : `Dispensed from ${line.batches.length} batches`
                        }}
                    </p>
                </div>
                <div
                    v-if="
                        form.outcome === 'quarantine' &&
                        dialogBatches.length > 1
                    "
                    class="tabular-nums"
                    data-testid="return-check-dialog-batches"
                >
                    <p>From {{ dialogBatches.length }} batches:</p>
                    <ul class="mt-1 list-disc pl-5">
                        <li
                            v-for="b in dialogBatches"
                            :key="b.publicId"
                            class="[overflow-wrap:anywhere]"
                        >
                            {{ b.label }} —
                            {{ quantityWords(b.quantity, line.unit) }}
                        </li>
                    </ul>
                </div>
                <p
                    v-if="serverAlert"
                    role="alert"
                    class="rounded-[10px] border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
                >
                    {{ serverAlert }}
                </p>
                <template v-if="form.outcome === 'quarantine'">
                    <div v-if="locations.length > 1" class="text-sm">
                        <label :for="id('location')" class="font-medium"
                            >Returns location
                            <span class="text-muted-foreground"
                                >(required)</span
                            ></label
                        >
                        <OperationalSelect
                            :id="id('location')"
                            v-model="form.location_public_id"
                            class="mt-1"
                            trigger-class="h-11 lg:h-9"
                            label="Returns location"
                            placeholder="Choose a returns location"
                            :invalid="!!form.errors.location_public_id"
                            :disabled="form.processing"
                            :options="
                                locations.map((l) => ({
                                    value: l.publicId,
                                    label: l.name,
                                }))
                            "
                        />
                        <span class="mt-1 block text-xs text-muted-foreground"
                            >Only returns locations at this branch are
                            listed.</span
                        >
                    </div>
                    <p v-else-if="locations.length === 1" class="text-sm">
                        Goes to:
                        <span class="font-medium">{{ locations[0].name }}</span>
                    </p>
                    <span
                        v-if="form.errors.location_public_id"
                        :id="id('location-error')"
                        role="alert"
                        class="text-xs text-destructive"
                        >{{ form.errors.location_public_id }}</span
                    >
                    <fieldset
                        v-if="isSplit"
                        class="grid gap-3 text-sm"
                        :aria-describedby="
                            splitError
                                ? `${id('split-help')} ${id('split-error')}`
                                : id('split-help')
                        "
                        data-testid="return-check-split"
                    >
                        <legend class="mb-1 font-medium">
                            Batches the units came from
                            <span class="text-muted-foreground"
                                >(required)</span
                            >
                        </legend>
                        <p
                            :id="id('split-help')"
                            class="text-xs text-muted-foreground"
                        >
                            Enter how many went back to each batch. Leave 0 for
                            a batch you did not use.
                        </p>
                        <div
                            v-for="batch in line.batches"
                            :key="batch.publicId"
                            class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1"
                        >
                            <div class="min-w-0">
                                <label
                                    :for="id(`share-${batch.publicId}`)"
                                    class="font-medium [overflow-wrap:anywhere]"
                                    >{{ batch.label }}</label
                                >
                                <span
                                    :id="
                                        id(`share-${batch.publicId}-available`)
                                    "
                                    class="block text-xs text-muted-foreground tabular-nums"
                                    >{{
                                        batch.available === '0'
                                            ? 'None left to return from this batch'
                                            : `Available: ${quantityWords(batch.available, line.unit)}`
                                    }}</span
                                >
                            </div>
                            <span class="flex items-center gap-2">
                                <input
                                    :id="id(`share-${batch.publicId}`)"
                                    v-model="shares[batch.publicId]"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    placeholder="0"
                                    :disabled="
                                        form.processing ||
                                        batch.available === '0'
                                    "
                                    :aria-label="`Quantity from batch ${batch.label}`"
                                    :aria-invalid="
                                        shareError(batch.publicId)
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="
                                        shareError(batch.publicId)
                                            ? `${id(`share-${batch.publicId}-available`)} ${id(`share-${batch.publicId}-error`)}`
                                            : id(
                                                  `share-${batch.publicId}-available`,
                                              )
                                    "
                                    class="h-9 w-24 rounded-md border border-input bg-card px-3 text-right tabular-nums outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-60 aria-invalid:border-destructive max-lg:h-11"
                                    data-field="check-share"
                                />
                                <span class="text-muted-foreground">{{
                                    line.unit
                                }}</span>
                            </span>
                            <span
                                v-if="shareError(batch.publicId)"
                                :id="id(`share-${batch.publicId}-error`)"
                                role="alert"
                                class="col-span-2 text-xs text-destructive"
                                >{{ shareError(batch.publicId) }}</span
                            >
                        </div>
                        <p
                            class="text-sm tabular-nums"
                            :class="
                                placed > returned
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                            aria-hidden="true"
                            data-testid="return-check-placed"
                        >
                            {{ placedWords }}
                        </p>
                        <p class="sr-only" role="status" aria-live="polite">
                            {{ placedAnnouncement }}
                        </p>
                        <span
                            v-if="splitError"
                            :id="id('split-error')"
                            role="alert"
                            class="text-xs text-destructive"
                            >{{ splitError }}</span
                        >
                        <div
                            class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3"
                        >
                            <Button
                                type="button"
                                variant="outline"
                                class="h-9 w-full max-lg:h-11 sm:w-auto"
                                :disabled="form.processing || anyTyped"
                                data-action="fill-earliest-expiry"
                                @click="fillFromEarliestExpiry"
                            >
                                Fill from earliest expiry
                            </Button>
                            <span class="text-xs text-muted-foreground"
                                >Use only if the boxes in hand show these
                                batches.</span
                            >
                        </div>
                    </fieldset>
                    <span
                        v-if="!isSplit && form.errors.batch_public_id"
                        :id="id('batch-error')"
                        role="alert"
                        class="text-xs text-destructive"
                        >{{ form.errors.batch_public_id }}</span
                    >
                </template>
                <template v-if="form.outcome === 'refuse'">
                    <fieldset
                        class="grid gap-2 text-sm"
                        :aria-describedby="
                            form.errors.refuse_reason
                                ? id('refuse-reason-error')
                                : undefined
                        "
                    >
                        <legend class="mb-1 font-medium">
                            Reason for refusing
                            <span class="text-muted-foreground"
                                >(required)</span
                            >
                        </legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="reason in returns.refuseReasons"
                                :key="reason"
                                :class="reasonTileClass"
                            >
                                <input
                                    v-model="form.refuse_reason"
                                    type="radio"
                                    :name="id('refuse-reason')"
                                    :value="reason"
                                    :disabled="form.processing"
                                    :aria-invalid="
                                        form.errors.refuse_reason
                                            ? 'true'
                                            : undefined
                                    "
                                    class="accent-brand"
                                    data-field="check-refuse-reason"
                                />
                                {{ refuseReasonLabels[reason] ?? reason }}
                            </label>
                        </div>
                        <span
                            v-if="form.errors.refuse_reason"
                            :id="id('refuse-reason-error')"
                            class="text-xs text-destructive"
                            >{{ form.errors.refuse_reason }}</span
                        >
                    </fieldset>
                    <div v-if="form.refuse_reason === 'other'" class="text-sm">
                        <label :for="id('refuse-note')" class="font-medium"
                            >Note
                            <span class="text-muted-foreground"
                                >(required)</span
                            ></label
                        >
                        <textarea
                            :id="id('refuse-note')"
                            v-model="form.refuse_note"
                            rows="2"
                            :maxlength="returns.noteMax"
                            :disabled="form.processing"
                            :aria-invalid="
                                form.errors.refuse_note ? 'true' : undefined
                            "
                            :aria-describedby="
                                form.errors.refuse_note
                                    ? `${id('refuse-note')}-help ${id('refuse-note')}-error`
                                    : `${id('refuse-note')}-help`
                            "
                            class="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                            data-field="check-refuse-note"
                        />
                        <span
                            :id="`${id('refuse-note')}-help`"
                            class="mt-1 block text-xs text-muted-foreground"
                            >Up to {{ returns.noteMax }} characters. Do not
                            write a diagnosis or identity details.</span
                        >
                        <span
                            v-if="form.errors.refuse_note"
                            :id="`${id('refuse-note')}-error`"
                            class="mt-1 block text-xs text-destructive"
                            >{{ form.errors.refuse_note }}</span
                        >
                    </div>
                </template>
            </div>
            <template #footer-note>
                <p
                    class="text-[13px] text-muted-foreground"
                    data-testid="return-check-footer"
                >
                    {{ sheetText.footer }}
                </p>
            </template>
        </V2Dialog>
    </div>
</template>
