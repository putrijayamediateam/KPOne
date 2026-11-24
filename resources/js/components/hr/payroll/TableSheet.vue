<script setup lang="ts">
// HR-7a statutory banded table (HR-PLAN 14.2): scheme, category, the date it applies from, the rounding rule, where the
// figures came from, and wage bands with an employee and an employer part. Someone else checks it before it counts.
// KPOne ships no statutory number. Each band's error sits under its own row (Daniel).
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ErrorState } from '@/components/ui/state';
import { Textarea } from '@/components/ui/textarea';
import { useTableSheet } from '@/composables/hr/useTableSheet';
import type { TableSheetProps } from '@/composables/hr/useTableSheet';

const props = defineProps<TableSheetProps>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { errors, failure, kinds, form, addBand, removeBand, send, rowError } =
    useTableSheet(props, () => emit('update:open', false));
</script>

<template>
    <Sheet :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <SheetContent
            class="w-full overflow-y-auto sm:max-w-3xl"
            data-test="table-sheet"
        >
            <SheetHeader>
                <SheetTitle>Enter a statutory table</SheetTitle>
                <SheetDescription>
                    Copy the figures from the official table. Someone else
                    checks it before it counts, and a checked table never
                    changes; a correction is a new table.
                </SheetDescription>
            </SheetHeader>
            <form class="flex flex-col gap-4 px-4 pb-6" @submit.prevent="send">
                <ErrorState v-if="failure" :message="failure" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="table-scheme"
                            ><span id="table-scheme-label"
                                >Scheme *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="table-scheme"
                            v-model="form.scheme"
                            labelledby="table-scheme-label"
                            label="Scheme"
                            placeholder="Choose a scheme"
                            trigger-class="max-lg:h-11"
                            :options="schemes"
                            :invalid="!!errors.scheme"
                        />
                        <InputError :message="errors.scheme" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="table-category">Category *</Label>
                        <Input
                            id="table-category"
                            v-model="form.category"
                            maxlength="60"
                            class="max-lg:h-11"
                            aria-describedby="table-category-help"
                            :aria-invalid="!!errors.category"
                        />
                        <p
                            id="table-category-help"
                            class="text-xs text-muted-foreground"
                        >
                            For example "Malaysian below 60" or "Category 1".
                        </p>
                        <InputError :message="errors.category" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="table-from">Applies from *</Label>
                        <DateField
                            id="table-from"
                            v-model="form.effectiveFrom"
                            :aria-invalid="!!errors.effective_from"
                        />
                        <InputError :message="errors.effective_from" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="table-rounding"
                            ><span id="table-rounding-label"
                                >Rounding *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="table-rounding"
                            v-model="form.rounding"
                            labelledby="table-rounding-label"
                            label="Rounding"
                            placeholder="Choose how amounts round"
                            trigger-class="max-lg:h-11"
                            :options="rounding"
                            :invalid="!!errors.rounding"
                        />
                        <p class="text-xs text-muted-foreground">
                            Applied once to each part, as the official table
                            says.
                        </p>
                        <InputError :message="errors.rounding" />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="table-source"
                        >Where the figures came from *</Label
                    >
                    <Textarea
                        id="table-source"
                        v-model="form.sourceNote"
                        maxlength="200"
                        rows="2"
                        aria-describedby="table-source-hint"
                        :aria-invalid="!!errors.source_note"
                    />
                    <p
                        id="table-source-hint"
                        class="text-xs text-muted-foreground"
                        data-test="table-source-hint"
                    >
                        Copy the figures from the official KWSP, PERKESO or HRD
                        Corp schedule, never from memory. Name the schedule and
                        its date. A second person checks the table before it
                        counts.
                    </p>
                    <InputError :message="errors.source_note" />
                </div>

                <fieldset class="flex flex-col gap-3">
                    <legend class="mb-1 text-sm font-medium">
                        Wage bands *
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        Each band starts one sen after the one above ends. Leave
                        "To" empty on the last band for "and above".
                    </p>
                    <div
                        v-for="(row, i) in form.rows"
                        :key="i"
                        class="flex flex-col gap-2 rounded-lg border p-3"
                        data-test="table-band"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium"
                                >Band {{ i + 1 }}</span
                            >
                            <Button
                                v-if="form.rows.length > 1"
                                type="button"
                                variant="ghost"
                                class="min-h-11 lg:min-h-8"
                                :aria-label="`Remove band ${i + 1}`"
                                @click="removeBand(i)"
                                >Remove</Button
                            >
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="grid gap-1">
                                <Label :for="`band-${i}-from`">From (RM)</Label>
                                <Input
                                    :id="`band-${i}-from`"
                                    v-model="row.from"
                                    inputmode="decimal"
                                    class="text-right tabular-nums max-lg:h-11"
                                    :aria-invalid="!!rowError(i, 'from')"
                                />
                                <InputError :message="rowError(i, 'from')" />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`band-${i}-to`">To (RM)</Label>
                                <Input
                                    :id="`band-${i}-to`"
                                    v-model="row.to"
                                    inputmode="decimal"
                                    class="text-right tabular-nums max-lg:h-11"
                                    :placeholder="
                                        i === form.rows.length - 1
                                            ? 'and above'
                                            : ''
                                    "
                                    :aria-invalid="!!rowError(i, 'to')"
                                />
                                <InputError :message="rowError(i, 'to')" />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`band-${i}-employee`"
                                    >Employee</Label
                                >
                                <div class="flex flex-col gap-1">
                                    <OperationalSelect
                                        :id="`band-${i}-employee-kind`"
                                        v-model="row.employee_kind"
                                        :label="`Band ${i + 1} employee part kind`"
                                        trigger-class="w-full max-lg:h-11"
                                        :options="kinds"
                                    />
                                    <Input
                                        :id="`band-${i}-employee`"
                                        v-model="row.employee"
                                        inputmode="decimal"
                                        class="text-right tabular-nums max-lg:h-11"
                                        :aria-invalid="
                                            !!rowError(i, 'employee')
                                        "
                                    />
                                </div>
                                <InputError
                                    :message="rowError(i, 'employee')"
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`band-${i}-employer`"
                                    >Employer</Label
                                >
                                <div class="flex flex-col gap-1">
                                    <OperationalSelect
                                        :id="`band-${i}-employer-kind`"
                                        v-model="row.employer_kind"
                                        :label="`Band ${i + 1} employer part kind`"
                                        trigger-class="w-full max-lg:h-11"
                                        :options="kinds"
                                    />
                                    <Input
                                        :id="`band-${i}-employer`"
                                        v-model="row.employer"
                                        inputmode="decimal"
                                        class="text-right tabular-nums max-lg:h-11"
                                        :aria-invalid="
                                            !!rowError(i, 'employer')
                                        "
                                    />
                                </div>
                                <InputError
                                    :message="rowError(i, 'employer')"
                                />
                            </div>
                        </div>
                    </div>
                    <InputError :message="errors.rows" />
                    <Button
                        v-if="form.rows.length < maxRows"
                        type="button"
                        variant="outline"
                        class="self-start max-lg:min-h-11"
                        data-test="table-add-band"
                        @click="addBand"
                        >Add a band</Button
                    >
                </fieldset>

                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="form.processing"
                    data-test="table-send"
                >
                    {{ form.processing ? 'Saving…' : 'Enter for checking' }}
                </Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
