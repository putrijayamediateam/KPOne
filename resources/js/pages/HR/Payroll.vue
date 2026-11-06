<script setup lang="ts">
// HR-7a /hr/payroll: Pay profiles, Tables and Settings for payroll preparers and approvers (HR-PLAN 14.2 and 14.6, with
// Daniel's early look folded in). Nothing is calculated yet: payroll runs come with HR-7b. KPOne ships no statutory
// number, makes no payment and files nothing.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AccountantNote from '@/components/hr/payroll/AccountantNote.vue';
import ProfileSheet from '@/components/hr/payroll/ProfileSheet.vue';
import TableSheet from '@/components/hr/payroll/TableSheet.vue';
import type {
    Option,
    Person,
    Profile,
    StatutoryTable,
} from '@/components/hr/payroll/types';
import {
    day,
    on,
    profileWords,
    tableWords,
    tone,
} from '@/components/hr/payroll/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { myr } from '@/types/billing';

type Tab = 'profiles' | 'tables' | 'settings';
type Group = {
    id: string;
    name: string;
    payDay: number | null;
    isActive: boolean;
    lockVersion: number;
};
type ItemType = {
    id: string;
    name: string;
    kind: string;
    kindLabel: string;
    version: number;
    countsEpf: boolean;
    countsSocso: boolean;
    countsEis: boolean;
    countsMtd: boolean;
    countsHrd: boolean;
    isActive: boolean;
    lockVersion: number;
};

