<script setup lang="ts">
// HR-7d yearly MTD schedule (HR-PLAN 18.2): the year, rounding, the smallest MTD deducted, the yearly reliefs and the
// EPF relief limit, and chargeable-income bands with a rate and the tax on M for categories 1 and 3 and for category 2.
// Someone else checks it before KPOne uses it. KPOne ships no tax number: every field starts empty.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
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
import type { Option } from './types';

const props = defineProps<{
    open: boolean;
    rounding: Option[];
    reliefs: Option[];
    maxBands: number;
    year: number;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.payroll ?? errors.value.records);
type Band = {
    from: string;
    to: string;
    rate: string;
    base_cat13: string;
    base_cat2: string;
};
const band = (from = ''): Band => ({
    from,
    to: '',
    rate: '',
    base_cat13: '',
    base_cat2: '',
});
const emptyReliefs = () =>
    Object.fromEntries(props.reliefs.map((r) => [r.value, ''])) as Record<
        string,
        string
    >;
const form = reactive({
    year: '',
    rounding: '',
    minimum: '',
    reliefs: {} as Record<string, string>,
    sourceNote: '',
    rows: [band('0.00')] as Band[],
    key: '',
    processing: false,
});
watch(
    () => props.open,
    (open) => {
        if (open) {
            Object.assign(form, {
                year: String(props.year),
                rounding: '',
                minimum: '',
                reliefs: emptyReliefs(),
                sourceNote: '',
                rows: [band('0.00')],
                key: crypto.randomUUID(),
            });
        }
    },
);
/** The next band starts one sen after the last one ends, so bands run on without a gap. */
const addBand = () => {
    const last = form.rows[form.rows.length - 1];
    const match = last?.to.replace(/,/g, '').match(/^(\d+)(?:\.(\d{1,2}))?$/);
    let next = '';

    if (match) {
        const sen =
            Number(match[1]) * 100 +
            Number((match[2] ?? '').padEnd(2, '0')) +
            1;
        next = `${Math.trunc(sen / 100)}.${String(sen % 100).padStart(2, '0')}`;
    }

    form.rows.push(band(next));
};
const removeBand = (index: number) => {
    form.rows = form.rows.filter((_, i) => i !== index);
};
const send = () => {
    form.processing = true;
    router.post(
        '/hr/payroll/mtd-schedules',
        {
            year: form.year,
            rounding: form.rounding,
            minimum: form.minimum,
            ...form.reliefs,
            source_note: form.sourceNote,
            rows: form.rows,
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onFinish: () => (form.processing = false),
        },
    );
};
const rowError = (i: number, field: string) =>
    errors.value[`rows.${i}.${field}`];
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
                    Copy the year's schedule and reliefs as the clinic's
                    accountant gives them. Someone else checks it before KPOne
                    works out MTD with it, and a checked schedule never changes;
                    a correction is a new schedule.
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
                                    >Tax on M, categories 1 and 3 (RM)</Label
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
                                    >Tax on M, category 2 (RM)</Label
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
