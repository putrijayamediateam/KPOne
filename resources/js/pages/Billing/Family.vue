<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import type { FamilyBillPage } from '@/types/billing';
import { myr, toSen } from '@/types/billing';

// BIL-39: the family bill. Read only here: each member keeps their own invoice, coverage and receipts, and every
// money action other than the family payment stays on the member's own Billing page.
const props = defineProps<{ family: FamilyBillPage }>();

const names = computed(() => {
    const all = props.family.members.map((member) => member.patientName);

    return all.length > 1
        ? `${all.slice(0, -1).join(', ')} and ${all[all.length - 1]}`
        : (all[0] ?? '');
});
const base = computed(
    () =>
        `/visits/${encodeURIComponent(props.family.anchorVisitNumber)}/billing/family`,
);
const payable = computed(() =>
    props.family.members.filter((member) => !member.invoice.correctionHold),
);
const form = reactive({ amount: '', method: '', reference: '' });
const busy = ref(false);
const error = ref('');
// One retry key per attempt: a repeat of the same press returns the same receipts.
const attemptKey = ref(crypto.randomUUID());
const method = computed(() =>
    props.family.methods.find((m) => m.code === form.method),
);
const amountSen = computed(() => toSen(form.amount));
const amountNumber = computed(() =>
    amountSen.value === null ? null : Number(amountSen.value),
);
const canSubmit = computed(
    () =>
        props.family.can.pay &&
        props.family.totalDueNowSen > 0 &&
        amountNumber.value !== null &&
        amountNumber.value > 0 &&
        amountNumber.value <= props.family.totalDueNowSen &&
        form.method !== '' &&
        (!method.value?.requiresReference || form.reference.trim() !== ''),
);
const fillTotal = () => {
    form.amount = (props.family.totalDueNowSen / 100).toFixed(2);
};
const send = (
    url: string,
    data: Record<string, unknown>,
    after?: () => void,
) => {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    router.post(
        url,
        { expected_branch_id: props.family.expectedBranchId, ...data },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The request could not be completed.';
            },
            onSuccess: () => after?.(),
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
const pay = () => {
    if (!canSubmit.value || amountSen.value === null) {
        return;
    }

    send(
        `${base.value}/payments`,
        {
            idempotency_key: attemptKey.value,
            amount_sen: amountSen.value,
            method: form.method,
            reference: form.reference.trim() || null,
            expected_total_sen: props.family.totalDueNowSen,
            members: payable.value.map((member) => ({
                invoice_public_id: member.invoice.publicId,
                lock_version: member.invoice.lockVersion,
            })),
        },
        () => {
            form.amount = '';
            form.reference = '';
            attemptKey.value = crypto.randomUUID();
        },
    );
};
const remove = (invoicePublicId: string) =>
    send(`${base.value}/remove`, { invoice_public_id: invoicePublicId });
const restore = (invoicePublicId: string) =>
    send(`${base.value}/restore`, { invoice_public_id: invoicePublicId });
const fieldClass =
    'mt-1 h-10 w-full rounded-[10px] border border-input bg-field-fill px-3 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

const anchorBillingUrl = computed(
    () =>
        props.family.members.find(
            (member) => member.visitNumber === props.family.anchorVisitNumber,
        )?.billingUrl ??
        `/visits/${encodeURIComponent(props.family.anchorVisitNumber)}/billing`,
);
</script>

<template>
    <Head title="Family bill" />
    <main
        class="mx-auto w-full max-w-6xl space-y-4 px-4 py-6 md:px-6 lg:px-8 max-lg:[&_[data-slot=button]]:min-h-11 max-lg:[&_input]:min-h-11 max-lg:[&_select]:min-h-11"
        data-testid="family-bill"
    >
        <PageHeader
            title="Family bill"
            :description="`${family.branch} · ${names}`"
        >
            <template #actions>
                <ActionLink
                    :href="anchorBillingUrl"
                    variant="outline"
                    data-testid="family-bill-back"
                    ><ArrowLeft class="size-3.5" aria-hidden="true" />Back to
                    billing</ActionLink
                >
            </template>
        </PageHeader>

        <p
            class="rounded-xl border bg-card-quiet px-4 py-3 text-sm text-muted-foreground"
        >
            Each person keeps their own invoice and receipt. Panel shares, pay
            later, adjustments and printing stay on each person's billing page.
        </p>

        <EmptyState
            v-if="family.members.length === 0"
            title="No open family invoices"
            :description="`No family member has a finalised invoice waiting at ${family.branch}.`"
        />

        <div
            v-else
            class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]"
        >
            <div class="grid min-w-0 gap-4" data-testid="family-bill-members">
                <section
                    v-for="member in family.members"
                    :key="member.visitNumber"
                    class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
                    :aria-label="`${member.patientName}, ${member.invoice.number ?? 'invoice'}`"
                    data-testid="family-bill-member"
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                        <div class="min-w-0 sm:flex-1">
                            <h2 class="text-[15px] font-semibold break-words">
                                {{ member.patientName }}
                            </h2>
                            <p class="text-[13px] text-muted-foreground">
                                {{ member.patientNumber }} ·
                                {{ member.invoice.number }} ·
                                {{ member.coverage ?? 'Self-pay' }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <ActionLink
                                :href="member.billingUrl"
                                variant="outline"
                                >Open billing</ActionLink
                            >
                            <Button
                                v-if="family.can.remove"
                                type="button"
                                variant="outline"
                                :disabled="busy"
                                :data-testid="`family-bill-remove-${member.visitNumber}`"
                                @click="remove(member.invoice.publicId)"
                                >Remove from family bill</Button
                            >
                        </div>
                    </div>
                    <p v-if="member.invoice.correctionHold" class="mt-2">
                        <StatusBadge
                            status="review_required"
                            label="Review required"
                            tone="neutral"
                        /><span class="ml-2 text-xs text-muted-foreground"
                            >Not in the family total until the bill is
                            corrected.</span
                        >
                    </p>
                    <ul
                        v-if="family.linesShown && member.invoice.lines.length"
                        class="mt-3 divide-y border-t text-sm"
                        :aria-label="`Items for ${member.patientName}`"
                    >
                        <li
                            v-for="(line, index) in member.invoice.lines"
                            :key="index"
                            class="flex min-h-10 items-center justify-between gap-3 py-2"
                        >
                            <span class="min-w-0 break-words">{{
                                line.name
                            }}</span>
                            <span class="whitespace-nowrap tabular-nums">{{
                                myr(line.totalSen)
                            }}</span>
                        </li>
                    </ul>
                    <dl class="mt-2 grid gap-1 border-t pt-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Invoice total</dt>
                            <dd class="whitespace-nowrap tabular-nums">
                                {{ myr(member.invoice.state.total) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3 font-semibold">
                            <dt>Due now</dt>
                            <dd class="whitespace-nowrap tabular-nums">
                                {{ myr(member.invoice.state.due_now) }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <aside
                class="grid min-w-0 gap-4 rounded-xl border bg-card p-4 sm:p-5"
                aria-label="Family total"
                data-testid="family-bill-total"
            >
                <div>
                    <p class="text-[13px] text-muted-foreground">
                        Family due now
                    </p>
                    <p
                        class="text-[34px] leading-tight font-bold tracking-[-0.035em] whitespace-nowrap tabular-nums"
                    >
                        {{ myr(family.totalDueNowSen) }}
                    </p>
                </div>
                <dl class="grid gap-1 border-t pt-2 text-sm">
                    <div
                        v-for="member in family.members"
                        :key="member.visitNumber"
                        class="flex justify-between gap-3"
                    >
                        <dt class="min-w-0 break-words">
                            {{ member.patientName }}
                        </dt>
                        <dd class="whitespace-nowrap tabular-nums">
                            {{
                                member.invoice.correctionHold
                                    ? '–'
                                    : myr(member.invoice.state.due_now)
                            }}
                        </dd>
                    </div>
                </dl>
                <p class="text-xs text-muted-foreground">
                    Oldest invoice first: a family payment pays each invoice in
                    this order until the money runs out.
                </p>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded-xl border border-destructive/25 bg-destructive/10 p-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <form
                    v-if="family.can.pay && family.totalDueNowSen > 0"
                    class="grid gap-3 border-t pt-3"
                    data-testid="family-bill-pay"
                    @submit.prevent="pay"
                >
                    <label class="text-[13px] font-medium" for="family-amount"
                        >Amount received (RM)
                        <input
                            id="family-amount"
                            v-model="form.amount"
                            inputmode="decimal"
                            autocomplete="off"
                            :class="fieldClass"
                            placeholder="0.00"
                        />
                    </label>
                    <button
                        type="button"
                        class="w-fit text-[13px] font-medium text-link underline-offset-2 hover:underline"
                        @click="fillTotal"
                    >
                        Use the family total
                    </button>
                    <label class="text-[13px] font-medium" for="family-method"
                        >Payment method
                        <select
                            id="family-method"
                            v-model="form.method"
                            :class="fieldClass"
                        >
                            <option value="" disabled>Select a method</option>
                            <option
                                v-for="m in family.methods"
                                :key="m.code"
                                :value="m.code"
                            >
                                {{ m.name }}
                            </option>
                        </select>
                    </label>
                    <label
                        v-if="method?.requiresReference"
                        class="text-[13px] font-medium"
                        for="family-reference"
                        >Reference
                        <input
                            id="family-reference"
                            v-model="form.reference"
                            maxlength="100"
                            autocomplete="off"
                            :class="fieldClass"
                        />
                    </label>
                    <Button type="submit" :disabled="busy || !canSubmit"
                        >Take family payment</Button
                    >
                    <p class="text-xs text-muted-foreground">
                        One receipt is recorded for each person's invoice.
                    </p>
                </form>
                <p
                    v-if="family.paused"
                    class="rounded-xl border border-warning/25 bg-warning-wash p-3 text-xs text-warning-strong"
                    role="status"
                >
                    Today's cash closing is waiting for approval, so payments
                    are paused.
                </p>
            </aside>
        </div>

        <section
            v-if="family.removed.length"
            class="rounded-xl border bg-card p-4 sm:p-5"
            aria-labelledby="family-removed-heading"
            data-testid="family-bill-removed"
        >
            <h2 id="family-removed-heading" class="text-[15px] font-semibold">
                Removed from the family bill
            </h2>
            <p class="text-[13px] text-muted-foreground">
                These people pay on their own billing page.
            </p>
            <ul class="mt-2 divide-y">
                <li
                    v-for="person in family.removed"
                    :key="person.visitNumber"
                    class="flex min-h-12 flex-wrap items-center justify-between gap-2 py-2"
                >
                    <span class="min-w-0 text-sm"
                        ><span class="font-medium">{{
                            person.patientName
                        }}</span>
                        <span class="text-muted-foreground">
                            · {{ person.patientNumber }} ·
                            {{ person.invoiceNumber }}</span
                        ></span
                    >
                    <Button
                        v-if="family.can.remove"
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="restore(person.invoicePublicId)"
                        >Put back</Button
                    >
                </li>
            </ul>
        </section>
    </main>
</template>
