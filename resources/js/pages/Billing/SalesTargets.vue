<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { myr } from '@/types/billing';

// BIL-30: monthly sales targets per branch and per doctor (Director and Finance Officer). The server works out every
// figure; this page only shows them and sends a set, change or clear.

type Progress = {
    targetSen: number | null;
    achievedSen: number;
    balanceSen: number | null;
    overSen: number | null;
    neededPerDaySen: number | null;
    daysLeft: number | null;
    percent: number | null;
    reached: boolean;
    lockVersion: number;
};
type DoctorRow = Progress & { doctorId: number; name: string; canSet: boolean };
type BranchRow = Progress & {
    branchId: number;
    open: boolean;
    branch: string;
    code: string;
    doctors: DoctorRow[];
};

const props = defineProps<{
    salesTargets: {
        month: string;
        currentMonth: string;
        lastMonth: string;
        open: boolean;
        expectedBranchId: number;
        branches: BranchRow[];
    };
}>();

const view = computed(() => props.salesTargets);
const monthTitle = (month: string) =>
    new Date(`${month}-01T00:00:00`).toLocaleDateString('en-MY', {
        month: 'long',
        year: 'numeric',
    });
const shift = (month: string, by: number) => {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + by, 1);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};
const goTo = (month: string) =>
    router.get('/sales-targets', { month }, { preserveScroll: true });

const expanded = reactive<Record<number, boolean>>({});
const toggle = (branchId: number) => (expanded[branchId] = !expanded[branchId]);

const money = (sen: number | null) => (sen === null ? '—' : myr(sen));
const balanceText = (row: Progress) => {
    if (row.targetSen === null) {
        return '—';
    }

    return row.reached
        ? `Target reached, ${myr(row.overSen ?? 0)} over`
        : myr(row.balanceSen ?? 0);
};
const neededText = (row: Progress) => {
    if (row.targetSen === null || row.reached) {
        return '—';
    }

    if (row.daysLeft === null) {
        return 'Month ended';
    }

    return `${myr(row.neededPerDaySen ?? 0)} a day`;
};
const neededLabel = (row: Progress) =>
    row.targetSen !== null && !row.reached && row.daysLeft
        ? `Needed per day · ${row.daysLeft} ${row.daysLeft === 1 ? 'day' : 'days'} left`
        : 'Needed per day';
const grid =
    'grid gap-3 p-3 lg:grid-cols-[minmax(0,1.3fr)_repeat(4,minmax(0,1fr))_7rem] lg:items-center';

// The set, change or clear dialog.
const open = ref(false);
const editing = ref<{
    title: string;
    branchId: number;
    doctorId: number | null;
    current: number | null;
} | null>(null);
const form = useForm({
    expected_branch_id: 0,
    branch_id: 0,
    month: '',
    doctor_user_id: null as number | null,
    amount: '',
    lock_version: 0,
    reason: '',
    idempotency_key: '',
});
const startEdit = (branch: BranchRow, doctor: DoctorRow | null) => {
    const row = doctor ?? branch;
    editing.value = {
        title: doctor ? `${doctor.name} at ${branch.branch}` : branch.branch,
        branchId: branch.branchId,
        doctorId: doctor?.doctorId ?? null,
        current: row.targetSen,
    };
    form.clearErrors();
    form.expected_branch_id = view.value.expectedBranchId;
    form.branch_id = branch.branchId;
    form.month = view.value.month;
    form.doctor_user_id = doctor?.doctorId ?? null;
    form.amount =
        row.targetSen === null ? '' : (row.targetSen / 100).toFixed(2);
    form.lock_version = row.lockVersion;
    form.reason = '';
    form.idempotency_key = crypto.randomUUID();
    open.value = true;
};
const changing = computed(() => editing.value?.current !== null);
const clearing = computed(() => changing.value && form.amount.trim() === '');
const missing = computed(() => {
    if (!changing.value && form.amount.trim() === '') {
        return 'Write the target in RM.';
    }

    if (changing.value && form.reason.trim() === '') {
        return clearing.value
            ? 'Write why the target is cleared to continue.'
            : 'Write why the target is changed to continue.';
    }

    return '';
});
const submit = () => {
    if (missing.value || form.processing) {
        return;
    }

    form.post('/sales-targets', {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
        onError: () => (form.idempotency_key = crypto.randomUUID()),
    });
};
</script>

