<script setup lang="ts">
// HR-7a pay profile (HR-PLAN 14.2): pay group, pay type, basic salary or hourly rate, fixed allowances, the schemes
// that apply and the date it applies from. Someone else confirms it. Errors show under each field, all in one submit.
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
import RmInput from './RmInput.vue';
import type { Option, Person } from './types';
import { rmText } from './types';

const props = defineProps<{
    open: boolean;
    person: Person | null;
    groups: Option[];
    allowanceTypes: Option[];
    categories: { epf: string[]; socso: string[] };
    maxItems: number;
    today: string;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.payroll ?? errors.value.records);
const schemes = [
    { key: 'epf', label: 'EPF' },
    { key: 'socso', label: 'SOCSO' },
    { key: 'eis', label: 'EIS' },
    { key: 'mtd', label: 'MTD (PCB)' },
    { key: 'hrd', label: 'HRD levy' },
] as const;
type SchemeKey = (typeof schemes)[number]['key'];

const form = reactive({
    payGroup: '',
    payType: 'monthly',
    rate: '',
    effectiveFrom: '',
    schemes: {
        epf: false,
        socso: false,
        eis: false,
        mtd: false,
        hrd: false,
    } as Record<SchemeKey, boolean>,
    epfCategory: '',
    socsoCategory: '',
    items: [] as { type: string; amount: string }[],
    key: '',
    processing: false,
});
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        const current = props.person?.current ?? null;
        const pick = (label: string) =>
            current?.schemes.find(
                (s) => s === label || s.startsWith(`${label} (`),
            );
        const category = (label: string) =>
            pick(label)?.match(/\((.*)\)$/)?.[1] ?? '';
        Object.assign(form, {
            payGroup:
                props.groups.find((g) => g.label === current?.payGroup)
                    ?.value ??
                (props.groups.length === 1 ? props.groups[0].value : ''),
            payType: current?.payType ?? 'monthly',
            rate: rmText(current?.rateSen ?? null),
            effectiveFrom: '',
            schemes: {
                epf: !!pick('EPF'),
                socso: !!pick('SOCSO'),
                eis: !!pick('EIS'),
                mtd: !!pick('MTD'),
                hrd: !!pick('HRD levy'),
            },
            epfCategory: category('EPF'),
            socsoCategory: category('SOCSO'),
            items: (current?.items ?? [])
                .map((i) => ({
                    type:
                        props.allowanceTypes.find((t) => t.label === i.name)
                            ?.value ?? '',
                    amount: rmText(i.amountSen),
                }))
                .filter((i) => i.type !== ''),
            key: crypto.randomUUID(),
        });
    },
);
const payTypes: Option[] = [
    { value: 'monthly', label: 'Monthly salary' },
    { value: 'hourly', label: 'Hourly (paid on worked hours)' },
];
const addItem = () => form.items.push({ type: '', amount: '' });
const removeItem = (index: number) => {
    form.items = form.items.filter((_, i) => i !== index);
};
const send = () => {
    if (!props.person) {
        return;
    }

    form.processing = true;
    router.post(
        '/hr/payroll/profiles',
        {
            worker_id: props.person.id,
            pay_group_id: form.payGroup,
            pay_type: form.payType,
            rate: form.rate,
            effective_from: form.effectiveFrom,
            epf: form.schemes.epf,
            epf_category: form.schemes.epf ? form.epfCategory : '',
            socso: form.schemes.socso,
            socso_category: form.schemes.socso ? form.socsoCategory : '',
            eis: form.schemes.eis,
            mtd: form.schemes.mtd,
            hrd: form.schemes.hrd,
            items: form.items.map((i) => ({
                item_type_id: i.type,
                amount: i.amount,
            })),
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onFinish: () => (form.processing = false),
        },
    );
};
</script>

