<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status';

// STK-24: one controlled medicine's register, read from the stock ledger, with its second-person checks.
type Line = {
    id: number;
    when: string;
    what: string;
    in: string | null;
    out: string | null;
    balance: string;
    batch: string | null;
    location: string;
    reference: string;
    recordedBy: string;
};
type Check = {
    publicId: string;
    sequence: number;
    outcome: 'matches' | 'differs';
    reason: string | null;
    note: string | null;
    ledger: string;
    counted: string;
    checkedBy: string;
    checkedOn: string;
};
type Register = {
    expectedBranchId: number;
    branchName: string;
    sku: {
        publicId: string;
        medicine: string;
        skuCode: string;
        stockUnit: string;
    };
    filters: { from: string; to: string };
    opening: string;
    closing: string;
    lines: { page: number; lastPage: number; total: number; rows: Line[] };
    balanceNow: string;
    newestMovementId: number;
    movementsSinceCheck: number;
    checks: Check[];
    can: { check: boolean };
    checkBlockedReason: string | null;
};

const props = defineProps<{ register: Register }>();

const reasons: Record<string, string> = {
    count_to_follow: 'A governed stock count will follow',
    stock_elsewhere: 'Stock is held somewhere else',
    investigate: 'Difference to investigate',
    other: 'Other (add a note)',
};

const from = ref(props.register.filters.from);
const to = ref(props.register.filters.to);
const base = computed(
    () => `/inventory/controlled-register/${props.register.sku.publicId}`,
);
const visit = (page = 1) =>
    router.get(
        base.value,
        { from: from.value, to: to.value, page },
        { preserveScroll: true, preserveState: true },
    );
const printHref = computed(
    () =>
        `${base.value}/print?from=${encodeURIComponent(props.register.filters.from)}&to=${encodeURIComponent(props.register.filters.to)}`,
);

// Exact thousandths from the typed text, so "10" and "10.000" compare equal with no float maths.
const milli = (text: string): number | null => {
    const match = /^(\d{1,9})(?:\.(\d{1,3}))?$/.exec(text.trim());

    return match
        ? Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'))
        : null;
};

const form = reactive({
    counted: '',
    reason: '',
    note: '',
    key: crypto.randomUUID(),
});
const errors = ref<Record<string, string>>({});
const busy = ref(false);
const differs = computed(() => {
    const counted = milli(form.counted);
    const ledger = milli(props.register.balanceNow);

    return counted !== null && ledger !== null && counted !== ledger;
});
watch(differs, (value) => {
    if (!value) {
        form.reason = '';
        form.note = '';
    }
});

const submit = () => {
    errors.value = {};

    if (form.counted.trim() === '') {
        errors.value = { counted: 'Enter the quantity you counted.' };
    } else if (milli(form.counted) === null) {
        errors.value = {
            counted: 'Enter a number, with up to 3 decimal places.',
        };
    } else if (differs.value && form.reason === '') {
        errors.value = {
            reason: 'The count differs from the register. Choose a reason.',
        };
    } else if (
        differs.value &&
        form.reason === 'other' &&
        form.note.trim() === ''
    ) {
        errors.value = { note: 'Add a note to explain the difference.' };
    }

    if (Object.keys(errors.value).length) {
        void nextTick(() =>
            document
                .querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus(),
        );

        return;
    }

    router.post(
        `${base.value}/checks`,
        {
            expected_branch_id: props.register.expectedBranchId,
            idempotency_key: form.key,
            seen_movement_id: props.register.newestMovementId,
            counted: form.counted.trim(),
            reason: differs.value ? form.reason : null,
            note: differs.value && form.note.trim() ? form.note.trim() : null,
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => {
                form.counted = '';
                form.reason = '';
                form.note = '';
                form.key = crypto.randomUUID();
            },
            onError: (bag) => {
                errors.value = bag as Record<string, string>;

                // A stale page or an earlier key needs a fresh one after the reload.
                if (bag.seen_movement_id || bag.idempotency_key) {
                    form.key = crypto.randomUUID();
                }
            },
        },
    );
};
const formError = computed(
    () => errors.value.seen_movement_id ?? errors.value.idempotency_key ?? null,
);
</script>

