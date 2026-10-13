<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type PanelDetail = {
    id: number;
    code: string;
    name: string;
    isActive: boolean;
    lockVersion: number;
    panelType: string | null;
    companyRegistrationNumber: string | null;
    companyName: string | null;
    personInCharge: string | null;
    phone: string | null;
    email: string | null;
    addressLine1: string | null;
    addressLine2: string | null;
    postcode: string | null;
    city: string | null;
    state: string | null;
    country: string | null;
    verificationMode: 'url' | 'remark';
    verificationUrl: string | null;
    verificationRemark: string | null;
    claimDueDays: number;
    claimDueRule: 'days_after_visit' | 'daily' | 'weekly' | 'monthly';
    claimDueTime: string | null;
    claimDueOffset: number | null;
    incomeTaxNumber: string | null;
};

const props = defineProps<{ panel: PanelDetail | null }>();
const editing = props.panel !== null;

const form = useForm({
    code: props.panel?.code ?? '',
    name: props.panel?.name ?? '',
    panel_type: props.panel?.panelType ?? '',
    company_registration_number: props.panel?.companyRegistrationNumber ?? '',
    company_name: props.panel?.companyName ?? '',
    person_in_charge: props.panel?.personInCharge ?? '',
    phone: props.panel?.phone ?? '',
    email: props.panel?.email ?? '',
    address_line_1: props.panel?.addressLine1 ?? '',
    address_line_2: props.panel?.addressLine2 ?? '',
    postcode: props.panel?.postcode ?? '',
    city: props.panel?.city ?? '',
    state: props.panel?.state ?? '',
    country: props.panel?.country ?? 'Malaysia',
    verification_mode: props.panel?.verificationMode ?? 'url',
    verification_url: props.panel?.verificationUrl ?? '',
    verification_remark: props.panel?.verificationRemark ?? '',
    claim_due_rule: props.panel?.claimDueRule ?? 'days_after_visit',
    claim_due_days: props.panel?.claimDueDays ?? 30,
    claim_due_time: props.panel?.claimDueTime ?? '',
    claim_due_offset: props.panel?.claimDueOffset ?? ('' as number | ''),
    income_tax_number: props.panel?.incomeTaxNumber ?? '',
    lock_version: props.panel?.lockVersion ?? 1,
});

const scheduled = computed(() => form.claim_due_rule !== 'days_after_visit');
const setScheduled = (on: boolean) => {
    form.claim_due_rule = on ? 'daily' : 'days_after_visit';
    form.claim_due_offset = '';
};
const weekdays = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];
const monthDays = [
    ...Array.from({ length: 28 }, (_, i) => ({
        value: i + 1,
        label: `Day ${i + 1} of next month`,
    })),
    { value: 0, label: 'Last day of the visit month' },
];

const submit = () => {
    const blankToNull = (value: string) => value.trim() || null;
    form.transform((data) => ({
        ...data,
        panel_type: blankToNull(data.panel_type),
        company_registration_number: blankToNull(
            data.company_registration_number,
        ),
        company_name: blankToNull(data.company_name),
        person_in_charge: blankToNull(data.person_in_charge),
        phone: blankToNull(data.phone),
        email: blankToNull(data.email),
        address_line_1: blankToNull(data.address_line_1),
        address_line_2: blankToNull(data.address_line_2),
        postcode: blankToNull(data.postcode),
        city: blankToNull(data.city),
        state: blankToNull(data.state),
        country: blankToNull(data.country),
        verification_url: blankToNull(data.verification_url),
        verification_remark: blankToNull(data.verification_remark),
        claim_due_days:
            data.claim_due_rule === 'days_after_visit'
                ? data.claim_due_days
                : null,
        claim_due_time:
            data.claim_due_rule === 'days_after_visit'
                ? null
                : blankToNull(data.claim_due_time),
        claim_due_offset:
            data.claim_due_rule === 'days_after_visit' ||
            data.claim_due_offset === ''
                ? null
                : data.claim_due_offset,
        income_tax_number: blankToNull(data.income_tax_number),
    }));

    if (editing) {
        form.patch(`/checkout-settings/panels/${props.panel?.id}`);
    } else {
        form.post('/checkout-settings/panels');
    }
};
</script>