<template>
    <Sheet :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <SheetContent
            class="w-full overflow-y-auto sm:max-w-lg"
            data-test="profile-sheet"
        >
            <SheetHeader>
                <SheetTitle>{{
                    person ? `Pay profile for ${person.name}` : 'Pay profile'
                }}</SheetTitle>
                <SheetDescription>
                    Someone else confirms it before it counts. The profile in
                    force now stays until then. Bank details come from the staff
                    file.
                </SheetDescription>
            </SheetHeader>
            <form class="flex flex-col gap-4 px-4 pb-6" @submit.prevent="send">
                <ErrorState v-if="failure" :message="failure" />
                <div class="grid gap-1.5">
                    <Label for="profile-group"
                        ><span id="profile-group-label"
                            >Pay group *</span
                        ></Label
                    >
                    <OperationalSelect
                        id="profile-group"
                        v-model="form.payGroup"
                        labelledby="profile-group-label"
                        label="Pay group"
                        placeholder="Choose a pay group"
                        trigger-class="max-lg:h-11"
                        :options="groups"
                        :invalid="!!errors.pay_group_id"
                    />
                    <InputError :message="errors.pay_group_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="profile-type"
                        ><span id="profile-type-label">Pay type *</span></Label
                    >
                    <OperationalSelect
                        id="profile-type"
                        v-model="form.payType"
                        labelledby="profile-type-label"
                        label="Pay type"
                        trigger-class="max-lg:h-11"
                        :options="payTypes"
                        :invalid="!!errors.pay_type"
                    />
                    <InputError :message="errors.pay_type" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="profile-rate">{{
                        form.payType === 'hourly'
                            ? 'Hourly rate *'
                            : 'Basic monthly salary *'
                    }}</Label>
                    <RmInput
                        id="profile-rate"
                        v-model="form.rate"
                        describedby="profile-rate-help"
                        :invalid="!!errors.rate"
                    />
                    <p
                        id="profile-rate-help"
                        class="text-xs text-muted-foreground"
                    >
                        In RM. For example 2,500.00
                    </p>
                    <InputError :message="errors.rate" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="profile-from">Applies from *</Label>
                    <Input
                        id="profile-from"
                        v-model="form.effectiveFrom"
                        type="date"
                        class="w-44 max-lg:h-11"
                        :aria-invalid="!!errors.effective_from"
                    />
                    <InputError :message="errors.effective_from" />
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Schemes that apply
                    </legend>
                    <template v-for="s in schemes" :key="s.key">
                        <label
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                        >
                            <input
                                v-model="form.schemes[s.key]"
                                type="checkbox"
                                class="size-4"
                                :data-test="`profile-scheme-${s.key}`"
                            />
                            {{ s.label }}
                        </label>
                        <div
                            v-if="
                                (s.key === 'epf' || s.key === 'socso') &&
                                form.schemes[s.key]
                            "
                            class="grid gap-1.5 pl-6"
                        >
                            <Label :for="`profile-${s.key}-category`"
                                >{{ s.label }} category *</Label
                            >
                            <Input
                                v-if="s.key === 'epf'"
                                id="profile-epf-category"
                                v-model="form.epfCategory"
                                maxlength="60"
                                list="profile-epf-categories"
                                class="max-lg:h-11"
                                :aria-invalid="!!errors.epf_category"
                            />
                            <Input
                                v-else
                                id="profile-socso-category"
                                v-model="form.socsoCategory"
                                maxlength="60"
                                list="profile-socso-categories"
                                class="max-lg:h-11"
                                :aria-invalid="!!errors.socso_category"
                            />
                            <p class="text-xs text-muted-foreground">
                                As named on its statutory table.
                            </p>
                            <InputError
                                :message="
                                    s.key === 'epf'
                                        ? errors.epf_category
                                        : errors.socso_category
                                "
                            />
                        </div>
                    </template>
                    <datalist id="profile-epf-categories">
                        <option
                            v-for="c in categories.epf"
                            :key="c"
                            :value="c"
                        />
                    </datalist>
                    <datalist id="profile-socso-categories">
                        <option
                            v-for="c in categories.socso"
                            :key="c"
                            :value="c"
                        />
                    </datalist>
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Fixed allowances
                    </legend>
                    <p
                        v-if="!allowanceTypes.length"
                        class="text-xs text-muted-foreground"
                    >
                        Add an earnings pay item type in Settings to add
                        allowances.
                    </p>
                    <div
                        v-for="(item, i) in form.items"
                        :key="i"
                        class="flex flex-wrap items-start gap-2"
                        data-test="profile-item"
                    >
                        <div class="grid min-w-40 flex-1 gap-1">
                            <OperationalSelect
                                :id="`profile-item-${i}`"
                                v-model="item.type"
                                :label="`Allowance ${i + 1} type`"
                                placeholder="Choose a type"
                                trigger-class="max-lg:h-11"
                                :options="allowanceTypes"
                                :invalid="!!errors[`items.${i}.item_type_id`]"
                            />
                            <InputError
                                :message="errors[`items.${i}.item_type_id`]"
                            />
                        </div>
                        <div class="grid gap-1">
                            <RmInput
                                :id="`profile-item-${i}-amount`"
                                v-model="item.amount"
                                :label="`Allowance ${i + 1} amount in RM`"
                                :invalid="!!errors[`items.${i}.amount`]"
                            />
                            <InputError
                                :message="errors[`items.${i}.amount`]"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            class="min-h-11 lg:min-h-9"
                            :aria-label="`Remove allowance ${i + 1}`"
                            @click="removeItem(i)"
                            >Remove</Button
                        >
                    </div>
                    <InputError :message="errors.items" />
                    <Button
                        v-if="
                            allowanceTypes.length &&
                            form.items.length < maxItems
                        "
                        type="button"
                        variant="outline"
                        class="self-start max-lg:min-h-11"
                        data-test="profile-add-item"
                        @click="addItem"
                        >Add an allowance</Button
                    >
                </fieldset>

                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="form.processing"
                    data-test="profile-send"
                >
                    {{ form.processing ? 'Saving…' : 'Enter for confirmation' }}
                </Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
