<script setup lang="ts">
// HR Payroll V2 (b): the statutory table dialog. The Classic sheet is `components/hr/payroll/TableSheet.vue`; both use
// `useTableSheet`, so they send the same request with the same key. KPOne ships no statutory number: the clinic copies
// the official table and someone else checks it before it counts. Each band's error sits under its own row (Daniel).
import { computed } from 'vue';
import { OperationalSelect } from '@/components/ui/select';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import { useTableSheet } from '@/composables/hr/useTableSheet';
import type { TableSheetProps } from '@/composables/hr/useTableSheet';

const props = defineProps<TableSheetProps>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { errors, failure, kinds, form, addBand, removeBand, send, rowError } =
    useTableSheet(props, () => emit('update:open', false));

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px] lg:h-9';
const numberClass = `${inputClass} text-right tabular-nums`;
const textareaClass =
    'w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px]';
const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';

const missing = computed(() =>
    form.scheme === ''
        ? 'Choose a scheme to continue.'
        : form.category.trim() === ''
          ? 'Enter the category to continue.'
          : form.effectiveFrom === ''
            ? 'Choose the date it applies from to continue.'
            : form.rounding === ''
              ? 'Choose how amounts round to continue.'
              : form.sourceNote.trim() === ''
                ? 'Say where the figures came from to continue.'
                : '',
);
</script>

<template>
    <V2Dialog
        :open="open"
        size="tall"
        title="Enter a statutory table"
        description="Copy the figures from the official table. Someone else checks it before it counts, and a checked table never changes; a correction is a new table."
        :confirm-label="form.processing ? 'Saving…' : 'Enter for checking'"
        :busy="form.processing"
        :confirm-disabled="missing !== ''"
        :confirm-disabled-reason="missing"
        @update:open="(v: boolean) => emit('update:open', v)"
        @confirm="missing === '' && send()"
    >
        <div class="flex flex-col gap-4" data-test="table-sheet">
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <label
                        id="v2-table-scheme-label"
                        class="text-sm font-medium"
                        >Scheme
                        <span class="text-destructive" aria-hidden="true"
                            >*</span
                        ></label
                    >
                    <OperationalSelect
                        v-model="form.scheme"
                        labelledby="v2-table-scheme-label"
                        label="Scheme"
                        placeholder="Choose a scheme"
                        trigger-class="h-11 w-full lg:h-9"
                        :options="schemes"
                        :invalid="!!errors.scheme"
                    />
                    <p
                        v-if="errors.scheme"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.scheme }}
                    </p>
                </div>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Category"
                    hint='For example "Malaysian below 60" or "Category 1".'
                    :error="errors.category"
                    required
                >
                    <input
                        :id="id"
                        v-model="form.category"
                        maxlength="60"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                    />
                </V2Field>
                <V2DateField
                    v-model="form.effectiveFrom"
                    label="Applies from"
                    :error="errors.effective_from"
                    required
                />
                <div class="grid gap-1.5">
                    <label
                        id="v2-table-rounding-label"
                        class="text-sm font-medium"
                        >Rounding
                        <span class="text-destructive" aria-hidden="true"
                            >*</span
                        ></label
                    >
                    <OperationalSelect
                        v-model="form.rounding"
                        labelledby="v2-table-rounding-label"
                        label="Rounding"
                        placeholder="Choose how amounts round"
                        trigger-class="h-11 w-full lg:h-9"
                        :options="rounding"
                        :invalid="!!errors.rounding"
                    />
                    <p class="text-sm text-muted-foreground">
                        Applied once to each part, as the official table says.
                    </p>
                    <p
                        v-if="errors.rounding"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.rounding }}
                    </p>
                </div>
            </div>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Where the figures came from"
                hint="Copy the figures from the official KWSP, PERKESO or HRD Corp schedule, never from memory. Name the schedule and its date. A second person checks the table before it counts."
                :error="errors.source_note"
                required
            >
                <textarea
                    :id="id"
                    v-model="form.sourceNote"
                    maxlength="200"
                    rows="2"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    data-test="table-source-hint"
                />
            </V2Field>

            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Wage bands
                    <span class="text-destructive" aria-hidden="true">*</span>
                </legend>
                <p class="text-sm text-muted-foreground">
                    Each band starts one sen after the one above ends. Leave
                    "To" empty on the last band for "and above".
                </p>
                <div
                    v-for="(row, i) in form.rows"
                    :key="i"
                    class="flex flex-col gap-3 rounded-[10px] border p-3"
                    data-test="table-band"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium"
                            >Band {{ i + 1 }}</span
                        >
                        <button
                            v-if="form.rows.length > 1"
                            type="button"
                            :class="button"
                            class="text-destructive"
                            :aria-label="`Remove band ${i + 1}`"
                            @click="removeBand(i)"
                        >
                            Remove
                        </button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="From (RM)"
                            :error="rowError(i, 'from')"
                        >
                            <input
                                :id="id"
                                v-model="row.from"
                                inputmode="decimal"
                                :class="numberClass"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="To (RM)"
                            :error="rowError(i, 'to')"
                        >
                            <input
                                :id="id"
                                v-model="row.to"
                                inputmode="decimal"
                                :placeholder="
                                    i === form.rows.length - 1
                                        ? 'and above'
                                        : ''
                                "
                                :class="numberClass"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
                        <div
                            v-for="part in ['employee', 'employer'] as const"
                            :key="part"
                            class="grid gap-1.5"
                        >
                            <span
                                :id="`v2-band-${i}-${part}-label`"
                                class="text-sm font-medium"
                                >{{
                                    part === 'employee'
                                        ? 'Employee'
                                        : 'Employer'
                                }}</span
                            >
                            <OperationalSelect
                                v-model="row[`${part}_kind`]"
                                :label="`Band ${i + 1} ${part} part kind`"
                                trigger-class="h-11 w-full lg:h-9"
                                :options="kinds"
                            />
                            <input
                                v-model="row[part]"
                                inputmode="decimal"
                                :class="numberClass"
                                :aria-labelledby="`v2-band-${i}-${part}-label`"
                                :aria-invalid="!!rowError(i, part)"
                            />
                            <p
                                v-if="rowError(i, part)"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ rowError(i, part) }}
                            </p>
                        </div>
                    </div>
                </div>
                <p
                    v-if="errors.rows"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.rows }}
                </p>
                <button
                    v-if="form.rows.length < maxRows"
                    type="button"
                    :class="button"
                    class="self-start"
                    data-test="table-add-band"
                    @click="addBand"
                >
                    Add a band
                </button>
            </fieldset>
        </div>
    </V2Dialog>
</template>
