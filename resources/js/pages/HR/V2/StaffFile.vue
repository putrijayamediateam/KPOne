<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { dateLabel, statusTone } from '@/components/hr/records/types';
import V2ChecklistTab from '@/components/hr/records/V2ChecklistTab.vue';
import V2DocumentList from '@/components/hr/records/V2DocumentList.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2MaskedValue from '@/components/v2/V2MaskedValue.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useStaffFilePage } from '@/composables/hr/useStaffFilePage';
import type { StaffFilePageProps } from '@/composables/hr/useStaffFilePage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { revealValue } from '@/lib/hr-show-value';

/**
 * Version 2 staff file (shown only in V2; Classic is `pages/HR/StaffFile.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `useStaffFilePage` (and the checklist
 * composable), which Classic uses too, so this page sends none of its own except the audited Show. Sensitive values
 * arrive masked and stay masked: each Show is the same audited request as Classic's (`lib/hr-show-value`), the server
 * records the view first and only then returns the value, and the value is never kept, prefetched or put in props. A
 * new bank account still cannot be confirmed until the checker has pressed Show on it. Documents open only through
 * their existing audited link when pressed.
 */
const props = defineProps<StaffFilePageProps>();

const {
    errors,
    w,
    signIn,
    askSignIn,
    cancelSignIn,
    tabs,
    selectTab,
    notGiven,
    timeLabel,
    fieldWords,
    period,
    openPeriod,
    needsEditReason,
    savePeriod,
    position,
    openPosition,
    savePosition,
    personal,
    openPersonal,
    savePersonal,
    personalCorrections,
    bankCorrections,
    correctionDecision,
    startCorrectionDecision,
    sendCorrectionDecision,
    upload,
    openUpload,
    onFile,
    sendUpload,
    removal,
    startRemoval,
    sendRemoval,
    bankForm,
    openBank,
    saveBank,
    bankDecision,
    waitingShown,
    startBankDecision,
    sendBankDecision,
    bankDialog,
} = useStaffFilePage(props);

usePageCrumbs(() => [
    { title: 'Staff files', href: '/hr/records?tab=files' },
    { title: props.file.worker.name },
]);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const textareaClass =
    'w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';

const shownUrl = computed(() => `/hr/records/${w.value.id}/show-value`);
// The audited Show: the same request as Classic's MaskedValue, resolved to the value for V2MaskedValue.
const reveal =
    (field: string, extra: Record<string, string> = {}, onShown?: () => void) =>
    async () => {
        const value = await revealValue(shownUrl.value, field, extra);
        onShown?.();

        return value;
    };
const spokenEnding = (label: string, last4: string) =>
    `${label}, ending ${last4.split('').join(' ')}, hidden`;

const title = computed(() => w.value.name);
const description = computed(() =>
    [w.value.role, w.value.typeLabel].filter((p) => p).join(' · '),
);
const tabsV2 = computed(() => tabs);
const addressParts = [
    { key: 'line1', label: 'Address line 1' },
    { key: 'line2', label: 'Address line 2 (optional)' },
    { key: 'city', label: 'City' },
    { key: 'state', label: 'State' },
    { key: 'postcode', label: 'Postcode' },
    { key: 'country', label: 'Country' },
] as const;
const identityKinds = [
    { value: '', label: 'Keep what is recorded' },
    { value: 'ic', label: 'IC (MyKad)' },
    { value: 'passport', label: 'Passport' },
];
const departmentOptions = computed(() => [
    { value: '', label: 'None' },
    ...props.file.options.departments.map((d) => ({
        value: String(d.id),
        label: d.name,
    })),
]);
const reportsToOptions = computed(() => [
    { value: '', label: 'Nobody' },
    ...props.file.workers.map((x) => ({ value: x.id, label: x.name })),
]);

const periodMissing = computed(() => {
    if (period.type === '') {
        return 'Choose the type to continue.';
    }

    if (period.startsOn === '') {
        return 'Choose the start date to continue.';
    }

    if (period.endsOn !== '' && period.endReason === '') {
        return 'Choose why it ended to continue.';
    }

    return needsEditReason.value && period.editReason === ''
        ? 'Choose why this is being changed to continue.'
        : '';
});
const positionMissing = computed(() => {
    if (position.title.trim() === '') {
        return 'Enter the title to continue.';
    }

    return position.fromDate === ''
        ? 'Choose the date it applies from to continue.'
        : '';
});
const bankMissing = computed(() =>
    bankForm.bankName.trim() === '' ||
    bankForm.holder.trim() === '' ||
    bankForm.account.trim() === ''
        ? 'Fill in the bank name, holder and account number to continue.'
        : '',
);
const uploadMissing = computed(() => {
    if (upload.category === '') {
        return 'Choose the type to continue.';
    }

    return upload.file ? '' : 'Choose the file to continue.';
});
const removalMissing = computed(() =>
    removal.reason === '' ? 'Pick a reason to continue.' : '',
);
const bankDecisionMissing = computed(() =>
    bankDecision.decision === 'reject' && bankDecision.reason === ''
        ? 'Pick a reason to continue.'
        : '',
);
const correctionMissing = computed(() =>
    correctionDecision.decision === 'reject' && correctionDecision.reason === ''
        ? 'Pick a reason to continue.'
        : '',
);
const showError = computed(
    () =>
        (errors.value.records ||
            errors.value.bank ||
            errors.value.correction) &&
        !period.open &&
        !position.open &&
        !personal.open &&
        !bankForm.open &&
        !bankDecision.open &&
        !correctionDecision.correction,
);
const bankChangeLabel = computed(() =>
    props.file.bank.confirmed ? 'Change bank details' : 'Enter bank details',
);
const canEnterBank = computed(
    () =>
        props.file.canManage &&
        !props.file.bank.waiting &&
        w.value.kind === 'staff',
);
</script>

