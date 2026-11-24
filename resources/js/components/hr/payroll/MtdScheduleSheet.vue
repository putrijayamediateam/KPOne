<script setup lang="ts">
// HR-7d yearly MTD schedule (HR-PLAN 18.2): the year, rounding, the smallest MTD deducted, the yearly reliefs and the
// EPF relief limit, and chargeable-income bands with a rate and the tax on M for categories 1 and 3 and for category 2.
// Someone else checks it before KPOne uses it. KPOne ships no tax number: every field starts empty.
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { useMtdScheduleSheet } from '@/composables/hr/useMtdScheduleSheet';
import type { MtdScheduleSheetProps } from '@/composables/hr/useMtdScheduleSheet';

const props = defineProps<MtdScheduleSheetProps>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { errors, failure, form, addBand, removeBand, send, rowError } =
    useMtdScheduleSheet(props, () => emit('update:open', false));
</script>

<template>
    <Sheet :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <SheetContent
            class="w-full overflow-y-auto sm:max-w-3xl"
            data-test="mtd-schedule-sheet"
        >
            <SheetHeader>
                <SheetTitle>Enter an MTD schedule</SheetTitle>
                <SheetDescription>
                    Copy the year's schedule and reliefs from LHDN's published
                    schedule. Someone else checks it before KPOne works out MTD
                    with it, and a checked schedule never changes; a correction
                    is a new schedule.
                </SheetDescription>
            </SheetHeader>
            <form class="flex flex-col gap-4 px-4 pb-6" @submit.prevent="send">
                <ErrorState v-if="failure" :message="failure" />
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="mtd-year">Year *</Label>
                        <Input
                            id="mtd-year"
                            v-model="form.year"
                            inputmode="numeric"
                            maxlength="4"
                            class="w-28 tabular-nums max-lg:h-11"
                            :aria-invalid="!!errors.year"
                        />
                        <InputError :message="errors.year" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="mtd-rounding"
                            ><span id="mtd-rounding-label"
                                >Rounding *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="mtd-rounding"
                            v-model="form.rounding"
                            labelledby="mtd-rounding-label"
                            label="Rounding"
                            placeholder="Choose how MTD rounds"
                            trigger-class="max-lg:h-11"
                            :options="rounding"
                            :invalid="!!errors.rounding"
                        />
                        <InputError :message="errors.rounding" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="mtd-minimum"
                            >Smallest MTD deducted (RM) *</Label
                        >
                        <Input
                            id="mtd-minimum"
                            v-model="form.minimum"
                            inputmode="decimal"
                            class="text-right tabular-nums max-lg:h-11"
                            aria-describedby="mtd-minimum-help"
                            :aria-invalid="!!errors.minimum"
                        />
                        <p
                            id="mtd-minimum-help"
                            class="text-xs text-muted-foreground"
                        >
                            A smaller result deducts nothing.
                        </p>
                        <InputError :message="errors.minimum" />
                    </div>
                </div>

                <fieldset class="flex flex-col gap-3">
                    <legend class="mb-1 text-sm font-medium">
                        Yearly reliefs (RM) *
                    </legend>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="r in reliefs"
                            :key="r.value"
                            class="grid gap-1"
                        >
                            <Label :for="`mtd-${r.value}`">{{ r.label }}</Label>
                            <Input
                                :id="`mtd-${r.value}`"
                                v-model="form.reliefs[r.value]"
                                inputmode="decimal"
                                class="text-right tabular-nums max-lg:h-11"
                                :aria-invalid="!!errors[r.value]"
                            />
                            <InputError :message="errors[r.value]" />
                        </div>
                    </div>
                </fieldset>

                <div class="grid gap-1.5">
                    <Label for="mtd-source"
                        >Where the figures came from *</Label
                    >
                    <Textarea
                        id="mtd-source"
                        v-model="form.sourceNote"
                        maxlength="200"
                        rows="2"
                        :aria-invalid="!!errors.source_note"
                    />
                    <InputError :message="errors.source_note" />
                </div>

                <fieldset class="flex flex-col gap-3">
                    <legend class="mb-1 text-sm font-medium">
                        Chargeable income bands *
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        "From" is M, the start of the band. Each band starts one
                        sen after the one above ends; leave "To" empty on the
                        last band for "and above". The tax on M may be below
                        zero, for example -400.00.
                    </p>
                    <div
                        v-for="(row, i) in form.rows"
                        :key="i"
                        class="flex flex-col gap-2 rounded-lg border p-3"
                        data-test="mtd-band"
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
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                            <div class="grid gap-1">
                                <Label :for="`mtd-band-${i}-from`"
                                    >From, M (RM)</Label
                                >
                                <Input
                                    :id="`mtd-band-${i}-from`"
                                    v-model="row.from"
                                    inputmode="decimal"
                                    class="text-right tabular-nums max-lg:h-11"
                                    :aria-invalid="!!rowError(i, 'from')"
                                />
                                <InputError :message="rowError(i, 'from')" />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`mtd-band-${i}-to`">To (RM)</Label>
                                <Input
                                    :id="`mtd-band-${i}-to`"
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
                                <Label :for="`mtd-band-${i}-rate`"
                                    >Rate, R (%)</Label
                                >
                                <Input
                                    :id="`mtd-band-${i}-rate`"
                                    v-model="row.rate"
                                    inputmode="decimal"
                                    class="text-right tabular-nums max-lg:h-11"
                                    :aria-invalid="!!rowError(i, 'rate')"
                                />
                                <InputError :message="rowError(i, 'rate')" />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`mtd-band-${i}-b13`"
                                    >Tax on M, cat. 1 &amp; 3 (RM)</Label
                                >
                                <Input
                                    :id="`mtd-band-${i}-b13`"
                                    v-model="row.base_cat13"
                                    inputmode="decimal"
                                    class="text-right tabular-nums max-lg:h-11"
                                    :aria-invalid="!!rowError(i, 'base_cat13')"
                                />
                                <InputError
                                    :message="rowError(i, 'base_cat13')"
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`mtd-band-${i}-b2`"
                                    >Tax on M, cat. 2 (RM)</Label
                                >
                                <Input
                                    :id="`mtd-band-${i}-b2`"
                                    v-model="row.base_cat2"
                                    inputmode="decimal"
                                    class="text-right tabular-nums max-lg:h-11"
                                    :aria-invalid="!!rowError(i, 'base_cat2')"
                                />
                                <InputError
                                    :message="rowError(i, 'base_cat2')"
                                />
                            </div>
                        </div>
                    </div>
                    <InputError :message="errors.rows" />
                    <Button
                        v-if="form.rows.length < maxBands"
                        type="button"
                        variant="outline"
                        class="self-start max-lg:min-h-11"
                        data-test="mtd-add-band"
                        @click="addBand"
                        >Add a band</Button
                    >
                </fieldset>

                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="form.processing"
                    data-test="mtd-send"
                >
                    {{ form.processing ? 'Saving…' : 'Enter for checking' }}
                </Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