<template>
    <Head :title="editing ? 'Edit panel' : 'Add panel'" />
    <form
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
        @submit.prevent="submit"
    >
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Button variant="outline" size="sm" as-child>
                    <Link href="/checkout-settings" aria-label="Back">←</Link>
                </Button>
                <h1 class="text-xl font-semibold">
                    {{ editing ? 'Edit panel' : 'Add panel' }}
                </h1>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" as-child>
                    <Link href="/checkout-settings">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">Save</Button>
            </div>
        </div>
        <InputError :message="(form.errors as Record<string, string>).panel" />

        <div class="grid gap-6 lg:grid-cols-[1fr_1fr_1fr]">
            <section class="grid content-start gap-4 lg:col-span-1">
                <div>
                    <h2 class="text-sm font-semibold">Panel information</h2>
                    <p class="text-xs text-muted-foreground">
                        Fill in panel information below.
                    </p>
                </div>
                <fieldset class="grid gap-2 text-sm">
                    <legend class="mb-1 text-xs font-medium">Panel type</legend>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="form.panel_type"
                            type="radio"
                            value="tpa"
                        />
                        Third Party Administrator
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="form.panel_type"
                            type="radio"
                            value="direct_company"
                        />
                        Direct company
                    </label>
                    <InputError :message="form.errors.panel_type" />
                </fieldset>
                <label class="grid gap-1 text-xs">
                    Company registration number (12-digit SSM ID)
                    <Input
                        v-model="form.company_registration_number"
                        inputmode="numeric"
                        maxlength="12"
                        placeholder="e.g. 202201234567"
                    />
                    <InputError
                        :message="form.errors.company_registration_number"
                    />
                </label>
                <label class="grid gap-1 text-xs">
                    Company name
                    <Input v-model="form.company_name" maxlength="255" />
                    <InputError :message="form.errors.company_name" />
                </label>
                <label class="grid gap-1 text-xs">
                    Panel name
                    <Input v-model="form.name" maxlength="255" required />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="grid gap-1 text-xs">
                    Panel code
                    <Input
                        v-model="form.code"
                        maxlength="80"
                        :disabled="editing"
                        required
                    />
                    <span v-if="editing" class="text-muted-foreground">
                        The code identifies the Panel on records and cannot be
                        changed.
                    </span>
                    <InputError :message="form.errors.code" />
                </label>
                <label class="grid gap-1 text-xs">
                    Person in charge
                    <Input v-model="form.person_in_charge" maxlength="255" />
                    <InputError :message="form.errors.person_in_charge" />
                </label>
            </section>

            <section class="grid content-start gap-4">
                <label class="grid gap-1 text-xs">
                    Phone number
                    <Input v-model="form.phone" maxlength="32" />
                    <InputError :message="form.errors.phone" />
                </label>
                <label class="grid gap-1 text-xs">
                    Email
                    <Input v-model="form.email" type="email" maxlength="255" />
                    <InputError :message="form.errors.email" />
                </label>
                <fieldset class="grid gap-2 text-xs">
                    <legend class="mb-1 font-medium">Address</legend>
                    <Input
                        v-model="form.address_line_1"
                        maxlength="255"
                        placeholder="Address line 1"
                    />
                    <Input
                        v-model="form.address_line_2"
                        maxlength="255"
                        placeholder="Address line 2"
                    />
                    <Input
                        v-model="form.postcode"
                        maxlength="16"
                        placeholder="Postcode"
                    />
                    <Input
                        v-model="form.city"
                        maxlength="100"
                        placeholder="City"
                    />
                    <Input
                        v-model="form.state"
                        maxlength="100"
                        placeholder="State"
                    />
                    <Input
                        v-model="form.country"
                        maxlength="100"
                        placeholder="Country"
                    />
                </fieldset>
            </section>

            <section class="grid content-start gap-4">
                <div>
                    <h2 class="text-sm font-semibold">Panel verification</h2>
                    <p class="text-xs text-muted-foreground">
                        Provide a link for users to review panel information.
                    </p>
                </div>
                <fieldset class="flex gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <input
                            v-model="form.verification_mode"
                            type="radio"
                            value="url"
                        />
                        URL to panel platform
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="form.verification_mode"
                            type="radio"
                            value="remark"
                        />
                        Manual remark
                    </label>
                </fieldset>
                <label
                    v-if="form.verification_mode === 'url'"
                    class="grid gap-1 text-xs"
                >
                    Verification link
                    <Input
                        v-model="form.verification_url"
                        type="url"
                        maxlength="500"
                        placeholder="https://"
                    />
                    <InputError :message="form.errors.verification_url" />
                </label>
                <label v-else class="grid gap-1 text-xs">
                    Manual remark
                    <Input
                        v-model="form.verification_remark"
                        maxlength="500"
                        placeholder="e.g. Call the hotline to verify"
                    />
                    <span class="text-muted-foreground">
                        {{ form.verification_remark.length }} / 500
                    </span>
                    <InputError :message="form.errors.verification_remark" />
                </label>

                <div>
                    <h2 class="text-sm font-semibold">Panel rule</h2>
                    <p class="text-xs text-muted-foreground">
                        Set the operational terms for this panel.
                    </p>
                </div>
                <fieldset class="grid gap-2 text-xs">
                    <legend class="mb-1 font-medium">
                        Claim submission due date
                    </legend>
                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-2">
                            <input
                                type="radio"
                                :checked="!scheduled"
                                @change="setScheduled(false)"
                            />
                            Days after the visit
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                type="radio"
                                :checked="scheduled"
                                @change="setScheduled(true)"
                            />
                            Daily, weekly or monthly
                        </label>
                    </div>
                    <span
                        v-if="!scheduled"
                        class="flex items-center gap-2 text-sm"
                    >
                        Submit
                        <Input
                            v-model.number="form.claim_due_days"
                            type="number"
                            min="0"
                            max="365"
                            class="w-24"
                        />
                        days after the visit
                    </span>
                    <InputError :message="form.errors.claim_due_days" />
                    <div
                        v-if="scheduled"
                        class="flex flex-wrap items-center gap-2 text-sm"
                    >
                        Submit
                        <select
                            v-model="form.claim_due_rule"
                            class="h-9 rounded-md border bg-background px-2"
                            aria-label="Frequency"
                            @change="form.claim_due_offset = ''"
                        >
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                        by
                        <Input
                            v-model="form.claim_due_time"
                            type="time"
                            class="w-32"
                            aria-label="Time"
                        />
                        <select
                            v-model="form.claim_due_offset"
                            class="h-9 rounded-md border bg-background px-2"
                            aria-label="Day"
                        >
                            <option value="" disabled>Select day</option>
                            <template v-if="form.claim_due_rule === 'daily'">
                                <option :value="0">Same day</option>
                                <option :value="1">Next day</option>
                                <option :value="2">2 days after</option>
                                <option :value="3">3 days after</option>
                            </template>
                            <template
                                v-else-if="form.claim_due_rule === 'weekly'"
                            >
                                <option
                                    v-for="(name, index) in weekdays"
                                    :key="name"
                                    :value="index + 1"
                                >
                                    Next {{ name }}
                                </option>
                            </template>
                            <template v-else>
                                <option
                                    v-for="day in monthDays"
                                    :key="day.value"
                                    :value="day.value"
                                >
                                    {{ day.label }}
                                </option>
                            </template>
                        </select>
                    </div>
                    <InputError :message="form.errors.claim_due_time" />
                    <InputError :message="form.errors.claim_due_offset" />
                </fieldset>
                <p class="text-xs text-muted-foreground">
                    Prices: a Panel uses the default Panel price book unless it
                    has its own. Manage them in Pricing.
                </p>
                <label class="grid gap-1 text-xs">
                    Income Tax Number (TIN) for e-invoice
                    <Input
                        v-model="form.income_tax_number"
                        maxlength="20"
                        placeholder="Example: C1234567"
                    />
                    <InputError :message="form.errors.income_tax_number" />
                </label>
            </section>
        </div>
    </form>
</template>