const props = defineProps<{
    payroll: {
        tab: Tab;
        tabs: Tab[];
        today: string;
        canApprove: boolean;
        options: {
            schemes: Option[];
            rounding: Option[];
            kinds: Option[];
            tableRejectReasons: Option[];
            profileRejectReasons: Option[];
            groups: Option[];
            allowanceTypes: Option[];
            categories: { epf: string[]; socso: string[] };
            suggestedItemTypes: string[];
            maxRows: number;
            maxItems: number;
        };
        setUp: { groups: boolean; rates: boolean; checkedTables: boolean };
        profiles: {
            people: Person[];
            waitingCount: number;
            missingCount: number;
        } | null;
        tables: { tables: StatutoryTable[]; waitingCount: number } | null;
        settings: {
            groups: Group[];
            itemTypes: ItemType[];
            rates: {
                version: number;
                daily: string | null;
                hourly: string | null;
                history: {
                    version: number;
                    daily: string;
                    hourly: string;
                    by: string;
                    at: string;
                }[];
            };
        } | null;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.payroll ?? errors.value.records);
const tabLabels: Record<Tab, string> = {
    profiles: 'Pay profiles',
    tables: 'Tables',
    settings: 'Settings',
};
const tabs = computed(() =>
    props.payroll.tabs.map((value) => ({ value, label: tabLabels[value] })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/payroll', { tab: value }, { preserveScroll: true });
const money = (sen: number | null) =>
    sen === null ? 'Amount unavailable' : myr(sen);
const rateLine = (p: Profile) =>
    p.payType === 'hourly'
        ? `Hourly · ${money(p.rateSen)} an hour`
        : `Monthly salary · ${money(p.rateSen)}`;
const busy = ref(false);
const anyDialog = computed(
    () =>
        decision.target !== null ||
        withdrawing.target !== null ||
        profileSheet.open ||
        tableSheet.value ||
        groupForm.open ||
        typeForm.open,
);

// ---- Pay profiles ----
const profileSheet = reactive({ open: false, person: null as Person | null });
const enterFor = (person: Person) =>
    Object.assign(profileSheet, { open: true, person });

// ---- Tables ----
const tableSheet = ref(false);

// ---- Decide and withdraw (profiles and tables) ----
type Target = {
    kind: 'profile' | 'table';
    id: string;
    lockVersion: number;
    label: string;
};
const decision = reactive({
    target: null as Target | null,
    action: 'check' as 'check' | 'reject',
    reason: '',
    key: '',
});
const askDecide = (target: Target, action: 'check' | 'reject') =>
    Object.assign(decision, {
        target,
        action,
        reason: '',
        key: crypto.randomUUID(),
    });
const decide = () => {
    const t = decision.target;

    if (!t) {
        return;
    }

    busy.value = true;
    const verb =
        t.kind === 'profile'
            ? decision.action === 'check'
                ? 'confirm'
                : 'reject'
            : decision.action;
    router.post(
        `/hr/payroll/${t.kind === 'profile' ? 'profiles' : 'tables'}/${t.id}/${verb}`,
        {
            reason: decision.action === 'reject' ? decision.reason : null,
            expected_lock_version: t.lockVersion,
            idempotency_key: decision.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (decision.target = null),
            onFinish: () => (busy.value = false),
        },
    );
};
const withdrawing = reactive({ target: null as Target | null, key: '' });
const askWithdraw = (target: Target) =>
    Object.assign(withdrawing, { target, key: crypto.randomUUID() });
const withdraw = () => {
    const t = withdrawing.target;

    if (!t) {
        return;
    }

    busy.value = true;
    router.post(
        `/hr/payroll/${t.kind === 'profile' ? 'profiles' : 'tables'}/${t.id}/withdraw`,
        {
            expected_lock_version: t.lockVersion,
            idempotency_key: withdrawing.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (withdrawing.target = null),
            onFinish: () => (busy.value = false),
        },
    );
};
const profileTarget = (person: Person, p: Profile): Target => ({
    kind: 'profile',
    id: p.id,
    lockVersion: p.lockVersion,
    label: `${person.name} · applies from ${day(p.effectiveFrom)}`,
});
const tableTarget = (t: StatutoryTable): Target => ({
    kind: 'table',
    id: t.id,
    lockVersion: t.lockVersion,
    label: `${t.schemeLabel} · ${t.category} · from ${day(t.effectiveFrom)}`,
});

// ---- Settings ----
const groupForm = reactive({
    open: false,
    group: null as Group | null,
    name: '',
    payDay: '',
    isActive: true,
    key: '',
    processing: false,
});
const openGroup = (group: Group | null) =>
    Object.assign(groupForm, {
        open: true,
        group,
        name: group?.name ?? '',
        payDay: group?.payDay === null || !group ? '' : String(group.payDay),
        isActive: group?.isActive ?? true,
        key: crypto.randomUUID(),
    });
const saveGroup = () => {
    groupForm.processing = true;
    router.post(
        '/hr/payroll/groups',
        {
            group_id: groupForm.group?.id ?? null,
            name: groupForm.name,
            pay_day: groupForm.payDay,
            is_active: groupForm.isActive,
            expected_lock_version: groupForm.group?.lockVersion ?? null,
            idempotency_key: groupForm.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (groupForm.open = false),
            onFinish: () => (groupForm.processing = false),
        },
    );
};
const schemeFlags = [
    { key: 'countsEpf', field: 'counts_epf', label: 'EPF' },
    { key: 'countsSocso', field: 'counts_socso', label: 'SOCSO' },
    { key: 'countsEis', field: 'counts_eis', label: 'EIS' },
    { key: 'countsMtd', field: 'counts_mtd', label: 'MTD' },
    { key: 'countsHrd', field: 'counts_hrd', label: 'HRD' },
] as const;
type FlagKey = (typeof schemeFlags)[number]['key'];
const typeForm = reactive({
    open: false,
    type: null as ItemType | null,
    name: '',
    kind: 'earnings',
    flags: {
        countsEpf: false,
        countsSocso: false,
        countsEis: false,
        countsMtd: false,
        countsHrd: false,
    } as Record<FlagKey, boolean>,
    isActive: true,
    key: '',
    processing: false,
});
const openType = (type: ItemType | null, name = '') =>
    Object.assign(typeForm, {
        open: true,
        type,
        name: type?.name ?? name,
        kind:
            type?.kind ??
            (/deduction|unpaid/i.test(name)
                ? 'deduction'
                : /refund/i.test(name)
                  ? 'refund'
                  : 'earnings'),
        flags: {
            countsEpf: type?.countsEpf ?? false,
            countsSocso: type?.countsSocso ?? false,
            countsEis: type?.countsEis ?? false,
            countsMtd: type?.countsMtd ?? false,
            countsHrd: type?.countsHrd ?? false,
        },
        isActive: type?.isActive ?? true,
        key: crypto.randomUUID(),
    });
const saveType = () => {
    typeForm.processing = true;
    router.post(
        '/hr/payroll/item-types',
        {
            item_type_id: typeForm.type?.id ?? null,
            name: typeForm.name,
            kind: typeForm.kind,
            counts_epf: typeForm.flags.countsEpf,
            counts_socso: typeForm.flags.countsSocso,
            counts_eis: typeForm.flags.countsEis,
            counts_mtd: typeForm.flags.countsMtd,
            counts_hrd: typeForm.flags.countsHrd,
            is_active: typeForm.isActive,
            expected_lock_version: typeForm.type?.lockVersion ?? null,
            idempotency_key: typeForm.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (typeForm.open = false),
            onFinish: () => (typeForm.processing = false),
        },
    );
};
const unusedSuggestions = computed(() =>
    props.payroll.options.suggestedItemTypes.filter(
        (name) =>
            !(props.payroll.settings?.itemTypes ?? []).some(
                (t) => t.name.toLowerCase() === name.toLowerCase(),
            ),
    ),
);
const rates = reactive({
    daily: props.payroll.settings?.rates.daily ?? '',
    hourly: props.payroll.settings?.rates.hourly ?? '',
    key: crypto.randomUUID(),
    processing: false,
});
const saveRates = () => {
    rates.processing = true;
    router.post(
        '/hr/payroll/rates',
        {
            daily_divisor: rates.daily,
            hourly_divisor: rates.hourly,
            expected_version: props.payroll.settings?.rates.version ?? 0,
            idempotency_key: rates.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (rates.key = crypto.randomUUID()),
            onFinish: () => (rates.processing = false),
        },
    );
};
</script>

<template>
    <Head title="Payroll" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Payroll"
                description="Pay profiles, statutory tables and pay settings. A second person checks each one before it counts. Payroll runs come next; KPOne makes no payment and files nothing."
            />
            <AccountantNote />
        </div>

        <OperationalTabs
            :model-value="payroll.tab"
            :tabs="tabs"
            label="Payroll sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState v-if="failure && !anyDialog" :message="failure" />

        <!-- Pay profiles -->
        <section
            v-if="payroll.tab === 'profiles' && payroll.profiles"
            aria-labelledby="profiles-title"
            class="flex flex-col gap-3"
            data-test="pay-profiles"
        >
            <div class="flex flex-col gap-1">
                <h2 id="profiles-title" class="text-base font-semibold">
                    Pay profiles
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ payroll.profiles.waitingCount }} waiting for confirmation
                    · {{ payroll.profiles.missingCount }} without a confirmed
                    profile
                </p>
                <p
                    class="text-sm text-muted-foreground"
                    data-test="managers-outside"
                >
                    In this first version, the Director's and the HR Manager's
                    own pay is kept outside KPOne.
                </p>
            </div>
            <EmptyState
                v-if="!payroll.setUp.groups"
                title="No pay group yet."
                description="Add a pay group in Settings first; each pay profile belongs to one."
            />
            <EmptyState
                v-else-if="!payroll.profiles.people.length"
                title="No staff files yet."
                description="Pay profiles are entered for staff who have a staff file."
            />
            <article
                v-for="person in payroll.profiles.people"
                :key="person.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`person-${person.id}`"
                data-test="pay-person"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`person-${person.id}`"
                            class="text-base font-semibold break-words"
                        >
                            {{ person.name }}
                        </h3>
                        <p
                            v-if="!person.active"
                            class="text-sm text-muted-foreground"
                        >
                            Account inactive
                        </p>
                    </div>
                    <Button
                        v-if="
                            payroll.setUp.groups &&
                            !person.outside &&
                            !person.enterRefusal
                        "
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="profile-enter"
                        @click="enterFor(person)"
                        >{{
                            person.current
                                ? 'Change pay profile'
                                : 'Enter pay profile'
                        }}</Button
                    >
                    <p
                        v-else-if="
                            person.enterRefusal &&
                            !person.waiting &&
                            !person.outside
                        "
                        class="text-sm text-muted-foreground"
                    >
                        {{ person.enterRefusal }}
                    </p>
                </div>

                <p
                    v-if="person.outside"
                    class="text-sm text-muted-foreground"
                    data-test="pay-outside"
                >
                    Pay kept outside KPOne in this version.
                </p>
                <div
                    v-else
                    class="grid gap-3"
                    :class="
                        person.waiting && person.current ? 'md:grid-cols-2' : ''
                    "
                >
                    <div
                        v-if="person.current"
                        class="flex flex-col gap-1 rounded-lg border p-3"
                        data-test="profile-current"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium"
                                >In force now</span
                            >
                            <StatusBadge
                                status="confirmed"
                                :label="profileWords.confirmed"
                                :tone="tone('confirmed')"
                            />
                        </div>
                        <p class="text-sm tabular-nums">
                            {{ rateLine(person.current) }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ person.current.payGroup }} · from
                            {{ day(person.current.effectiveFrom) }}
                        </p>
                        <p
                            v-if="person.current.schemes.length"
                            class="text-sm text-muted-foreground"
                        >
                            {{ person.current.schemes.join(' · ') }}
                        </p>
                        <ul
                            v-if="person.current.items.length"
                            class="text-sm tabular-nums"
                        >
                            <li
                                v-for="(item, i) in person.current.items"
                                :key="i"
                            >
                                {{ item.name }} · {{ money(item.amountSen) }}
                            </li>
                        </ul>
                        <p class="text-xs text-muted-foreground">
                            Confirmed by {{ person.current.decidedBy }}
                        </p>
                    </div>
                    <p
                        v-else-if="!person.waiting"
                        class="text-sm text-muted-foreground"
                    >
                        No confirmed pay profile.
                    </p>

                    <div
                        v-if="person.waiting"
                        class="flex flex-col gap-1 rounded-lg border border-dashed p-3"
                        data-test="profile-waiting"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium">New</span>
                            <StatusBadge
                                status="waiting"
                                :label="profileWords.waiting"
                                :tone="tone('waiting')"
                            />
                        </div>
                        <p class="text-sm tabular-nums">
                            {{ rateLine(person.waiting) }}
                            <template v-if="person.current"
                                >(now
                                {{ money(person.current.rateSen) }})</template
                            >
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ person.waiting.payGroup }} · from
                            {{ day(person.waiting.effectiveFrom) }}
                        </p>
                        <p
                            v-if="person.waiting.schemes.length"
                            class="text-sm text-muted-foreground"
                        >
                            {{ person.waiting.schemes.join(' · ') }}
                        </p>
                        <ul
                            v-if="person.waiting.items.length"
                            class="text-sm tabular-nums"
                        >
                            <li
                                v-for="(item, i) in person.waiting.items"
                                :key="i"
                            >
                                {{ item.name }} · {{ money(item.amountSen) }}
                            </li>
                        </ul>
                        <p class="text-xs text-muted-foreground">
                            Entered by {{ person.waiting.enteredBy }} on
                            {{ on(person.waiting.enteredAt) }}
                        </p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <template v-if="person.waiting.canDecide">
                                <Button
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    data-test="profile-confirm"
                                    @click="
                                        askDecide(
                                            profileTarget(
                                                person,
                                                person.waiting,
                                            ),
                                            'check',
                                        )
                                    "
                                    >Confirm</Button
                                >
                                <Button
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    data-test="profile-reject"
                                    @click="
                                        askDecide(
                                            profileTarget(
                                                person,
                                                person.waiting,
                                            ),
                                            'reject',
                                        )
                                    "
                                    >Reject</Button
                                >
                            </template>
                            <p
                                v-else-if="person.waiting.decideRefusal"
                                class="text-sm text-muted-foreground"
                            >
                                {{ person.waiting.decideRefusal }}
                            </p>
                            <Button
                                v-if="person.waiting.canWithdraw"
                                variant="outline"
                                class="max-lg:min-h-11"
                                data-test="profile-withdraw"
                                @click="
                                    askWithdraw(
                                        profileTarget(person, person.waiting),
                                    )
                                "
                                >Withdraw</Button
                            >
                        </div>
                    </div>
                </div>

                <p
                    v-if="person.upcoming"
                    class="text-sm text-muted-foreground tabular-nums"
                >
                    From {{ day(person.upcoming.effectiveFrom) }}:
                    {{ rateLine(person.upcoming) }} (confirmed)
                </p>
                <details v-if="person.earlier.length" class="text-sm">
                    <summary
                        class="flex min-h-11 cursor-pointer items-center text-muted-foreground lg:min-h-0"
                    >
                        Earlier pay profiles ({{ person.earlier.length }})
                    </summary>
                    <ul class="mt-2 flex flex-col gap-1">
                        <li
                            v-for="p in person.earlier"
                            :key="p.id"
                            class="tabular-nums"
                        >
                            {{ day(p.effectiveFrom) }} · {{ rateLine(p) }} ·
                            {{ profileWords[p.status]
                            }}<template v-if="p.reason"
                                >: {{ p.reason }}</template
                            >
                        </li>
                    </ul>
                </details>
            </article>
        </section>

        <!-- Tables -->
        <section
            v-if="payroll.tab === 'tables' && payroll.tables"
            aria-labelledby="tables-title"
            class="flex flex-col gap-3"
            data-test="statutory-tables"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-col gap-1">
                    <h2 id="tables-title" class="text-base font-semibold">
                        Statutory tables
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        EPF, SOCSO, EIS and the HRD levy, entered by the clinic
                        from the official tables. KPOne ships no statutory
                        number.
                    </p>
                </div>
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="table-enter"
                    @click="tableSheet = true"
                    >Enter a table</Button
                >
            </div>
            <EmptyState
                v-if="!payroll.tables.tables.length"
                title="No tables checked yet."
                description="Enter a table from the official figures; someone else checks it before it counts."
            />
            <article
                v-for="t in payroll.tables.tables"
                :key="t.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`table-${t.id}`"
                data-test="statutory-table"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`table-${t.id}`"
                            class="text-base font-semibold break-words"
                        >
                            {{ t.schemeLabel }} · {{ t.category }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            From {{ day(t.effectiveFrom) }} ·
                            {{ t.roundingLabel }}
                        </p>
                    </div>
                    <StatusBadge
                        :status="t.status"
                        :label="
                            t.reason
                                ? `${tableWords[t.status]}: ${t.reason}`
                                : tableWords[t.status]
                        "
                        :tone="tone(t.status)"
                    />
                </div>
                <p class="text-sm break-words">Source: {{ t.sourceNote }}</p>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full min-w-[28rem] text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-medium">
                                    Wages from
                                </th>
                                <th scope="col" class="px-3 py-2 font-medium">
                                    To
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2 text-right font-medium"
                                >
                                    Employee
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2 text-right font-medium"
                                >
                                    Employer
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(r, i) in t.rows"
                                :key="i"
                                class="border-t tabular-nums"
                            >
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ r.from }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ r.to ?? 'and above' }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    {{ r.employee }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    {{ r.employer }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">
                    Entered by {{ t.enteredBy }} on {{ on(t.enteredAt)
                    }}<template v-if="t.decidedBy && t.decidedAt">
                        ·
                        {{ t.status === 'rejected' ? 'Rejected' : 'Checked' }}
                        by {{ t.decidedBy }} on {{ on(t.decidedAt) }}</template
                    >
                </p>
                <div
                    v-if="t.status === 'entered'"
                    class="flex flex-wrap items-center gap-2"
                >
                    <template v-if="t.canDecide">
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            data-test="table-check"
                            @click="askDecide(tableTarget(t), 'check')"
                            >Check</Button
                        >
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            data-test="table-reject"
                            @click="askDecide(tableTarget(t), 'reject')"
                            >Reject</Button
                        >
                    </template>
                    <p
                        v-else-if="t.decideRefusal"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t.decideRefusal }}
                    </p>
                    <Button
                        v-if="t.canWithdraw"
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="table-withdraw"
                        @click="askWithdraw(tableTarget(t))"
                        >Withdraw</Button
                    >
                </div>
            </article>
        </section>

        <!-- Settings -->
        <section
            v-if="payroll.tab === 'settings' && payroll.settings"
            aria-labelledby="settings-title"
            class="flex flex-col gap-6"
            data-test="payroll-settings"
        >
            <h2 id="settings-title" class="sr-only">Payroll settings</h2>

            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-semibold">Pay groups</h3>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="group-add"
                        @click="openGroup(null)"
                        >Add a pay group</Button
                    >
                </div>
                <EmptyState
                    v-if="!payroll.settings.groups.length"
                    title="No pay group yet."
                    description="For example one monthly group for everyone."
                />
                <ul
                    v-else
                    class="flex flex-col divide-y rounded-xl border bg-card"
                >
                    <li
                        v-for="g in payroll.settings.groups"
                        :key="g.id"
                        class="flex flex-wrap items-center justify-between gap-2 p-3"
                        data-test="pay-group"
                    >
                        <div class="min-w-0">
                            <p class="font-medium break-words">{{ g.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    g.payDay
                                        ? `Pay day ${g.payDay} of each month`
                                        : 'Pay day not set'
                                }}
                                · {{ g.isActive ? 'In use' : 'Not in use' }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Change ${g.name}`"
                            @click="openGroup(g)"
                            >Change</Button
                        >
                    </li>
                </ul>
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold">Pay item types</h3>
                        <p class="text-sm text-muted-foreground">
                            The clinic decides which items count for each
                            scheme. Each save is a new version.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="type-add"
                        @click="openType(null)"
                        >Add a pay item type</Button
                    >
                </div>
                <div
                    v-if="unusedSuggestions.length"
                    class="flex flex-wrap items-center gap-2"
                >
                    <span class="text-sm text-muted-foreground"
                        >Suggested:</span
                    >
                    <Button
                        v-for="name in unusedSuggestions"
                        :key="name"
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        @click="openType(null, name)"
                        >{{ name }}</Button
                    >
                </div>
                <ul
                    v-if="payroll.settings.itemTypes.length"
                    class="flex flex-col divide-y rounded-xl border bg-card"
                >
                    <li
                        v-for="t in payroll.settings.itemTypes"
                        :key="t.id"
                        class="flex flex-wrap items-center justify-between gap-2 p-3"
                        data-test="item-type"
                    >
                        <div class="min-w-0">
                            <p class="font-medium break-words">{{ t.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ t.kindLabel }} · counts for
                                {{
                                    schemeFlags
                                        .filter((f) => t[f.key])
                                        .map((f) => f.label)
                                        .join(', ') || 'no scheme'
                                }}
                                · {{ t.isActive ? 'In use' : 'Not in use' }} ·
                                version {{ t.version }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Change ${t.name}`"
                            @click="openType(t)"
                            >Change</Button
                        >
                    </li>
                </ul>
            </div>

            <form
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                aria-labelledby="rates-title"
                data-test="rate-settings"
                @submit.prevent="saveRates"
            >
                <div>
                    <h3 id="rates-title" class="text-base font-semibold">
                        Rate settings
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        How a day's and an hour's rate come from a monthly
                        salary, for overtime and unpaid leave. KPOne ships no
                        divisor; the accountant enters them.
                    </p>
                </div>
                <div class="flex flex-wrap gap-4">
                    <div class="grid gap-1.5">
                        <Label for="rate-daily">Days in a month *</Label>
                        <Input
                            id="rate-daily"
                            v-model="rates.daily"
                            inputmode="decimal"
                            class="w-32 text-right tabular-nums max-lg:h-11"
                            aria-describedby="rate-daily-help"
                            :aria-invalid="!!errors.daily_divisor"
                        />
                        <p
                            id="rate-daily-help"
                            class="text-xs text-muted-foreground"
                        >
                            Basic salary ÷ this = a day's rate. For example 26.
                        </p>
                        <InputError :message="errors.daily_divisor" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="rate-hourly">Hours in a day *</Label>
                        <Input
                            id="rate-hourly"
                            v-model="rates.hourly"
                            inputmode="decimal"
                            class="w-32 text-right tabular-nums max-lg:h-11"
                            aria-describedby="rate-hourly-help"
                            :aria-invalid="!!errors.hourly_divisor"
                        />
                        <p
                            id="rate-hourly-help"
                            class="text-xs text-muted-foreground"
                        >
                            A day's rate ÷ this = an hour's rate. For example 8.
                        </p>
                        <InputError :message="errors.hourly_divisor" />
                    </div>
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    class="self-start max-lg:min-h-11"
                    :disabled="rates.processing"
                    data-test="rates-save"
                    >{{
                        rates.processing ? 'Saving…' : 'Save rate settings'
                    }}</Button
                >
                <ul
                    v-if="payroll.settings.rates.history.length"
                    class="flex flex-col gap-1 text-xs text-muted-foreground"
                >
                    <li
                        v-for="h in payroll.settings.rates.history"
                        :key="h.version"
                    >
                        Version {{ h.version }}: {{ h.daily }} days,
                        {{ h.hourly }} hours · {{ h.by }} on {{ on(h.at) }}
                    </li>
                </ul>
            </form>
        </section>

        <ProfileSheet
            v-model:open="profileSheet.open"
            :person="profileSheet.person"
            :groups="payroll.options.groups"
            :allowance-types="payroll.options.allowanceTypes"
            :categories="payroll.options.categories"
            :max-items="payroll.options.maxItems"
            :today="payroll.today"
        />
        <TableSheet
            v-model:open="tableSheet"
            :schemes="payroll.options.schemes"
            :rounding="payroll.options.rounding"
            :max-rows="payroll.options.maxRows"
        />

        <OperationalConfirmDialog
            :open="decision.target !== null"
            :title="
                decision.action === 'reject'
                    ? decision.target?.kind === 'table'
                        ? 'Reject this table?'
                        : 'Reject this pay profile?'
                    : decision.target?.kind === 'table'
                      ? 'Check this table?'
                      : 'Confirm this pay profile?'
            "
            :description="
                decision.target
                    ? decision.action === 'reject'
                        ? `${decision.target.label}. The rejection is kept with its reason; enter a new one to correct it.`
                        : decision.target.kind === 'table'
                          ? `${decision.target.label}. Compare every band with the official table. A checked table never changes.`
                          : `${decision.target.label}. It counts from that date and never changes; a later change is a new profile.`
                    : ''
            "
            :confirm-label="
                decision.action === 'reject'
                    ? 'Reject'
                    : decision.target?.kind === 'table'
                      ? 'Check'
                      : 'Confirm'
            "
            processing-label="Saving…"
            :processing="busy"
            :error="failure"
            @update:open="(v: boolean) => !v && (decision.target = null)"
            @confirm="decide"
        >
            <div v-if="decision.action === 'reject'" class="grid gap-1.5">
                <Label for="payroll-reject-reason"
                    ><span id="payroll-reject-reason-label"
                        >Why it is rejected *</span
                    ></Label
                >
                <OperationalSelect
                    id="payroll-reject-reason"
                    v-model="decision.reason"
                    labelledby="payroll-reject-reason-label"
                    label="Why it is rejected"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:h-11"
                    :options="
                        decision.target?.kind === 'table'
                            ? payroll.options.tableRejectReasons
                            : payroll.options.profileRejectReasons
                    "
                    :invalid="!!errors.reason"
                />
                <InputError :message="errors.reason" />
            </div>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            :open="withdrawing.target !== null"
            title="Withdraw this?"
            :description="
                withdrawing.target
                    ? `${withdrawing.target.label}. It is kept as withdrawn and nobody checks it.`
                    : ''
            "
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="busy"
            :error="failure"
            @update:open="(v: boolean) => !v && (withdrawing.target = null)"
            @confirm="withdraw"
        />

        <Sheet v-model:open="groupForm.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="group-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        groupForm.group
                            ? `Change ${groupForm.group.name}`
                            : 'Add a pay group'
                    }}</SheetTitle>
                    <SheetDescription
                        >Each pay profile belongs to one pay
                        group.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="saveGroup"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="grid gap-1.5">
                        <Label for="group-name">Name *</Label>
                        <Input
                            id="group-name"
                            v-model="groupForm.name"
                            maxlength="60"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.name"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="group-pay-day">Pay day (optional)</Label>
                        <Input
                            id="group-pay-day"
                            v-model="groupForm.payDay"
                            inputmode="numeric"
                            class="w-24 text-right tabular-nums max-lg:h-11"
                            aria-describedby="group-pay-day-help"
                            :aria-invalid="!!errors.pay_day"
                        />
                        <p
                            id="group-pay-day-help"
                            class="text-xs text-muted-foreground"
                        >
                            Day of the month, 1 to 31.
                        </p>
                        <InputError :message="errors.pay_day" />
                    </div>
                    <label
                        v-if="groupForm.group"
                        class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                    >
                        <input
                            v-model="groupForm.isActive"
                            type="checkbox"
                            class="size-4"
                        />
                        In use
                    </label>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="groupForm.processing"
                        >{{
                            groupForm.processing ? 'Saving…' : 'Save pay group'
                        }}</Button
                    >
                </form>
            </SheetContent>
        </Sheet>

        <Sheet v-model:open="typeForm.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="type-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        typeForm.type
                            ? `Change ${typeForm.type.name}`
                            : 'Add a pay item type'
                    }}</SheetTitle>
                    <SheetDescription
                        >Saves a new version; profiles keep the version they
                        used.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="saveType"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="grid gap-1.5">
                        <Label for="type-name">Name *</Label>
                        <Input
                            id="type-name"
                            v-model="typeForm.name"
                            maxlength="60"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.name"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="type-kind"
                            ><span id="type-kind-label">Kind *</span></Label
                        >
                        <OperationalSelect
                            id="type-kind"
                            v-model="typeForm.kind"
                            labelledby="type-kind-label"
                            label="Kind"
                            trigger-class="max-lg:h-11"
                            :options="payroll.options.kinds"
                            :invalid="!!errors.kind"
                        />
                        <InputError :message="errors.kind" />
                    </div>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Counts as wages for
                        </legend>
                        <label
                            v-for="f in schemeFlags"
                            :key="f.key"
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                        >
                            <input
                                v-model="typeForm.flags[f.key]"
                                type="checkbox"
                                class="size-4"
                            />
                            {{ f.label }}
                        </label>
                    </fieldset>
                    <label
                        v-if="typeForm.type"
                        class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                    >
                        <input
                            v-model="typeForm.isActive"
                            type="checkbox"
                            class="size-4"
                        />
                        In use
                    </label>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="typeForm.processing"
                        >{{
                            typeForm.processing
                                ? 'Saving…'
                                : typeForm.type
                                  ? 'Save new version'
                                  : 'Save pay item type'
                        }}</Button
                    >
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
