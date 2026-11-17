<script setup lang="ts">
// HR Payroll V2 (b): the pay profile dialog. The Classic sheet is `components/hr/payroll/ProfileSheet.vue`; both use
// `useProfileSheet`, so they send the same request with the same key. Someone else confirms the profile before it
// counts, and the profile in force stays until then. Errors show under each field, all in one send.
import { computed } from 'vue';
import { OperationalSelect } from '@/components/ui/select';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import V2SwitchRow from '@/components/v2/V2SwitchRow.vue';
import { useProfileSheet } from '@/composables/hr/useProfileSheet';
import type { ProfileSheetProps } from '@/composables/hr/useProfileSheet';

const props = defineProps<ProfileSheetProps>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const {
    errors,
    failure,
    schemes,
    form,
    worksOutMtd,
    payTypes,
    addItem,
    removeItem,
    send,
} = useProfileSheet(props, () => emit('update:open', false));

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px] lg:h-9';
const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';

// What is missing before it can be sent, in words (the server still checks everything).
const missing = computed(() =>
    form.payGroup === ''
        ? 'Choose a pay group to continue.'
        : form.rate.trim() === ''
          ? form.payType === 'hourly'
              ? 'Enter the hourly rate to continue.'
              : 'Enter the basic monthly salary to continue.'
          : form.effectiveFrom === ''
            ? 'Choose the date it applies from to continue.'
            : '',
);
const mtdOptions = computed(() => [
    { value: 'none', label: 'None: type MTD each run' },
    ...props.mtdCategories,
]);
</script>

