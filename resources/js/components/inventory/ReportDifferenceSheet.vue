<script setup lang="ts">
/**
 * STK-45 (owner, P10, 25 Nov 2026): Report a difference on one batch at one location. It opens the governed STK-5 count
 * case or adds the report to the live one; it never moves stock. Daniel's design: a radio group that starts empty, one
 * filled button, visible hints, a 44 px close and, when the second-person rule blocks the viewer, a refusal with only
 * Close (a refusal is better than a missing button).
 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';

export type DifferenceState = {
    balanceId: number;
    balanceVersion: number;
    openCount: boolean;
    blocked: boolean;
};

export type DifferenceTarget = {
    medicine: string;
    batch: string;
    expiry: string;
    location: string;
    quantity: string | null;
    unit: string;
    difference: DifferenceState;
};

const props = defineProps<{
    target: DifferenceTarget | null;
    expectedBranchId: number;
    returnTo: 'inventory' | 'stock-review';
    returnQuery?: Record<string, string | number>;
}>();

const emit = defineEmits<{ closed: [] }>();

const open = computed({
    get: () => props.target !== null,
    set: (value: boolean) => {
        if (!value) {
            emit('closed');
        }
    },
});

const observedChoices = [
    { value: 'fewer', label: 'Fewer than KPOne shows' },
    { value: 'more', label: 'More than KPOne shows' },
    { value: 'damaged_or_expired', label: 'Some are damaged or expired' },
    { value: 'unsure', label: 'Not sure; please count it' },
] as const;

const NOTE_LIMIT = 200;

const form = useForm({
    expected_branch_id: 0,
    retry_key: '',
    balance_id: 0,
    balance_version: 0,
    observed: '',
    rough_quantity: '',
    note: '',
    return_to: 'inventory',
    return_query: {} as Record<string, string | number>,
});

watch(
    () => props.target,
    (target) => {
        form.clearErrors();

        if (target === null) {
            return;
        }

        form.defaults({
            expected_branch_id: props.expectedBranchId,
            retry_key: crypto.randomUUID(),
            balance_id: target.difference.balanceId,
            balance_version: target.difference.balanceVersion,
            observed: '',
            rough_quantity: '',
            note: '',
            return_to: props.returnTo,
            return_query: props.returnQuery ?? {},
        });
        form.reset();
    },
);

const errorFor = (field: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[field];

function submit(): void {
    if (form.observed === '' || form.processing) {
        return;
    }

    form.post('/inventory/difference-reports', {
        preserveScroll: true,
        onSuccess: () => emit('closed'),
        // A new key after a refusal, so a corrected report is a new attempt.
        onError: () => (form.retry_key = crypto.randomUUID()),
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="w-full overflow-y-auto max-sm:pb-[env(safe-area-inset-bottom)] sm:max-w-md [&>button:last-child]:top-1.5 [&>button:last-child]:right-1.5 [&>button:last-child]:flex [&>button:last-child]:size-11 [&>button:last-child]:items-center [&>button:last-child]:justify-center"
            data-testid="report-difference-sheet"
        >
            <template v-if="target">
                <SheetHeader>
                    <SheetTitle>{{
                        target.difference.openCount
                            ? 'Add to the open count'
                            : 'Report a difference'
                    }}</SheetTitle>
                    <SheetDescription>
                        {{ target.medicine }} · batch {{ target.batch }} · exp
                        {{ target.expiry }} · {{ target.location }}
                        <span v-if="target.quantity !== null" class="block"
                            >KPOne shows {{ target.quantity }}
                            {{ target.unit }}</span
                        >
                    </SheetDescription>
                </SheetHeader>

                <div
                    v-if="target.difference.blocked"
                    class="flex flex-1 flex-col gap-4 px-4 pb-4"
                    data-testid="report-difference-refusal"
                >
                    <p class="text-sm" role="status">
                        You counted this batch, so another person has to report
                        it.
                    </p>
                    <div
                        class="mt-auto flex justify-end max-sm:sticky max-sm:bottom-0 max-sm:bg-popover max-sm:py-3"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:min-h-11"
                            @click="emit('closed')"
                            >Close</Button
                        >
                    </div>
                </div>

                <form
                    v-else
                    class="flex flex-1 flex-col gap-5 px-4 pb-4"
                    @submit.prevent="submit"
                >
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            What did you see?
                        </legend>
                        <label
                            v-for="choice in observedChoices"
                            :key="choice.value"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-2 hover:bg-muted"
                        >
                            <input
                                v-model="form.observed"
                                type="radio"
                                name="observed"
                                class="size-4"
                                :value="choice.value"
                            />
                            <span class="text-sm">{{ choice.label }}</span>
                        </label>
                        <InputError :message="errorFor('observed')" />
                    </fieldset>

                    <div class="flex flex-col gap-1">
                        <label
                            for="report-difference-rough"
                            class="text-sm font-medium"
                            >About how many? (optional)</label
                        >
                        <div class="flex items-center gap-2">
                            <input
                                id="report-difference-rough"
                                v-model="form.rough_quantity"
                                inputmode="decimal"
                                maxlength="20"
                                class="min-h-11 w-32 rounded-[10px] border border-input bg-field-fill px-3 text-sm text-foreground lg:min-h-9"
                                aria-describedby="report-difference-rough-hint"
                            />
                            <span class="text-sm text-muted-foreground">{{
                                target.unit
                            }}</span>
                        </div>
                        <p
                            id="report-difference-rough-hint"
                            class="text-[12.5px] text-muted-foreground"
                        >
                            Your rough count, if you have one. The counter won't
                            see it.
                        </p>
                        <InputError :message="errorFor('rough_quantity')" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label
                            for="report-difference-note"
                            class="text-sm font-medium"
                            >Note (optional)</label
                        >
                        <Textarea
                            id="report-difference-note"
                            v-model="form.note"
                            :maxlength="NOTE_LIMIT"
                            rows="3"
                            aria-describedby="report-difference-note-hint"
                        />
                        <div
                            id="report-difference-note-hint"
                            class="flex justify-between gap-2 text-[12.5px] text-muted-foreground"
                        >
                            <span>Don't write patient names or details.</span>
                            <span
                                >{{ form.note.length }} / {{ NOTE_LIMIT }}</span
                            >
                        </div>
                        <InputError :message="errorFor('note')" />
                    </div>

                    <InputError
                        :message="
                            errorFor('balance_id') ??
                            errorFor('balance_version') ??
                            errorFor('retry_key')
                        "
                    />

                    <div
                        class="mt-auto flex flex-col gap-2 max-sm:sticky max-sm:bottom-0 max-sm:bg-popover max-sm:py-3"
                    >
                        <p
                            v-if="form.observed === ''"
                            class="text-[12.5px] text-muted-foreground"
                            data-testid="report-difference-pick"
                        >
                            Pick what you saw to continue.
                        </p>
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                class="max-lg:min-h-11"
                                @click="emit('closed')"
                                >Cancel</Button
                            >
                            <Button
                                type="submit"
                                class="max-lg:min-h-11"
                                :disabled="
                                    form.observed === '' || form.processing
                                "
                                >Send for counting</Button
                            >
                        </div>
                    </div>
                </form>
            </template>
        </SheetContent>
    </Sheet>
</template>