<template>
    <Head :title="`Controlled register: ${register.sku.medicine}`" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory/controlled-register"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" /> Controlled
            register</ActionLink
        >
        <PageHeader
            :title="register.sku.medicine"
            :description="`${register.sku.skuCode} · ${register.branchName} · on hand now ${register.balanceNow} ${register.sku.stockUnit}`"
        />
        <p
            class="rounded-md border border-warning bg-warning/10 p-3 text-sm font-medium text-warning-strong"
            data-testid="cd-draft-banner"
        >
            Draft register — pending pharmacist review. Not a statutory register
            until a pharmacist approves its format.
        </p>

        <form
            class="flex flex-wrap items-end gap-3"
            aria-label="Register dates"
            @submit.prevent="visit(1)"
        >
            <label class="min-w-0 text-sm font-medium"
                >From<DateField v-model="from" class="mt-1"
            /></label>
            <label class="min-w-0 text-sm font-medium"
                >To<DateField v-model="to" class="mt-1"
            /></label>
            <Button type="submit" variant="outline" class="min-h-11"
                >Show</Button
            >
            <ActionLink
                :href="printHref"
                variant="outline"
                class="min-h-11"
                data-testid="cd-print"
                >Print register</ActionLink
            >
        </form>

        <section aria-labelledby="cd-lines-heading" class="space-y-3">
            <h2 id="cd-lines-heading" class="text-xl font-semibold">
                Movements ({{ register.lines.total }})
            </h2>
            <p class="text-sm">
                Balance at the start:
                <span class="font-medium tabular-nums">{{
                    register.opening
                }}</span>
                · at the end:
                <span class="font-medium tabular-nums">{{
                    register.closing
                }}</span>
                {{ register.sku.stockUnit }}
            </p>
            <p
                v-if="!register.lines.rows.length"
                class="text-sm text-muted-foreground"
            >
                No movements in these dates.
            </p>
            <template v-else>
                <ul class="space-y-2 lg:hidden" aria-label="Movements">
                    <li
                        v-for="line in register.lines.rows"
                        :key="line.id"
                        class="space-y-1 rounded-md border p-3 text-sm"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <p class="font-medium">{{ line.what }}</p>
                            <p class="text-muted-foreground">{{ line.when }}</p>
                        </div>
                        <p class="tabular-nums">
                            <span v-if="line.in">In {{ line.in }}</span>
                            <span v-if="line.in && line.out"> · </span>
                            <span v-if="line.out">Out {{ line.out }}</span>
                            · Balance
                            <span class="font-medium">{{ line.balance }}</span>
                        </p>
                        <p class="break-words text-muted-foreground">
                            {{ line.location
                            }}<template v-if="line.batch">
                                · batch {{ line.batch }}</template
                            >
                        </p>
                        <p class="text-muted-foreground">
                            {{ line.reference }} · {{ line.recordedBy }}
                        </p>
                    </li>
                </ul>
                <div class="hidden overflow-x-auto rounded-md border lg:block">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            Movements with a running balance
                        </caption>
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th scope="col" class="p-3 font-medium">
                                    Date and time
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    What happened
                                </th>
                                <th
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    In
                                </th>
                                <th
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    Out
                                </th>
                                <th
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    Balance
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Batch
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Location
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Reference
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Recorded by
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in register.lines.rows"
                                :key="line.id"
                                class="border-t align-top"
                            >
                                <td class="p-3 whitespace-nowrap">
                                    {{ line.when }}
                                </td>
                                <td class="p-3">{{ line.what }}</td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ line.in ?? '' }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ line.out ?? '' }}
                                </td>
                                <td
                                    class="p-3 text-right font-medium tabular-nums"
                                >
                                    {{ line.balance }}
                                </td>
                                <td class="p-3 break-all">
                                    {{ line.batch ?? '—' }}
                                </td>
                                <td class="p-3">{{ line.location }}</td>
                                <td class="p-3 whitespace-nowrap">
                                    {{ line.reference }}
                                </td>
                                <td class="p-3">{{ line.recordedBy }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <CompactPagination
                    v-if="register.lines.lastPage > 1"
                    :current-page="register.lines.page"
                    :last-page="register.lines.lastPage"
                    :total="register.lines.total"
                    @change="visit"
                />
            </template>
        </section>

        <section
            aria-labelledby="cd-check-heading"
            class="space-y-3 rounded-md border p-4"
            data-testid="cd-check"
        >
            <h2 id="cd-check-heading" class="text-xl font-semibold">
                Check this register
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    register.movementsSinceCheck === 1
                        ? '1 movement'
                        : `${register.movementsSinceCheck} movements`
                }}
                since the last check. Count what is physically on hand at this
                branch and compare it with the register:
                <span class="font-medium text-foreground tabular-nums"
                    >{{ register.balanceNow }}
                    {{ register.sku.stockUnit }}</span
                >.
            </p>
            <p
                v-if="register.checkBlockedReason"
                class="rounded-md border border-warning bg-warning/10 p-3 text-sm text-warning-strong"
                data-testid="cd-check-blocked"
            >
                {{ register.checkBlockedReason }}
            </p>
            <form
                v-else-if="register.can.check"
                class="max-w-xl space-y-3"
                novalidate
                @submit.prevent="submit"
            >
                <p
                    v-if="formError"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ formError }}
                </p>
                <label class="block text-sm font-medium" for="cd-counted"
                    >Quantity counted ({{ register.sku.stockUnit }})</label
                >
                <Input
                    id="cd-counted"
                    v-model="form.counted"
                    inputmode="decimal"
                    autocomplete="off"
                    class="min-h-11 max-w-xs"
                    :aria-invalid="errors.counted ? 'true' : undefined"
                    :aria-describedby="
                        errors.counted ? 'cd-counted-error' : undefined
                    "
                />
                <InputError id="cd-counted-error" :message="errors.counted" />
                <template v-if="differs">
                    <p class="text-sm font-medium text-warning-strong">
                        This differs from the register. Recording it does not
                        change stock; correct stock with a governed stock count.
                    </p>
                    <label class="block text-sm font-medium" for="cd-reason"
                        >Reason</label
                    >
                    <select
                        id="cd-reason"
                        v-model="form.reason"
                        class="min-h-11 w-full rounded-md border bg-background px-3 text-sm"
                        :aria-invalid="errors.reason ? 'true' : undefined"
                        :aria-describedby="
                            errors.reason ? 'cd-reason-error' : undefined
                        "
                    >
                        <option value="" disabled>Choose a reason</option>
                        <option
                            v-for="(label, value) in reasons"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <InputError id="cd-reason-error" :message="errors.reason" />
                    <label class="block text-sm font-medium" for="cd-note"
                        >Note{{
                            form.reason === 'other' ? '' : ' (optional)'
                        }}</label
                    >
                    <Input
                        id="cd-note"
                        v-model="form.note"
                        maxlength="200"
                        class="min-h-11"
                        :aria-invalid="errors.note ? 'true' : undefined"
                        :aria-describedby="
                            errors.note ? 'cd-note-error' : undefined
                        "
                    />
                    <InputError id="cd-note-error" :message="errors.note" />
                </template>
                <Button type="submit" class="min-h-11" :disabled="busy"
                    >Record check</Button
                >
            </form>
        </section>

        <section aria-labelledby="cd-checks-heading" class="space-y-3">
            <h2 id="cd-checks-heading" class="text-xl font-semibold">
                Earlier checks
            </h2>
            <p
                v-if="!register.checks.length"
                class="text-sm text-muted-foreground"
            >
                This register has not been checked yet.
            </p>
            <ul v-else class="space-y-2" aria-label="Earlier checks">
                <li
                    v-for="check in register.checks"
                    :key="check.publicId"
                    class="space-y-1 rounded-md border p-3 text-sm"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            :status="check.outcome"
                            :label="
                                check.outcome === 'matches'
                                    ? 'Matches'
                                    : 'Differs'
                            "
                            :tone="
                                check.outcome === 'matches'
                                    ? 'success'
                                    : 'warning'
                            "
                        />
                        <span
                            >{{ check.checkedOn }} · {{ check.checkedBy }}</span
                        >
                    </div>
                    <p class="tabular-nums">
                        Register {{ check.ledger }} · counted
                        {{ check.counted }} {{ register.sku.stockUnit }}
                    </p>
                    <p v-if="check.reason" class="text-muted-foreground">
                        {{ reasons[check.reason] ?? check.reason
                        }}<template v-if="check.note"
                            >: {{ check.note }}</template
                        >
                    </p>
                </li>
            </ul>
        </section>
    </main>
</template>
