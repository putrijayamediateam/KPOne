<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Ban, PackageOpen, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { OperationalSelect } from '@/components/ui/select';
import { refuseReasonLabels } from '@/lib/dispensary-returns';
import type {
    DispensaryReturnLine,
    DispensaryReturnRow,
    DispensaryReturnsPanel,
} from '@/lib/dispensary-returns';

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
    'flex min-h-11 cursor-pointer items-start gap-2 rounded-md border px-3 py-2 text-sm focus-within:ring-3 focus-within:ring-ring/40 has-[:checked]:border-brand has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 sm:min-h-9';
const amount = computed(() => `${props.row.quantity} ${props.line.unit}`);
const locationName = computed(
    () =>
        locations.value.find((l) => l.publicId === form.location_public_id)
            ?.name ?? 'the returns location',
);
const dialog = computed(() => {
    if (form.outcome === 'quarantine') {
        return {
            title: `Move ${amount.value} of ${props.line.name} to ${locationName.value}?`,
            description:
                'The units go on hold at the returns location and cannot be dispensed. This check cannot be undone.',
            confirm: 'Move to returns location',
            icon: PackageOpen,
            busy: 'Moving…',
            destructive: false,
        };
    }

    if (form.outcome === 'refuse') {
        return {
            title: 'Refuse this return?',
            description:
                'Nothing changes in stock. The return stays in the history as refused, and its quantity can be recorded again.',
            confirm: 'Refuse return',
            icon: Ban,
            busy: 'Refusing…',
            destructive: false,
        };
    }

    return {
        title: `Dispose ${amount.value} of ${props.line.name}?`,
        description:
            'The units are destroyed and no stock goes back. This check cannot be undone.',
        confirm: `Dispose ${amount.value}`,
        icon: Trash2,
        busy: 'Disposing…',
        destructive: true,
    };
});