<template>
    <Head title="Sales targets" />
    <main class="mx-auto w-full max-w-6xl space-y-4 px-4 py-6 md:px-6">
        <PageHeader
            title="Sales targets"
            description="Each month's target for every branch, and for its doctors. Achieved is net sales, the same figure as Insights."
        />

        <nav
            aria-label="Month"
            class="flex items-center justify-between gap-2 rounded-xl border bg-card p-2"
        >
            <button
                type="button"
                class="inline-flex min-h-11 items-center gap-1 rounded-md px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-testid="targets-previous"
                @click="goTo(shift(view.month, -1))"
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
                <span>Previous<span class="max-sm:sr-only"> month</span></span>
            </button>
            <h2 class="text-base font-semibold" data-testid="targets-month">
                {{ monthTitle(view.month) }}
            </h2>
            <button
                type="button"
                class="inline-flex min-h-11 items-center gap-1 rounded-md px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-disabled:cursor-not-allowed aria-disabled:text-muted-foreground aria-disabled:hover:bg-transparent"
                :aria-disabled="
                    view.month >= view.lastMonth ? 'true' : undefined
                "
                data-testid="targets-next"
                @click="
                    view.month < view.lastMonth && goTo(shift(view.month, 1))
                "
            >
                <span>Next<span class="max-sm:sr-only"> month</span></span>
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
        </nav>

        <p
            v-if="!view.open"
            class="rounded-lg border bg-muted/40 p-3 text-sm"
            data-testid="targets-past"
        >
            This month has ended. Its targets are shown read only.
        </p>
        <p class="text-xs text-muted-foreground">
            Doctor lines can add up to less than the branch line: medicine-only
            (OTC) sales and doctors without a target are not in them. Doctor
            targets are separate goals from the branch target.
        </p>

        <ul class="space-y-3" aria-label="Branches">
            <li
                v-for="branch in view.branches"
                :key="branch.branchId"
                class="rounded-xl border bg-card"
                :data-testid="`target-branch-${branch.code}`"
            >
                <div :class="grid">
                    <div class="min-w-0">
                        <h3 class="font-semibold">{{ branch.branch }}</h3>
                        <p class="text-xs text-muted-foreground">
                            Branch target
                        </p>
                        <button
                            v-if="branch.doctors.length"
                            type="button"
                            class="mt-1 inline-flex min-h-11 items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-0"
                            :aria-expanded="
                                expanded[branch.branchId] ? 'true' : 'false'
                            "
                            @click="toggle(branch.branchId)"
                        >
                            Doctors ({{ branch.doctors.length }})
                            <ChevronDown
                                class="size-3.5 transition-transform"
                                :class="
                                    expanded[branch.branchId]
                                        ? 'rotate-180'
                                        : ''
                                "
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                    <dl class="contents">
                        <div class="grid grid-cols-2 gap-1 lg:block">
                            <dt class="text-xs text-muted-foreground">
                                Target
                            </dt>
                            <dd class="tabular-nums">
                                {{ money(branch.targetSen) }}
                            </dd>
                        </div>
                        <div class="grid grid-cols-2 gap-1 lg:block">
                            <dt class="text-xs text-muted-foreground">
                                Achieved
                            </dt>
                            <dd class="whitespace-nowrap tabular-nums">
                                {{ money(branch.achievedSen)
                                }}<span
                                    v-if="branch.percent !== null"
                                    class="text-muted-foreground"
                                >
                                    · {{ branch.percent }}%</span
                                >
                            </dd>
                        </div>
                        <div class="grid grid-cols-2 gap-1 lg:block">
                            <dt class="text-xs text-muted-foreground">
                                Balance
                            </dt>
                            <dd class="tabular-nums">
                                {{ balanceText(branch) }}
                            </dd>
                        </div>
                        <div class="grid grid-cols-2 gap-1 lg:block">
                            <dt class="text-xs text-muted-foreground">
                                {{ neededLabel(branch) }}
                            </dt>
                            <dd class="whitespace-nowrap tabular-nums">
                                {{ neededText(branch) }}
                            </dd>
                        </div>
                    </dl>
                    <div class="flex lg:justify-end">
                        <Button
                            v-if="branch.open"
                            type="button"
                            variant="outline"
                            class="h-11 lg:h-9"
                            :data-testid="`target-set-${branch.code}`"
                            @click="startEdit(branch, null)"
                            >{{
                                branch.targetSen === null
                                    ? 'Set target'
                                    : 'Change'
                            }}</Button
                        >
                    </div>
                </div>
                <ul
                    v-if="expanded[branch.branchId]"
                    class="divide-y border-t"
                    :aria-label="`Doctors at ${branch.branch}`"
                >
                    <li
                        v-for="doctor in branch.doctors"
                        :key="doctor.doctorId"
                        :class="grid"
                        :data-testid="`target-doctor-${doctor.doctorId}`"
                    >
                        <p class="min-w-0 pl-4 font-medium break-words">
                            {{ doctor.name }}
                        </p>
                        <dl class="contents">
                            <div class="grid grid-cols-2 gap-1 lg:block">
                                <dt class="text-xs text-muted-foreground">
                                    Target
                                </dt>
                                <dd class="tabular-nums">
                                    {{ money(doctor.targetSen) }}
                                </dd>
                            </div>
                            <div class="grid grid-cols-2 gap-1 lg:block">
                                <dt class="text-xs text-muted-foreground">
                                    Achieved
                                </dt>
                                <dd class="whitespace-nowrap tabular-nums">
                                    {{ money(doctor.achievedSen)
                                    }}<span
                                        v-if="doctor.percent !== null"
                                        class="text-muted-foreground"
                                    >
                                        · {{ doctor.percent }}%</span
                                    >
                                </dd>
                            </div>
                            <div class="grid grid-cols-2 gap-1 lg:block">
                                <dt class="text-xs text-muted-foreground">
                                    Balance
                                </dt>
                                <dd class="tabular-nums">
                                    {{ balanceText(doctor) }}
                                </dd>
                            </div>
                            <div class="grid grid-cols-2 gap-1 lg:block">
                                <dt class="text-xs text-muted-foreground">
                                    {{ neededLabel(doctor) }}
                                </dt>
                                <dd class="whitespace-nowrap tabular-nums">
                                    {{ neededText(doctor) }}
                                </dd>
                            </div>
                        </dl>
                        <div class="flex lg:justify-end">
                            <Button
                                v-if="
                                    branch.open &&
                                    (doctor.canSet || doctor.targetSen !== null)
                                "
                                type="button"
                                variant="outline"
                                class="h-11 lg:h-9"
                                @click="startEdit(branch, doctor)"
                                >{{
                                    doctor.targetSen === null
                                        ? 'Set target'
                                        : 'Change'
                                }}</Button
                            >
                            <span
                                v-else-if="branch.open"
                                class="text-xs text-muted-foreground"
                                >Not at this branch this month</span
                            >
                        </div>
                    </li>
                </ul>
            </li>
        </ul>

        <Dialog v-model:open="open">
            <DialogContent
                class="w-[calc(100%-2rem)] max-w-[480px]"
                data-testid="target-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        changing ? 'Change target' : 'Set target'
                    }}</DialogTitle>
                    <DialogDescription
                        >{{ editing?.title }}, {{ monthTitle(view.month) }}.
                        <template v-if="changing"
                            >Leave the amount empty to clear the
                            target.</template
                        ></DialogDescription
                    >
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="submit">
                    <label class="grid gap-1 text-sm"
                        >Target (RM)
                        <Input
                            v-model="form.amount"
                            inputmode="decimal"
                            autocomplete="off"
                            class="h-11 lg:h-9"
                            data-testid="target-amount"
                        />
                    </label>
                    <InputError :message="form.errors.amount" />
                    <label v-if="changing" class="grid gap-1 text-sm"
                        >{{
                            clearing
                                ? 'Why is it cleared?'
                                : 'Why is it changed?'
                        }}
                        <textarea
                            v-model="form.reason"
                            maxlength="200"
                            rows="2"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            data-testid="target-reason"
                        />
                    </label>
                    <InputError :message="form.errors.reason" />
                    <InputError :message="form.errors.idempotency_key" />
                    <InputError :message="form.errors.expected_branch_id" />
                    <DialogFooter
                        class="flex-col gap-1 sm:flex-col sm:items-end"
                    >
                        <Button
                            type="submit"
                            class="h-11 lg:h-9"
                            :disabled="Boolean(missing) || form.processing"
                            data-testid="target-save"
                            >{{
                                clearing ? 'Clear target' : 'Save target'
                            }}</Button
                        >
                        <p
                            v-if="missing"
                            class="text-xs text-muted-foreground"
                            data-testid="target-missing"
                        >
                            {{ missing }}
                        </p>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
