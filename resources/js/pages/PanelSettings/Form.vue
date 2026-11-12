<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

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
    claimFollowUpDays?: number;
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
    claim_follow_up_days: props.panel?.claimFollowUpDays ?? 30,
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

// Same reading as v-model.number: a number when the text parses, else the text.
const numberFrom = (value: string | number) => {
    const parsed = parseFloat(String(value));

    return (Number.isNaN(parsed) ? value : parsed) as number;
};

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
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <div>
            <Button
                variant="ghost"
                size="sm"
                as-child
                class="-ml-2 max-lg:h-11"
            >
                <Link href="/checkout-settings">
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Back to Checkout settings
                </Link>
            </Button>
        </div>
        <form class="grid gap-5" @submit.prevent="submit">
            <PageHeader :title="editing ? 'Edit panel' : 'Add panel'">
                <template #actions>
                    <div class="flex gap-2 max-lg:hidden">
                        <Button variant="outline" as-child>
                            <Link href="/checkout-settings">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing"
                            >Save</Button
                        >
                    </div>
                </template>
            </PageHeader>
            <InputError
                :message="(form.errors as Record<string, string>).panel"
            />

            <div class="grid gap-5 lg:grid-cols-2">
                <section
                    class="grid content-start gap-4 rounded-xl border bg-card p-4 md:p-5"
                >
                    <h2 class="text-base font-semibold">Panel information</h2>
                    <fieldset class="grid gap-2 text-sm">
                        <legend class="mb-1 text-[13px] font-medium">
                            Panel type
                        </legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                class="flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:border-ring has-focus-visible:ring-[3px] has-focus-visible:ring-ring/40"
                            >
                                <input
                                    v-model="form.panel_type"
                                    type="radio"
                                    value="tpa"
                                    class="size-4 accent-primary"
                                />
                                Third Party Administrator
                            </label>
                            <label
                                class="flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:border-ring has-focus-visible:ring-[3px] has-focus-visible:ring-ring/40"
                            >
                                <input
                                    v-model="form.panel_type"
                                    type="radio"
                                    value="direct_company"
                                    class="size-4 accent-primary"
                                />
                                Direct company
                            </label>
                        </div>
                        <InputError :message="form.errors.panel_type" />
                    </fieldset>
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Company registration number
                        <span class="font-normal text-muted-foreground"
                            >(12-digit SSM ID)</span
                        >
                        <Input
                            v-model="form.company_registration_number"
                            inputmode="numeric"
                            maxlength="12"
                            placeholder="e.g. 202201234567"
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <InputError
                            :message="form.errors.company_registration_number"
                        />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Company name
                        <Input
                            v-model="form.company_name"
                            maxlength="255"
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <InputError :message="form.errors.company_name" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Panel name *
                        <Input
                            v-model="form.name"
                            maxlength="255"
                            required
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <InputError :message="form.errors.name" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Panel code *
                        <Input
                            v-model="form.code"
                            maxlength="80"
                            :disabled="editing"
                            required
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <span
                            v-if="editing"
                            class="font-normal text-muted-foreground"
                        >
                            The code identifies the Panel on records and cannot
                            be changed.
                        </span>
                        <InputError :message="form.errors.code" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Income Tax Number (TIN) for e-invoice
                        <Input
                            v-model="form.income_tax_number"
                            maxlength="20"
                            placeholder="Example: C1234567"
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <InputError :message="form.errors.income_tax_number" />
                    </label>
                </section>

                <section
                    class="grid content-start gap-4 rounded-xl border bg-card p-4 md:p-5"
                >
                    <h2 class="text-base font-semibold">Contact</h2>
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Person in charge
                        <Input
                            v-model="form.person_in_charge"
                            maxlength="255"
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <InputError :message="form.errors.person_in_charge" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Phone number
                        <Input
                            v-model="form.phone"
                            maxlength="32"
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <InputError :message="form.errors.phone" />
                    </label>
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Email
                        <Input
                            v-model="form.email"
                            type="email"
                            maxlength="255"
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <InputError :message="form.errors.email" />
                    </label>
                    <fieldset class="grid gap-3 sm:grid-cols-2">
                        <legend class="mb-1 text-[13px] font-medium">
                            Address
                        </legend>
                        <label
                            class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground sm:col-span-2"
                        >
                            <span class="font-normal">Address line 1</span>
                            <Input
                                v-model="form.address_line_1"
                                maxlength="255"
                                aria-label="Address line 1"
                                class="rounded-lg max-lg:h-11 max-lg:text-base"
                            />
                        </label>
                        <label
                            class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground sm:col-span-2"
                        >
                            <span class="font-normal">Address line 2</span>
                            <Input
                                v-model="form.address_line_2"
                                maxlength="255"
                                aria-label="Address line 2"
                                class="rounded-lg max-lg:h-11 max-lg:text-base"
                            />
                        </label>
                        <label
                            class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                        >
                            <span class="font-normal">Postcode</span>
                            <Input
                                v-model="form.postcode"
                                maxlength="16"
                                aria-label="Postcode"
                                class="rounded-lg max-lg:h-11 max-lg:text-base"
                            />
                        </label>
                        <label
                            class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                        >
                            <span class="font-normal">City</span>
                            <Input
                                v-model="form.city"
                                maxlength="100"
                                aria-label="City"
                                class="rounded-lg max-lg:h-11 max-lg:text-base"
                            />
                        </label>
                        <label
                            class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                        >
                            <span class="font-normal">State</span>
                            <Input
                                v-model="form.state"
                                maxlength="100"
                                aria-label="State"
                                class="rounded-lg max-lg:h-11 max-lg:text-base"
                            />
                        </label>
                        <label
                            class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                        >
                            <span class="font-normal">Country</span>
                            <Input
                                v-model="form.country"
                                maxlength="100"
                                aria-label="Country"
                                class="rounded-lg max-lg:h-11 max-lg:text-base"
                            />
                        </label>
                    </fieldset>
                </section>

                <section
                    class="grid content-start gap-4 rounded-xl border bg-card p-4 md:p-5"
                >
                    <div>
                        <h2 class="text-base font-semibold">
                            Panel verification
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Provide a link for users to review panel
                            information.
                        </p>
                    </div>
                    <fieldset class="grid gap-2 sm:grid-cols-2">
                        <legend class="sr-only">
                            Panel verification method
                        </legend>
                        <label
                            class="flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:border-ring has-focus-visible:ring-[3px] has-focus-visible:ring-ring/40"
                        >
                            <input
                                v-model="form.verification_mode"
                                type="radio"
                                value="url"
                                class="size-4 accent-primary"
                            />
                            URL to panel platform
                        </label>
                        <label
                            class="flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:border-ring has-focus-visible:ring-[3px] has-focus-visible:ring-ring/40"
                        >
                            <input
                                v-model="form.verification_mode"
                                type="radio"
                                value="remark"
                                class="size-4 accent-primary"
                            />
                            Manual remark
                        </label>
                    </fieldset>
                    <label
                        v-if="form.verification_mode === 'url'"
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Verification link
                        <Input
                            v-model="form.verification_url"
                            type="url"
                            maxlength="500"
                            placeholder="https://"
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <InputError :message="form.errors.verification_url" />
                    </label>
                    <label
                        v-else
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Manual remark
                        <Input
                            v-model="form.verification_remark"
                            maxlength="500"
                            placeholder="e.g. Call the hotline to verify"
                            class="rounded-lg max-lg:h-11 max-lg:text-base"
                        />
                        <span
                            class="text-right text-xs font-normal text-muted-foreground tabular-nums"
                        >
                            {{ form.verification_remark.length }} / 500
                        </span>
                        <InputError
                            :message="form.errors.verification_remark"
                        />
                    </label>
                </section>

                <section
                    class="grid content-start gap-4 rounded-xl border bg-card p-4 md:p-5"
                >
                    <div>
                        <h2 class="text-base font-semibold">Panel rule</h2>
                        <p class="text-sm text-muted-foreground">
                            Set the operational terms for this panel.
                        </p>
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-[13px] font-medium">
                            Claim submission due date
                        </legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                class="flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:border-ring has-focus-visible:ring-[3px] has-focus-visible:ring-ring/40"
                            >
                                <input
                                    type="radio"
                                    class="size-4 accent-primary"
                                    :checked="!scheduled"
                                    @change="setScheduled(false)"
                                />
                                Days after the visit
                            </label>
                            <label
                                class="flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:border-ring has-focus-visible:ring-[3px] has-focus-visible:ring-ring/40"
                            >
                                <input
                                    type="radio"
                                    class="size-4 accent-primary"
                                    :checked="scheduled"
                                    @change="setScheduled(true)"
                                />
                                Daily, weekly or monthly
                            </label>
                        </div>
                        <div
                            v-if="!scheduled"
                            class="flex flex-wrap items-center gap-2 text-sm"
                        >
                            <span class="whitespace-nowrap">Submit</span>
                            <Input
                                :model-value="form.claim_due_days"
                                aria-label="Claim due days after the visit"
                                type="number"
                                min="0"
                                max="365"
                                class="w-24 rounded-lg max-lg:h-11 max-lg:text-base"
                                @update:model-value="
                                    (value) =>
                                        (form.claim_due_days =
                                            numberFrom(value))
                                "
                            />
                            <span class="whitespace-nowrap"
                                >days after the visit</span
                            >
                        </div>
                        <InputError :message="form.errors.claim_due_days" />
                        <div
                            v-if="scheduled"
                            class="grid grid-cols-[auto_1fr] items-center gap-2 text-sm sm:flex sm:flex-wrap"
                        >
                            <span>Submit</span>
                            <select
                                v-model="form.claim_due_rule"
                                class="h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm font-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:h-11 max-lg:text-base sm:w-auto dark:bg-field-fill/30"
                                aria-label="Frequency"
                                @change="form.claim_due_offset = ''"
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                            <span>by</span>
                            <Input
                                v-model="form.claim_due_time"
                                type="time"
                                class="w-full rounded-lg max-lg:h-11 max-lg:text-base sm:w-32"
                                aria-label="Time"
                            />
                            <select
                                v-model="form.claim_due_offset"
                                class="col-start-2 h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm font-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:h-11 max-lg:text-base sm:w-auto dark:bg-field-fill/30"
                                aria-label="Day"
                            >
                                <option value="" disabled>Select day</option>
                                <template
                                    v-if="form.claim_due_rule === 'daily'"
                                >
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
                    <label
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                    >
                        Follow-up days
                        <span
                            class="flex flex-wrap items-center gap-2 text-sm font-normal text-foreground"
                        >
                            <span class="whitespace-nowrap"
                                >Follow up a claim after</span
                            >
                            <Input
                                :model-value="form.claim_follow_up_days"
                                type="number"
                                min="1"
                                max="365"
                                class="w-24 rounded-lg max-lg:h-11 max-lg:text-base"
                                aria-label="Follow-up days"
                                @update:model-value="
                                    (value) =>
                                        (form.claim_follow_up_days =
                                            numberFrom(value))
                                "
                            />
                            <span class="whitespace-nowrap"
                                >days with no new action</span
                            >
                        </span>
                        <span class="font-normal text-muted-foreground">
                            A claim of this Panel appears on the Follow-up list
                            once this many days pass without a query, decision,
                            payment, note or photo.
                        </span>
                        <InputError
                            :message="form.errors.claim_follow_up_days"
                        />
                    </label>
                    <p class="text-sm text-muted-foreground">
                        Prices: a Panel uses the default Panel price book unless
                        it has its own. Manage them in Pricing.
                    </p>
                </section>
            </div>

            <div class="flex gap-2 lg:hidden">
                <Button variant="outline" as-child class="h-11 flex-1">
                    <Link href="/checkout-settings">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    class="h-11 flex-1"
                    :disabled="form.processing"
                    >Save</Button
                >
            </div>
        </form>
    </main>
</template>
