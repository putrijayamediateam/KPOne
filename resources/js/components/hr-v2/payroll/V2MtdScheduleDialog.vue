<script setup lang="ts">
// HR Payroll V2 (b): the yearly MTD schedule dialog. The Classic sheet is `components/hr/payroll/MtdScheduleSheet.vue`;
// both use `useMtdScheduleSheet`, so they send the same request with the same key. KPOne ships no tax number: every
// field starts empty, and someone else checks the schedule before KPOne works out MTD with it.
import { computed } from 'vue';
import { OperationalSelect } from '@/components/ui/select';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import { useMtdScheduleSheet } from '@/composables/hr/useMtdScheduleSheet';
import type { MtdScheduleSheetProps } from '@/composables/hr/useMtdScheduleSheet';

const props = defineProps<MtdScheduleSheetProps>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { errors, failure, form, addBand, removeBand, send, rowError } =
    useMtdScheduleSheet(props, () => emit('update:open', false));

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px] lg:h-9';
const numberClass = `${inputClass} text-right tabular-nums`;
const textareaClass =
    'w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px]';
const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';

const bandFields = [
    { key: 'from', label: 'From, M (RM)' },
    { key: 'to', label: 'To (RM)' },
    { key: 'rate', label: 'Rate, R (%)' },
    { key: 'base_cat13', label: 'Tax on M, cat. 1 & 3 (RM)' },
    { key: 'base_cat2', label: 'Tax on M, cat. 2 (RM)' },
] as const;

const missing = computed(() =>
    form.year.trim() === ''
        ? 'Enter the year to continue.'
        : form.rounding === ''
          ? 'Choose how MTD rounds to continue.'
          : form.minimum.trim() === ''
            ? 'Enter the smallest MTD deducted to continue.'
            : form.sourceNote.trim() === ''
              ? 'Say where the figures came from to continue.'
              : '',
);
</script>

<template>
    <V2Dialog
        :open="open"
        size="tall"
        title="Enter an MTD schedule"
        description="Copy the year's schedule and reliefs from LHDN's published schedule. Someone else checks it before KPOne works out MTD with it, and a checked schedule never changes; a correction is a new schedule."
        :confirm-label="form.processing ? 'Saving…' : 'Enter for checking'"
        :busy="form.processing"
        :confirm-disabled="missing !== ''"
        :confirm-disabled-reason="missing"
        @update:open="(v: boolean) => emit('update:open', v)"
        @confirm="missing === '' && send()"
    >
        <div class="flex flex-col gap-4" data-test="mtd-schedule-sheet">
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div class="grid gap-4 sm:grid-cols-3">
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Year"
                    :error="errors.year"
                    required
                >
                    <input
                        :id="id"
                        v-model="form.year"
                        inputmode="numeric"
                        maxlength="4"
                        :class="inputClass"
                        class="max-w-28 tabular-nums"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                    />
                </V2Field>
                <div class="grid content-start gap-1.5">
                    <label
                        id="v2-mtd-rounding-label"
                        class="text-sm font-medium"
                        >Rounding
                        <span class="text-destructive" aria-hidden="true"
                            >*</span
                        ></label
                    >
                    <OperationalSelect
                        v-model="form.rounding"
                        labelledby="v2-mtd-rounding-label"
                        label="Rounding"
                        placeholder="Choose how MTD rounds"
                        trigger-class="h-11 w-full lg:h-9"
                        :options="rounding"
                        :invalid="!!errors.rounding"
                    />
                    <p
                        v-if="errors.rounding"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.rounding }}
                    </p>
                </div>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Smallest MTD deducted (RM)"
                    hint="A smaller result deducts nothing."
                    :error="errors.minimum"
                    required
                >
                    <input
                        :id="id"
                        v-model="form.minimum"
                        inputmode="decimal"
                        :class="numberClass"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                    />
                </V2Field>
            </div>
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Yearly reliefs (RM)
                    <span class="text-destructive" aria-hidden="true">*</span>
                </legend>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <V2Field
                        v-for="r in reliefs"
                        :key="r.value"
                        v-slot="{ id, describedBy, invalid }"
                        :label="r.label"
                        :error="errors[r.value]"
                    >
                        <input
                            :id="id"
                            v-model="form.reliefs[r.value]"
                            inputmode="decimal"
                            :class="numberClass"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                        />
                    </V2Field>
                </div>
            </fieldset>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Where the figures came from"
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
                />
            </V2Field>
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Chargeable income bands
                    <span class="text-destructive" aria-hidden="true">*</span>
                </legend>
                <p class="text-sm text-muted-foreground">
                    "From" is M, the start of the band. Each band starts one sen
                    after the one above ends; leave "To" empty on the last band
                    for "and above". The tax on M may be below zero, for example
                    -400.00.
                </p>
                <div
                    v-for="(row, i) in form.rows"
                    :key="i"
                    class="flex flex-col gap-3 rounded-[10px] border p-3"
                    data-test="mtd-band"
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
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <V2Field
                            v-for="f in bandFields"
                            :key="f.key"
                            v-slot="{ id, describedBy, invalid }"
                            :label="f.label"
                            :error="rowError(i, f.key)"
                        >
                            <input
                                :id="id"
                                v-model="row[f.key]"
                                inputmode="decimal"
                                :placeholder="
                                    f.key === 'to' && i === form.rows.length - 1
                                        ? 'and above'
                                        : ''
                                "
                                :class="numberClass"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
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
                    v-if="form.rows.length < maxBands"
                    type="button"
                    :class="button"
                    class="self-start"
                    data-test="mtd-add-band"
                    @click="addBand"
                >
                    Add a band
                </button>
            </fieldset>
        </div>
    </V2Dialog>
</template>