<template>
    <Head :title="`${w.name} · Staff file`" />
    <PageFrame>
        <V2WorkLayout :title="title" :description="description">
            <template v-if="file.canManage" #actions>
                <button
                    v-if="file.tab === 'employment'"
                    type="button"
                    :class="primaryButton"
                    data-test="add-period"
                    @click="openPeriod(null)"
                >
                    Add employment period
                </button>
                <button
                    v-else-if="file.tab === 'personal' && w.kind === 'staff'"
                    type="button"
                    :class="primaryButton"
                    data-test="edit-personal"
                    @click="openPersonal"
                >
                    Edit
                </button>
                <button
                    v-else-if="file.tab === 'bank' && canEnterBank"
                    type="button"
                    :class="primaryButton"
                    data-test="enter-bank"
                    @click="openBank"
                >
                    {{ bankChangeLabel }}
                </button>
                <button
                    v-else-if="file.tab === 'documents'"
                    type="button"
                    :class="primaryButton"
                    data-test="add-document"
                    @click="openUpload"
                >
                    Add a document
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <div class="flex flex-wrap items-center gap-2">
                        <V2Chip
                            :tone="statusTone(w.status)"
                            :label="w.statusLabel"
                        />
                        <V2Chip
                            v-if="w.kind === 'locum'"
                            tone="neutral"
                            :label="
                                file.signIn?.state === 'linked'
                                    ? 'Locum with a KPOne sign-in'
                                    : 'Locum, no KPOne sign-in'
                            "
                        />
                    </div>
                    <p
                        v-if="file.manageRefusal"
                        class="text-sm text-muted-foreground"
                        data-test="manage-refusal"
                    >
                        {{ file.manageRefusal }}
                    </p>
                    <p
                        v-if="file.isSelf"
                        class="rounded-[10px] border bg-card p-3 text-sm"
                        data-test="self-note"
                    >
                        This is your own file. Someone else manages it; use My
                        HR details for your contact details and corrections.
                    </p>
                    <div
                        v-if="file.attention.length"
                        class="rounded-[14px] border border-warning/40 bg-warning/10 p-3 text-sm"
                        data-test="attention"
                    >
                        <p class="font-medium">
                            {{
                                file.attention.length === 1
                                    ? 'Needs attention'
                                    : `${file.attention.length} things need attention`
                            }}
                        </p>
                        <ul class="mt-1 list-disc pl-5">
                            <li v-for="reason in file.attention" :key="reason">
                                {{ reason }}
                            </li>
                        </ul>
                    </div>

                    <V2Tabs
                        :model-value="file.tab"
                        :tabs="tabsV2"
                        ariaLabel="Staff file sections"
                        @update:model-value="selectTab"
                    />
                    <p
                        v-if="showError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.records ?? errors.bank ?? errors.correction }}
                    </p>

                    <!-- Employment -->
                    <section
                        v-if="file.tab === 'employment'"
                        aria-labelledby="v2-employment-title"
                        class="flex flex-col gap-4"
                    >
                        <div
                            v-if="file.signIn"
                            :class="cardClass"
                            data-test="locum-sign-in"
                        >
                            <h2 class="text-base font-semibold">
                                KPOne sign-in
                            </h2>
                            <template v-if="file.signIn.state === 'none'">
                                <p class="text-sm text-muted-foreground">
                                    A locum has no KPOne sign-in unless HR asks
                                    for one. With a sign-in they see only their
                                    own approved shifts: no patient, clinical or
                                    pay details.
                                </p>
                                <form
                                    v-if="file.canManage"
                                    class="flex flex-col gap-3"
                                    @submit.prevent="askSignIn"
                                >
                                    <V2Field
                                        v-slot="{ id, describedBy }"
                                        label="Email the locum will sign in with"
                                        hint="The account administrator creates the account on the Staff page and links it here. HR creates no account."
                                        :error="errors.email"
                                    >
                                        <input
                                            :id="id"
                                            v-model="signIn.email"
                                            type="email"
                                            autocomplete="off"
                                            :class="inputClass"
                                            :aria-describedby="describedBy"
                                        />
                                    </V2Field>
                                    <div
                                        class="flex flex-wrap items-center gap-3"
                                    >
                                        <button
                                            type="submit"
                                            :class="button"
                                            :disabled="
                                                signIn.processing ||
                                                signIn.email.trim() === ''
                                            "
                                            data-test="ask-sign-in"
                                        >
                                            {{
                                                signIn.processing
                                                    ? 'Asking…'
                                                    : 'Ask for a sign-in'
                                            }}
                                        </button>
                                        <span
                                            v-if="signIn.email.trim() === ''"
                                            class="text-sm text-muted-foreground"
                                            >Enter the email to continue.</span
                                        >
                                    </div>
                                </form>
                            </template>
                            <template v-else-if="file.signIn.state === 'open'">
                                <p class="text-sm">
                                    {{ file.signIn.label }}. Asked on
                                    {{ file.signIn.at }} for
                                    {{ file.signIn.email }}.
                                </p>
                                <div v-if="file.canManage">
                                    <button
                                        type="button"
                                        :class="dangerButton"
                                        data-test="cancel-sign-in"
                                        @click="signIn.cancelOpen = true"
                                    >
                                        Cancel request
                                    </button>
                                </div>
                            </template>
                            <p v-else class="text-sm">
                                {{ file.signIn.label }} as
                                {{ file.signIn.accountName }}, linked on
                                {{ file.signIn.at }}.
                            </p>
                        </div>

                        <h2
                            id="v2-employment-title"
                            class="text-base font-semibold"
                        >
                            Employment periods
                        </h2>
                        <div
                            v-if="!file.periods.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No employment period yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Add the first period with its start date and
                                type.
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="p in file.periods"
                                :key="p.id"
                                :class="`${cardClass} sm:flex-row sm:items-start sm:justify-between`"
                                data-test="period"
                            >
                                <div class="min-w-0 text-sm">
                                    <p class="font-medium">{{ p.typeLabel }}</p>
                                    <p>
                                        From {{ dateLabel(p.startsOn) }}
                                        <template v-if="p.endsOn"
                                            >to
                                            {{ dateLabel(p.endsOn) }}</template
                                        >
                                    </p>
                                    <p
                                        v-if="p.probationEndsOn"
                                        class="text-muted-foreground"
                                    >
                                        Probation to
                                        {{ dateLabel(p.probationEndsOn) }}
                                        <template v-if="p.confirmedOn">
                                            · confirmed
                                            {{
                                                dateLabel(p.confirmedOn)
                                            }}</template
                                        >
                                    </p>
                                    <p
                                        v-if="p.endReasonLabel"
                                        class="text-muted-foreground"
                                    >
                                        Ended: {{ p.endReasonLabel
                                        }}<template v-if="p.hasNote">
                                            (note kept)</template
                                        >
                                    </p>
                                </div>
                                <button
                                    v-if="file.canManage"
                                    type="button"
                                    :class="button"
                                    @click="openPeriod(p)"
                                >
                                    {{ p.endsOn ? 'Change' : 'Change or end' }}
                                </button>
                            </li>
                        </ul>
                        <p class="text-xs text-muted-foreground">
                            Ending a period changes nothing on the KPOne
                            account. Ask the account administrator to deactivate
                            it.
                        </p>

                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <h2 class="text-base font-semibold">
                                Position history
                            </h2>
                            <button
                                v-if="file.canManage"
                                type="button"
                                :class="button"
                                data-test="add-position"
                                @click="openPosition"
                            >
                                Add position
                            </button>
                        </div>
                        <div
                            v-if="!file.positions.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No position recorded.</p>
                            <p class="text-sm text-muted-foreground">
                                Add a title and the date it applies from. The
                                directory job title is not changed.
                            </p>
                        </div>
                        <ul
                            v-else
                            class="flex flex-col divide-y rounded-[14px] border bg-card"
                        >
                            <li
                                v-for="(pos, i) in file.positions"
                                :key="i"
                                class="flex flex-col gap-0.5 p-4 text-sm"
                            >
                                <span class="font-medium">{{ pos.title }}</span>
                                <span class="text-muted-foreground">
                                    From {{ dateLabel(pos.fromDate) }}
                                    <template v-if="pos.department">
                                        · {{ pos.department }}</template
                                    >
                                    <template v-if="pos.reportsTo">
                                        · reports to
                                        {{ pos.reportsTo }}</template
                                    >
                                </span>
                            </li>
                        </ul>

                        <details
                            class="rounded-[14px] border bg-card p-4 text-sm"
                        >
                            <summary
                                class="min-h-11 cursor-pointer content-center font-semibold lg:min-h-0"
                            >
                                Recent changes
                            </summary>
                            <p
                                v-if="!file.changes.length"
                                class="mt-2 text-muted-foreground"
                            >
                                No changes yet.
                            </p>
                            <ul v-else class="mt-2 flex flex-col gap-1">
                                <li v-for="(c, i) in file.changes" :key="i">
                                    {{ timeLabel(c.at) }} · {{ c.by }} ·
                                    {{ c.area }}: {{ fieldWords(c.fields) }}
                                </li>
                            </ul>
                            <p class="mt-2 text-xs text-muted-foreground">
                                Field names only. Values are never kept in the
                                change list.
                            </p>
                        </details>
                    </section>

                    <!-- Personal -->
                    <section
                        v-if="file.tab === 'personal'"
                        aria-labelledby="v2-personal-title"
                        class="flex flex-col gap-4"
                    >
                        <h2
                            id="v2-personal-title"
                            class="text-base font-semibold"
                        >
                            Personal details
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Sensitive values stay hidden until someone presses
                            Show. Each Show is recorded first, and the value
                            hides again after a minute, on Hide, or when you
                            leave the page.
                        </p>
                        <dl
                            :class="`${cardClass} text-sm sm:grid sm:grid-cols-2`"
                        >
                            <template v-if="w.kind === 'locum'">
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Contact phone
                                    </dt>
                                    <dd>
                                        <V2MaskedValue
                                            v-if="w.hasLocumPhone"
                                            label="Contact phone"
                                            masked="Hidden"
                                            :reveal="reveal('locum_phone')"
                                        />
                                        <span v-else>{{ notGiven }}</span>
                                    </dd>
                                </div>
                            </template>
                            <template v-else>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Legal name
                                    </dt>
                                    <dd>
                                        <V2MaskedValue
                                            v-if="file.personal.legalName"
                                            label="Legal name"
                                            masked="Hidden"
                                            :reveal="reveal('legal_name')"
                                        />
                                        <span v-else>{{ notGiven }}</span>
                                    </dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Preferred name
                                    </dt>
                                    <dd>
                                        {{
                                            file.personal.preferredName ??
                                            notGiven
                                        }}
                                    </dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Identity number
                                    </dt>
                                    <dd>
                                        <V2MaskedValue
                                            v-if="file.personal.identity"
                                            label="Identity number"
                                            :masked="
                                                file.personal.identity.mask
                                            "
                                            :masked-spoken="
                                                spokenEnding(
                                                    'Identity number',
                                                    file.personal.identity
                                                        .last4,
                                                )
                                            "
                                            :reveal="reveal('identity')"
                                        />
                                        <span v-else>{{ notGiven }}</span>
                                    </dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Date of birth
                                    </dt>
                                    <dd>
                                        <V2MaskedValue
                                            v-if="file.personal.dateOfBirth"
                                            label="Date of birth"
                                            masked="Hidden"
                                            :reveal="reveal('date_of_birth')"
                                        />
                                        <span v-else>{{ notGiven }}</span>
                                    </dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Nationality
                                    </dt>
                                    <dd>
                                        {{
                                            file.personal.nationality ??
                                            notGiven
                                        }}
                                    </dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Address
                                    </dt>
                                    <dd>
                                        <V2MaskedValue
                                            v-if="file.personal.address"
                                            label="Address"
                                            masked="Hidden"
                                            :reveal="reveal('address')"
                                        />
                                        <span v-else>{{ notGiven }}</span>
                                    </dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Personal phone
                                    </dt>
                                    <dd>
                                        <V2MaskedValue
                                            v-if="file.personal.personalPhone"
                                            label="Personal phone"
                                            masked="Hidden"
                                            :reveal="reveal('personal_phone')"
                                        />
                                        <span v-else>{{ notGiven }}</span>
                                    </dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-muted-foreground">
                                        Emergency contact
                                    </dt>
                                    <dd>
                                        <V2MaskedValue
                                            v-if="
                                                file.personal.emergencyContact
                                            "
                                            label="Emergency contact"
                                            masked="Hidden"
                                            :reveal="
                                                reveal('emergency_contact')
                                            "
                                        />
                                        <span v-else>{{ notGiven }}</span>
                                    </dd>
                                </div>
                            </template>
                        </dl>

                        <section
                            v-if="personalCorrections.length"
                            aria-labelledby="v2-personal-corrections"
                            class="flex flex-col gap-3"
                        >
                            <h3
                                id="v2-personal-corrections"
                                class="text-sm font-semibold"
                            >
                                Corrections waiting
                            </h3>
                            <div
                                v-for="c in personalCorrections"
                                :key="c.id"
                                :class="cardClass"
                                data-test="correction"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{
                                        c.kindLabel
                                    }}</span>
                                    <V2Chip
                                        tone="warning"
                                        label="Correction waiting"
                                    />
                                </div>
                                <p class="text-sm">
                                    Recorded: {{ c.recorded }} · New:
                                    {{ c.new }}
                                </p>
                                <V2MaskedValue
                                    v-if="
                                        c.new === 'Hidden' ||
                                        c.kind === 'identity'
                                    "
                                    label="Requested value"
                                    :masked="c.new"
                                    :reveal="
                                        reveal('correction', {
                                            correction_id: c.id,
                                        })
                                    "
                                />
                                <p class="text-sm text-muted-foreground">
                                    {{ c.reasonLabel
                                    }}<template v-if="c.hasNote">
                                        · note given</template
                                    >
                                    · asked by {{ c.askedBy }}
                                </p>
                                <div
                                    v-if="file.canManage"
                                    class="flex flex-wrap gap-2"
                                >
                                    <button
                                        type="button"
                                        :class="button"
                                        @click="
                                            startCorrectionDecision(c, 'apply')
                                        "
                                    >
                                        Apply
                                    </button>
                                    <button
                                        type="button"
                                        :class="dangerButton"
                                        @click="
                                            startCorrectionDecision(c, 'reject')
                                        "
                                    >
                                        Reject
                                    </button>
                                </div>
                            </div>
                        </section>
                    </section>

                    <!-- Bank -->
                    <section
                        v-if="file.tab === 'bank'"
                        aria-labelledby="v2-bank-title"
                        class="flex flex-col gap-4"
                    >
                        <h2 id="v2-bank-title" class="text-base font-semibold">
                            Bank details
                        </h2>
                        <div
                            v-if="file.bank.confirmed"
                            :class="cardClass"
                            data-test="bank-confirmed"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    file.bank.confirmed.bankName
                                }}</span>
                                <V2Chip tone="success" label="Confirmed" />
                            </div>
                            <V2MaskedValue
                                label="Account number"
                                :masked="file.bank.confirmed.mask"
                                :masked-spoken="
                                    spokenEnding(
                                        'Account number',
                                        file.bank.confirmed.mask.slice(-4),
                                    )
                                "
                                :reveal="
                                    reveal('bank_account', {
                                        bank_id: file.bank.confirmed.id,
                                    })
                                "
                            />
                        </div>
                        <div
                            v-else-if="!file.bank.waiting"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No bank details yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Enter them here; someone else confirms them
                                before they are used.
                            </p>
                        </div>

                        <div
                            v-if="file.bank.waiting"
                            :class="cardClass"
                            data-test="bank-waiting"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">Bank change</span>
                                <V2Chip
                                    tone="warning"
                                    label="Waiting for confirmation"
                                />
                            </div>
                            <p class="text-sm" data-test="bank-compare">
                                Recorded:
                                {{
                                    file.bank.confirmed
                                        ? `${file.bank.confirmed.bankName} ${file.bank.confirmed.mask.replace('Account ', '')}`
                                        : notGiven
                                }}
                                · New: {{ file.bank.waiting.bankName }}
                                {{
                                    file.bank.waiting.mask.replace(
                                        'Account ',
                                        '',
                                    )
                                }}
                            </p>
                            <V2MaskedValue
                                v-if="
                                    !file.bank.waiting.enteredByMe &&
                                    file.canManage
                                "
                                label="New account number"
                                :masked="file.bank.waiting.mask"
                                :masked-spoken="
                                    spokenEnding(
                                        'New account number',
                                        file.bank.waiting.mask.slice(-4),
                                    )
                                "
                                :reveal="
                                    reveal(
                                        'bank_account',
                                        { bank_id: file.bank.waiting.id },
                                        () => (waitingShown = true),
                                    )
                                "
                                data-test="bank-waiting-show"
                            />
                            <p class="text-sm text-muted-foreground">
                                Entered by {{ file.bank.waiting.enteredBy }},
                                {{ timeLabel(file.bank.waiting.enteredAt) }}
                            </p>
                            <template v-if="file.bank.waiting.enteredByMe">
                                <p
                                    class="text-sm text-muted-foreground"
                                    data-test="someone-else"
                                >
                                    Someone else confirms this.
                                </p>
                                <div>
                                    <button
                                        type="button"
                                        :class="dangerButton"
                                        data-test="bank-withdraw"
                                        @click="startBankDecision('withdraw')"
                                    >
                                        Withdraw
                                    </button>
                                </div>
                            </template>
                            <div
                                v-else-if="file.canManage"
                                class="flex flex-col gap-2"
                            >
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        :class="button"
                                        data-test="bank-confirm"
                                        :disabled="!waitingShown"
                                        @click="startBankDecision('confirm')"
                                    >
                                        Confirm
                                    </button>
                                    <button
                                        type="button"
                                        :class="dangerButton"
                                        data-test="bank-reject"
                                        @click="startBankDecision('reject')"
                                    >
                                        Reject
                                    </button>
                                </div>
                                <p
                                    v-if="!waitingShown"
                                    class="text-sm text-muted-foreground"
                                    data-test="show-before-confirm"
                                >
                                    Show the new account number to check it
                                    before you confirm.
                                </p>
                            </div>
                        </div>

                        <div
                            v-for="c in bankCorrections"
                            :key="c.id"
                            :class="cardClass"
                            data-test="correction"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium"
                                    >Bank correction from staff</span
                                >
                                <V2Chip
                                    tone="warning"
                                    label="Correction waiting"
                                />
                            </div>
                            <p class="text-sm">
                                Recorded: {{ c.recorded }} · New: {{ c.new }}
                            </p>
                            <V2MaskedValue
                                v-if="file.canManage"
                                label="Requested account"
                                :masked="c.new"
                                :masked-spoken="
                                    spokenEnding(
                                        'Requested account',
                                        c.new.slice(-4),
                                    )
                                "
                                :reveal="
                                    reveal('correction', {
                                        correction_id: c.id,
                                    })
                                "
                            />
                            <p class="text-sm text-muted-foreground">
                                {{ c.reasonLabel }} · asked by {{ c.askedBy }}.
                                Applying it makes a bank change that a third
                                person confirms.
                            </p>
                            <div
                                v-if="file.canManage"
                                class="flex flex-wrap gap-2"
                            >
                                <button
                                    type="button"
                                    :class="button"
                                    @click="startCorrectionDecision(c, 'apply')"
                                >
                                    Apply
                                </button>
                                <button
                                    type="button"
                                    :class="dangerButton"
                                    @click="
                                        startCorrectionDecision(c, 'reject')
                                    "
                                >
                                    Reject
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Documents -->
                    <section
                        v-if="file.tab === 'documents'"
                        aria-labelledby="v2-documents-title"
                        class="flex flex-col gap-4"
                    >
                        <h2
                            id="v2-documents-title"
                            class="text-base font-semibold"
                        >
                            Documents
                        </h2>
                        <p
                            v-if="errors.document && !removal.document"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errors.document }}
                        </p>
                        <V2DocumentList
                            :documents="file.documents"
                            :worker-id="w.id"
                            :can-open="file.canOpenDocuments"
                            :can-remove="file.canManage"
                            empty-title="No documents yet."
                            empty-description="Add a contract, offer letter, identity copy, certificate or resignation letter. PDF, JPG or PNG, up to 10 MB."
                            @remove="startRemoval"
                        />
                    </section>

                    <!-- Checklist -->
                    <section
                        v-if="file.tab === 'checklist' && file.checklist"
                        aria-labelledby="v2-checklist-title"
                        class="flex flex-col gap-4"
                    >
                        <h2
                            id="v2-checklist-title"
                            class="text-base font-semibold"
                        >
                            Onboarding and offboarding
                        </h2>
                        <V2ChecklistTab
                            :periods="file.checklist"
                            :worker-id="w.id"
                            :can-manage="file.canManage"
                        />
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- Cancel a locum sign-in request -->
        <V2Dialog
            :open="signIn.cancelOpen"
            title="Cancel the sign-in request?"
            description="The account administrator will no longer see it on the Staff page. You can ask again later."
            confirm-label="Cancel request"
            cancel-label="Keep it"
            tone="danger"
            :busy="signIn.processing"
            @update:open="(open: boolean) => (signIn.cancelOpen = open)"
            @confirm="cancelSignIn"
        >
            <p
                v-if="errors.request"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.request }}
            </p>
        </V2Dialog>

        <!-- Add or change an employment period -->
        <V2Dialog
            :open="period.open"
            :title="
                period.editing
                    ? 'Change employment period'
                    : 'Add employment period'
            "
            description="Ending a period changes nothing on the KPOne account."
            size="tall"
            :confirm-label="period.processing ? 'Saving…' : 'Save'"
            :busy="period.processing"
            :confirm-disabled="periodMissing !== ''"
            :confirm-disabled-reason="periodMissing"
            data-test="period-sheet"
            @update:open="(open: boolean) => (period.open = open)"
            @confirm="savePeriod"
        >
            <p
                v-if="errors.records"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.records }}
            </p>
            <V2OptionGroup
                :model-value="period.type || null"
                :options="file.options.types"
                label="Type"
                required
                :error="errors.type"
                @update:model-value="period.type = $event ?? ''"
            />
            <V2DateField
                v-model="period.startsOn"
                label="Start date"
                required
                :error="errors.starts_on"
            />
            <V2DateField
                v-model="period.probationEndsOn"
                label="Probation ends (optional)"
                :error="errors.probation_ends_on"
            />
            <V2DateField
                v-model="period.confirmedOn"
                label="Confirmed on (optional)"
                :error="errors.confirmed_on"
            />
            <V2DateField
                v-model="period.endsOn"
                label="End date (optional)"
                :error="errors.ends_on"
            />
            <V2OptionGroup
                v-if="period.endsOn"
                :model-value="period.endReason || null"
                :options="file.options.endReasons"
                label="Why it ended"
                required
                :error="errors.end_reason"
                @update:model-value="period.endReason = $event ?? ''"
            />
            <V2Field
                v-slot="{ id, describedBy }"
                label="Note (optional)"
                :hint="`Do not write medical details here.${period.editing?.hasNote ? ' Leave empty to keep the note.' : ''}`"
                :error="errors.note"
            >
                <textarea
                    :id="id"
                    v-model="period.note"
                    maxlength="200"
                    rows="3"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
            <V2OptionGroup
                v-if="needsEditReason"
                :model-value="period.editReason || null"
                :options="file.options.editReasons"
                label="Why this is being changed"
                required
                :error="errors.edit_reason"
                @update:model-value="period.editReason = $event ?? ''"
            />
        </V2Dialog>

        <!-- Add a position -->
        <V2Dialog
            :open="position.open"
            title="Add position"
            description="The directory job title in Staff and access is not changed."
            :confirm-label="position.processing ? 'Saving…' : 'Add position'"
            :busy="position.processing"
            :confirm-disabled="positionMissing !== ''"
            :confirm-disabled-reason="positionMissing"
            @update:open="(open: boolean) => (position.open = open)"
            @confirm="savePosition"
        >
            <V2Field
                v-slot="{ id, describedBy }"
                label="Title"
                required
                :error="errors.title"
            >
                <input
                    :id="id"
                    v-model="position.title"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <div class="grid gap-1.5">
                <label
                    id="v2-position-department-label"
                    class="text-sm font-medium"
                    >Department (optional)</label
                >
                <OperationalSelect
                    v-model="position.departmentId"
                    trigger-class="max-lg:h-11"
                    label="Department (optional)"
                    labelledby="v2-position-department-label"
                    :options="departmentOptions"
                />
                <p
                    v-if="errors.department_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.department_id }}
                </p>
            </div>
            <div class="grid gap-1.5">
                <label
                    id="v2-position-reports-label"
                    class="text-sm font-medium"
                    >Reports to (optional)</label
                >
                <OperationalSelect
                    v-model="position.reportsTo"
                    trigger-class="max-lg:h-11"
                    label="Reports to (optional)"
                    labelledby="v2-position-reports-label"
                    :options="reportsToOptions"
                />
                <p
                    v-if="errors.reports_to"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.reports_to }}
                </p>
            </div>
            <V2DateField
                v-model="position.fromDate"
                label="Applies from"
                required
                :error="errors.from_date"
            />
        </V2Dialog>

        <!-- Edit personal details (hidden values are never filled in) -->
        <V2Dialog
            :open="personal.open"
            title="Edit personal details"
            description="Hidden values are not filled in. Leave a field empty to keep what is recorded."
            size="tall"
            :confirm-label="personal.processing ? 'Saving…' : 'Save'"
            :busy="personal.processing"
            data-test="personal-sheet"
            @update:open="(open: boolean) => (personal.open = open)"
            @confirm="savePersonal"
        >
            <p
                v-if="errors.records"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.records }}
            </p>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Legal name"
                :error="errors.legal_name"
            >
                <input
                    :id="id"
                    v-model="personal.legalName"
                    autocomplete="off"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Preferred name"
                :error="errors.preferred_name"
            >
                <input
                    :id="id"
                    v-model="personal.preferredName"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2OptionGroup
                :model-value="personal.identityKind"
                :options="identityKinds"
                label="Identity document"
                :error="errors.identity_kind"
                @update:model-value="personal.identityKind = $event ?? ''"
            />
            <template v-if="personal.identityKind">
                <V2Field
                    v-slot="{ id, describedBy }"
                    :label="
                        personal.identityKind === 'ic'
                            ? 'IC number'
                            : 'Passport number'
                    "
                    :error="errors.identity_number"
                >
                    <input
                        :id="id"
                        v-model="personal.identityNumber"
                        autocomplete="off"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
                <V2Field
                    v-if="personal.identityKind === 'passport'"
                    v-slot="{ id, describedBy }"
                    label="Issuing country (2-letter code)"
                    :error="errors.passport_country"
                >
                    <input
                        :id="id"
                        v-model="personal.passportCountry"
                        maxlength="2"
                        :class="`${inputClass} w-24 uppercase`"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
            </template>
            <V2DateField
                v-model="personal.dateOfBirth"
                label="Date of birth"
                :error="errors.date_of_birth"
            />
            <V2Field
                v-slot="{ id, describedBy }"
                label="Nationality"
                :error="errors.nationality"
            >
                <input
                    :id="id"
                    v-model="personal.nationality"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Personal phone"
                :error="errors.personal_phone"
            >
                <input
                    :id="id"
                    v-model="personal.personalPhone"
                    type="tel"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1 text-sm font-semibold">Address</legend>
                <V2Field
                    v-for="part in addressParts"
                    :key="part.key"
                    v-slot="{ id, describedBy }"
                    :label="part.label"
                    :error="errors[`address.${part.key}`]"
                >
                    <input
                        :id="id"
                        v-model="personal[part.key]"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
            </fieldset>
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1 text-sm font-semibold">
                    Emergency contact
                </legend>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Name"
                    :error="errors['emergency_contact.name']"
                >
                    <input
                        :id="id"
                        v-model="personal.emName"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Relationship"
                    :error="errors['emergency_contact.relationship']"
                >
                    <input
                        :id="id"
                        v-model="personal.emRelationship"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Phone"
                    :error="errors['emergency_contact.phone']"
                >
                    <input
                        :id="id"
                        v-model="personal.emPhone"
                        type="tel"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
            </fieldset>
        </V2Dialog>

        <!-- Enter or change bank details -->
        <V2Dialog
            :open="bankForm.open"
            :title="bankChangeLabel"
            description="Saved as waiting. Someone other than you and the staff member confirms it."
            :confirm-label="
                bankForm.processing ? 'Saving…' : 'Save for confirmation'
            "
            :busy="bankForm.processing"
            :confirm-disabled="bankMissing !== ''"
            :confirm-disabled-reason="bankMissing"
            data-test="bank-sheet"
            @update:open="(open: boolean) => (bankForm.open = open)"
            @confirm="saveBank"
        >
            <p
                v-if="errors.bank || errors.records"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.bank ?? errors.records }}
            </p>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Bank name"
                required
                :error="errors.bank_name"
            >
                <input
                    :id="id"
                    v-model="bankForm.bankName"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Account holder"
                required
                :error="errors.holder"
            >
                <input
                    :id="id"
                    v-model="bankForm.holder"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Account number"
                required
                :error="errors.account"
            >
                <input
                    :id="id"
                    v-model="bankForm.account"
                    inputmode="numeric"
                    autocomplete="off"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
        </V2Dialog>

        <!-- Confirm, reject or withdraw a bank change -->
        <V2Dialog
            :open="bankDecision.open"
            :title="bankDialog.title"
            :description="bankDialog.description"
            :confirm-label="bankDialog.label"
            :tone="bankDecision.decision === 'confirm' ? 'default' : 'danger'"
            :busy="bankDecision.processing"
            :confirm-disabled="bankDecisionMissing !== ''"
            :confirm-disabled-reason="bankDecisionMissing"
            @update:open="(open: boolean) => (bankDecision.open = open)"
            @confirm="sendBankDecision"
        >
            <p
                v-if="errors.reason ?? errors.bank ?? errors.records"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.reason ?? errors.bank ?? errors.records }}
            </p>
            <V2OptionGroup
                v-if="bankDecision.decision === 'reject'"
                :model-value="bankDecision.reason || null"
                :options="file.options.bankRejectReasons"
                label="Why it is rejected"
                required
                :error="errors.reason"
                @update:model-value="bankDecision.reason = $event ?? ''"
            />
        </V2Dialog>

        <!-- Apply or reject a correction -->
        <V2Dialog
            :open="correctionDecision.correction !== null"
            :title="
                correctionDecision.decision === 'apply'
                    ? 'Apply this correction?'
                    : 'Reject this correction?'
            "
            :description="
                correctionDecision.correction
                    ? `${correctionDecision.correction.kindLabel}. Recorded: ${correctionDecision.correction.recorded} · New: ${correctionDecision.correction.new}.`
                    : ''
            "
            :confirm-label="
                correctionDecision.decision === 'apply' ? 'Apply' : 'Reject'
            "
            :tone="
                correctionDecision.decision === 'apply' ? 'default' : 'danger'
            "
            :busy="correctionDecision.processing"
            :confirm-disabled="correctionMissing !== ''"
            :confirm-disabled-reason="correctionMissing"
            @update:open="
                (open: boolean) =>
                    !open && (correctionDecision.correction = null)
            "
            @confirm="sendCorrectionDecision"
        >
            <p
                v-if="
                    errors.reason ??
                    errors.correction ??
                    errors.identity_number ??
                    errors.bank ??
                    errors.records
                "
                role="alert"
                class="text-sm text-destructive"
            >
                {{
                    errors.reason ??
                    errors.correction ??
                    errors.identity_number ??
                    errors.bank ??
                    errors.records
                }}
            </p>
            <V2OptionGroup
                v-if="correctionDecision.decision === 'reject'"
                :model-value="correctionDecision.reason || null"
                :options="file.options.correctionRejectReasons"
                label="Why it is rejected"
                required
                :error="errors.reason"
                @update:model-value="correctionDecision.reason = $event ?? ''"
            />
        </V2Dialog>

        <!-- Add a document -->
        <V2Dialog
            :open="upload.open"
            title="Add a document"
            description="PDF, JPG or PNG, up to 10 MB. The file is checked and stored encrypted; its name is not kept."
            size="tall"
            :confirm-label="upload.processing ? 'Adding…' : 'Add document'"
            :busy="upload.processing"
            :confirm-disabled="uploadMissing !== ''"
            :confirm-disabled-reason="uploadMissing"
            data-test="document-sheet"
            @update:open="(open: boolean) => (upload.open = open)"
            @confirm="sendUpload"
        >
            <V2OptionGroup
                :model-value="upload.category || null"
                :options="file.options.documentCategories"
                label="Type"
                required
                :error="errors.category"
                @update:model-value="upload.category = $event ?? ''"
            />
            <V2DateField
                v-model="upload.issuedOn"
                label="Issue date (optional)"
                :error="errors.issued_on"
            />
            <V2DateField
                v-model="upload.expiresOn"
                label="Expiry date (optional)"
                :error="errors.expires_on"
            />
            <FileChooseButton
                accept="application/pdf,image/jpeg,image/png"
                :label="upload.file ? 'Choose another file' : 'Choose the file'"
                :status="
                    upload.file
                        ? upload.file.name
                        : 'No file chosen. PDF, JPG or PNG, up to 10 MB.'
                "
                status-id="v2-document-file-status"
                @change="onFile"
            />
            <p v-if="errors.file" role="alert" class="text-sm text-destructive">
                {{ errors.file }}
            </p>
        </V2Dialog>

        <!-- Remove a document -->
        <V2Dialog
            :open="removal.document !== null"
            title="Remove this document?"
            :description="
                removal.document
                    ? `${removal.document.categoryLabel}. The file is deleted; the record that it existed stays.`
                    : ''
            "
            confirm-label="Remove"
            tone="danger"
            :busy="removal.processing"
            :confirm-disabled="removalMissing !== ''"
            :confirm-disabled-reason="removalMissing"
            @update:open="(open: boolean) => !open && (removal.document = null)"
            @confirm="sendRemoval"
        >
            <p
                v-if="errors.reason ?? errors.document ?? errors.records"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.reason ?? errors.document ?? errors.records }}
            </p>
            <V2OptionGroup
                :model-value="removal.reason || null"
                :options="file.options.documentRemovalReasons"
                label="Why it is removed"
                required
                :error="errors.reason"
                @update:model-value="removal.reason = $event ?? ''"
            />
        </V2Dialog>
    </PageFrame>
</template>