const validate = (): boolean => {
    form.clearErrors();

    if (form.outcome === 'quarantine') {
        if (!form.location_public_id) {
            form.setError('location_public_id', 'Choose a returns location.');
        }

        if (!form.batch_public_id) {
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

    return Object.keys(form.errors).length === 0;
};

const openConfirm = async () => {
    if (validate()) {
        confirmOpen.value = true;

        return;
    }

    await nextTick();
    document
        .getElementById(id('panel'))
        ?.querySelector<HTMLElement>(
            '[aria-invalid="true"], input[aria-invalid]',
        )
        ?.focus();
};

const submit = () => {
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
            data.outcome === 'quarantine' ? data.batch_public_id : null,
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
</script>

<template>
    <div
        :id="id('panel')"
        class="mt-2 grid gap-3 rounded-md border bg-card p-3"
        data-testid="return-check-panel"
    >
        <p class="text-sm font-semibold">Check this return</p>
        <p
            v-if="serverAlert"
            role="alert"
            class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
        >
            {{ serverAlert }}
        </p>

        <template v-if="line.controlled && form.outcome !== 'refuse'">
            <p class="text-sm">
                Outcome: <span class="font-semibold">Dispose</span>
            </p>
            <p class="text-xs text-muted-foreground">
                Controlled medicines are always disposed. A CA Supervisor checks
                them.
            </p>
        </template>
        <fieldset v-else-if="!line.controlled" class="grid gap-2 text-sm">
            <legend class="mb-1 font-medium">
                Outcome <span class="text-muted-foreground">(required)</span>
            </legend>
            <label
                v-for="outcome in row.allowedOutcomes"
                :key="outcome"
                :class="tileClass"
            >
                <input
                    v-model="form.outcome"
                    type="radio"
                    :name="id('outcome')"
                    :value="outcome"
                    :disabled="
                        form.processing ||
                        (outcome === 'quarantine' && locations.length === 0)
                    "
                    class="mt-1 accent-brand"
                    data-field="check-outcome"
                />
                <span>
                    <span class="font-medium">{{
                        outcomeText[outcome]?.label ?? outcome
                    }}</span>
                    <span
                        v-if="outcome === 'dispose'"
                        class="text-muted-foreground"
                    >
                        (usual choice)</span
                    >
                    <span class="block text-xs text-muted-foreground">{{
                        outcome === 'quarantine' && locations.length === 0
                            ? 'This branch has no returns location yet. Ask the Inventory team to set one up.'
                            : outcomeText[outcome]?.help
                    }}</span>
                </span>
            </label>
        </fieldset>

        <template v-if="form.outcome === 'quarantine'">
            <div v-if="locations.length > 1" class="text-sm">
                <label :for="id('location')" class="font-medium"
                    >Returns location
                    <span class="text-muted-foreground">(required)</span></label
                >
                <OperationalSelect
                    :id="id('location')"
                    v-model="form.location_public_id"
                    class="mt-1"
                    trigger-class="h-11 sm:h-9"
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
                    >Only returns locations at this branch are listed.</span
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
            <div v-if="line.batches.length > 1" class="text-sm">
                <label :for="id('batch')" class="font-medium"
                    >Batch the units came from
                    <span class="text-muted-foreground">(required)</span></label
                >
                <OperationalSelect
                    :id="id('batch')"
                    v-model="form.batch_public_id"
                    class="mt-1"
                    trigger-class="h-11 sm:h-9"
                    label="Batch"
                    placeholder="Choose a batch"
                    :invalid="!!form.errors.batch_public_id"
                    :disabled="form.processing"
                    :options="
                        line.batches.map((b) => ({
                            value: b.publicId,
                            label: b.label,
                        }))
                    "
                />
            </div>
            <span
                v-if="form.errors.batch_public_id"
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
                    <span class="text-muted-foreground">(required)</span>
                </legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="reason in returns.refuseReasons"
                        :key="reason"
                        :class="tileClass"
                    >
                        <input
                            v-model="form.refuse_reason"
                            type="radio"
                            :name="id('refuse-reason')"
                            :value="reason"
                            :disabled="form.processing"
                            :aria-invalid="
                                form.errors.refuse_reason ? 'true' : undefined
                            "
                            class="mt-1 accent-brand"
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
                    <span class="text-muted-foreground">(required)</span></label
                >
                <textarea
                    :id="id('refuse-note')"
                    v-model="form.refuse_note"
                    rows="2"
                    :maxlength="returns.noteMax"
                    :disabled="form.processing"
                    :aria-invalid="form.errors.refuse_note ? 'true' : undefined"
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
                    >Up to {{ returns.noteMax }} characters. Do not write a
                    diagnosis or identity details.</span
                >
                <span
                    v-if="form.errors.refuse_note"
                    :id="`${id('refuse-note')}-error`"
                    class="mt-1 block text-xs text-destructive"
                    >{{ form.errors.refuse_note }}</span
                >
            </div>
        </template>

        <div class="flex flex-col gap-2 sm:flex-row">
            <template v-if="line.controlled && form.outcome !== 'refuse'">
                <Button
                    type="button"
                    variant="destructive"
                    class="h-11 w-full sm:h-9 sm:w-auto"
                    :disabled="form.processing"
                    data-action="confirm-disposal"
                    @click="openConfirm"
                >
                    Confirm disposal
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="h-11 w-full sm:h-9 sm:w-auto"
                    :disabled="form.processing"
                    data-action="refuse-instead"
                    @click="form.outcome = 'refuse'"
                >
                    Refuse instead
                </Button>
            </template>
            <Button
                v-if="line.controlled && form.outcome === 'refuse'"
                type="button"
                variant="outline"
                class="h-11 w-full sm:h-9 sm:w-auto"
                :disabled="form.processing"
                data-action="dispose-instead"
                @click="form.outcome = 'dispose'"
            >
                Dispose instead
            </Button>
            <Button
                v-if="!line.controlled || form.outcome === 'refuse'"
                type="button"
                class="h-11 w-full sm:h-9 sm:w-auto"
                :disabled="form.processing"
                data-action="confirm-check"
                @click="openConfirm"
            >
                Confirm check
            </Button>
        </div>

        <OperationalConfirmDialog
            v-model:open="confirmOpen"
            :title="dialog.title"
            :description="dialog.description"
            :confirm-label="dialog.confirm"
            :processing-label="dialog.busy"
            :processing="form.processing"
            :destructive="dialog.destructive"
            @confirm="submit"
        >
            <template #icon>
                <component :is="dialog.icon" class="size-5" />
            </template>
        </OperationalConfirmDialog>
    </div>
</template>