<template>
    <V2Dialog
        :open="open"
        size="tall"
        :title="person ? `Pay profile for ${person.name}` : 'Pay profile'"
        description="Someone else confirms it before it counts. The profile in force now stays until then. Bank details come from the staff file."
        :confirm-label="form.processing ? 'Saving…' : 'Enter for confirmation'"
        :busy="form.processing"
        :confirm-disabled="missing !== ''"
        :confirm-disabled-reason="missing"
        @update:open="(v: boolean) => emit('update:open', v)"
        @confirm="missing === '' && send()"
    >
        <div class="flex flex-col gap-4" data-test="profile-sheet">
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div class="grid gap-1.5">
                <label id="v2-profile-group-label" class="text-sm font-medium"
                    >Pay group
                    <span class="text-destructive" aria-hidden="true"
                        >*</span
                    ></label
                >
                <OperationalSelect
                    v-model="form.payGroup"
                    labelledby="v2-profile-group-label"
                    label="Pay group"
                    placeholder="Choose a pay group"
                    trigger-class="h-11 w-full lg:h-9"
                    :options="groups"
                    :invalid="!!errors.pay_group_id"
                />
                <p
                    v-if="errors.pay_group_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.pay_group_id }}
                </p>
            </div>
            <div class="grid gap-1.5">
                <label id="v2-profile-type-label" class="text-sm font-medium"
                    >Pay type
                    <span class="text-destructive" aria-hidden="true"
                        >*</span
                    ></label
                >
                <OperationalSelect
                    v-model="form.payType"
                    labelledby="v2-profile-type-label"
                    label="Pay type"
                    trigger-class="h-11 w-full lg:h-9"
                    :options="payTypes"
                    :invalid="!!errors.pay_type"
                />
                <p
                    v-if="errors.pay_type"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.pay_type }}
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <V2MoneyInput
                    v-model="form.rate"
                    :label="
                        form.payType === 'hourly'
                            ? 'Hourly rate'
                            : 'Basic monthly salary'
                    "
                    :hint="
                        form.payType === 'hourly'
                            ? 'For example 15.00'
                            : 'For example 2,500.00'
                    "
                    :error="errors.rate"
                    required
                />
                <V2DateField
                    v-model="form.effectiveFrom"
                    label="Applies from"
                    :error="errors.effective_from"
                    required
                />
            </div>

            <fieldset class="flex flex-col gap-1">
                <legend class="mb-1 text-sm font-medium">
                    Schemes that apply
                </legend>
                <template v-for="s in schemes" :key="s.key">
                    <V2SwitchRow
                        v-model="form.schemes[s.key]"
                        :label="s.label"
                        :data-test="`profile-scheme-${s.key}`"
                    />
                    <div
                        v-if="
                            (s.key === 'epf' || s.key === 'socso') &&
                            form.schemes[s.key]
                        "
                        class="mb-2 border-l-2 border-border pl-4"
                    >
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            :label="`${s.label} category`"
                            hint="As named on its statutory table."
                            :error="
                                s.key === 'epf'
                                    ? errors.epf_category
                                    : errors.socso_category
                            "
                            required
                        >
                            <input
                                v-if="s.key === 'epf'"
                                :id="id"
                                v-model="form.epfCategory"
                                maxlength="60"
                                list="v2-profile-epf-categories"
                                :class="inputClass"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                            <input
                                v-else
                                :id="id"
                                v-model="form.socsoCategory"
                                maxlength="60"
                                list="v2-profile-socso-categories"
                                :class="inputClass"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
                    </div>
                    <div
                        v-if="s.key === 'mtd' && form.schemes.mtd"
                        class="mb-2 flex flex-col gap-3 border-l-2 border-border pl-4"
                        data-test="profile-mtd-details"
                    >
                        <div class="grid gap-1.5">
                            <label
                                id="v2-profile-mtd-category-label"
                                class="text-sm font-medium"
                                >Tax category</label
                            >
                            <OperationalSelect
                                v-model="form.mtdCategory"
                                labelledby="v2-profile-mtd-category-label"
                                label="Tax category"
                                trigger-class="h-11 w-full lg:h-9"
                                :options="mtdOptions"
                                :invalid="!!errors.mtd_category"
                            />
                            <p class="text-sm text-muted-foreground">
                                With a category, KPOne works MTD out on monthly
                                runs once the year's MTD schedule is checked.
                            </p>
                            <p
                                v-if="errors.mtd_category"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ errors.mtd_category }}
                            </p>
                        </div>
                        <template v-if="worksOutMtd">
                            <V2Field
                                v-slot="{ id, describedBy, invalid }"
                                label="Children for relief"
                                :error="errors.mtd_children"
                            >
                                <input
                                    :id="id"
                                    v-model="form.mtdChildren"
                                    inputmode="numeric"
                                    maxlength="2"
                                    :class="inputClass"
                                    class="max-w-24 text-right tabular-nums"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                />
                            </V2Field>
                            <V2SwitchRow
                                v-model="form.mtdDisabledSelf"
                                label="Disabled individual"
                                data-test="profile-mtd-disabled"
                            />
                            <V2SwitchRow
                                v-if="form.mtdCategory === '2'"
                                v-model="form.mtdDisabledSpouse"
                                label="Disabled spouse"
                                data-test="profile-mtd-disabled-spouse"
                            />
                        </template>
                    </div>
                </template>
                <datalist id="v2-profile-epf-categories">
                    <option v-for="c in categories.epf" :key="c" :value="c" />
                </datalist>
                <datalist id="v2-profile-socso-categories">
                    <option v-for="c in categories.socso" :key="c" :value="c" />
                </datalist>
            </fieldset>

            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Fixed allowances
                </legend>
                <p
                    v-if="!allowanceTypes.length"
                    class="text-sm text-muted-foreground"
                >
                    Add an earnings pay item type in Settings to add allowances.
                </p>
                <div
                    v-for="(item, i) in form.items"
                    :key="i"
                    class="grid gap-3 rounded-[10px] border p-3 sm:grid-cols-[1fr_12rem_auto] sm:items-start"
                    data-test="profile-item"
                >
                    <div class="grid gap-1.5">
                        <label
                            :id="`v2-profile-item-${i}-label`"
                            class="text-sm font-medium"
                            >Allowance {{ i + 1 }} type</label
                        >
                        <OperationalSelect
                            v-model="item.type"
                            :labelledby="`v2-profile-item-${i}-label`"
                            :label="`Allowance ${i + 1} type`"
                            placeholder="Choose a type"
                            trigger-class="h-11 w-full lg:h-9"
                            :options="allowanceTypes"
                            :invalid="!!errors[`items.${i}.item_type_id`]"
                        />
                        <p
                            v-if="errors[`items.${i}.item_type_id`]"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errors[`items.${i}.item_type_id`] }}
                        </p>
                    </div>
                    <V2MoneyInput
                        v-model="item.amount"
                        :label="`Allowance ${i + 1} amount`"
                        :error="errors[`items.${i}.amount`]"
                    />
                    <button
                        type="button"
                        :class="button"
                        class="text-destructive sm:mt-7"
                        :aria-label="`Remove allowance ${i + 1}`"
                        @click="removeItem(i)"
                    >
                        Remove
                    </button>
                </div>
                <p
                    v-if="errors.items"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.items }}
                </p>
                <button
                    v-if="allowanceTypes.length && form.items.length < maxItems"
                    type="button"
                    :class="button"
                    class="self-start"
                    data-test="profile-add-item"
                    @click="addItem"
                >
                    Add an allowance
                </button>
            </fieldset>
        </div>
    </V2Dialog>
</template>
