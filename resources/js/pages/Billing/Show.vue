<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type {
    BillingPage,
    BillingState,
    Receipt,
    Responsibility,
} from '@/types/billing';
import { myr, toSen } from '@/types/billing';

const props = defineProps<{ billing: BillingPage }>();
const totals: Array<[keyof BillingState, string]> = [
    ['total', 'Total'],
    ['self_pay', 'Self-pay received'],
    ['panel', 'Panel responsibility'],
    ['deferred', 'Approved pay later'],
    ['due_now', 'Due now'],
];
const page = usePage();
const busy = ref(false);
const contextCleared = ref(false);
const error = ref('');
const tab = ref('all');
const form = reactive({
    amount: '',
    method: '',
    reference: '',
    reason: '',
    due_date: '',
    panel_id: '',
    member_reference: '',
    recording_error_only: false,
});
let paymentKey = crypto.randomUUID();
const clearEntry = () => {
    Object.assign(form, {
        amount: '',
        method: '',
        reference: '',
        reason: '',
        due_date: '',
        panel_id: '',
        member_reference: '',
        recording_error_only: false,
    });
    error.value = '';
    paymentKey = crypto.randomUUID();
};
const stopStart = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        contextCleared.value = true;
        clearEntry();
    }
});
const stopSuccess = router.on('success', (event) => {
    if (event.detail.page.component === 'Billing/Show') {
        contextCleared.value = false;
    }
});
watch(
    () => [
        props.billing.visit.visitNumber,
        page.props.branchContext?.active?.id,
    ],
    clearEntry,
);
onUnmounted(() => {
    stopStart();
    stopSuccess();
});
const base = computed(
    () =>
        `/visits/${encodeURIComponent(props.billing.visit.visitNumber)}/billing`,
);
const invoiceBase = computed(
    () => `${base.value}/${props.billing.invoice?.publicId}`,
);
const lines = computed(
    () =>
        props.billing.invoice?.lines.filter(
            (l) =>
                tab.value === 'all' ||
                (tab.value === 'items'
                    ? l.type === 'medicine'
                    : l.type !== 'medicine'),
        ) ?? [],
);
const post = (
    url: string,
    extra: Record<string, string | number | boolean | null> = {},
) => {
    busy.value = true;
    error.value = '';
    router.post(
        url,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            lock_version: props.billing.invoice?.lockVersion ?? null,
            ...extra,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The request could not be completed.';
            },
            onSuccess: () => {
                paymentKey = crypto.randomUUID();
                form.amount = '';
                form.reference = '';
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
const payment = () => {
    const sen = toSen(form.amount);

    if (sen === null) {
        error.value =
            'Enter an exact MYR amount with at most two decimal places.';

        return;
    }

    post(`${invoiceBase.value}/payments`, {
        amount_sen: sen,
        method: form.method,
        reference: form.reference || null,
        idempotency_key: paymentKey,
    });
};
const propose = (kind: string) => {
    const sen = toSen(form.amount);

    if (sen === null) {
        error.value = 'Enter an exact MYR amount.';

        return;
    }

    post(`${invoiceBase.value}/responsibility/${kind}`, {
        amount_sen: sen,
        reason: form.reason,
        due_date: form.due_date || null,
        panel_id: form.panel_id || null,
        member_reference: form.member_reference || null,
    });
};
const approve = (kind: string, proposal: Responsibility) =>
    post(
        `${invoiceBase.value}/responsibility/${kind}/${proposal.publicId}/approve`,
        { proposal_lock_version: proposal.lockVersion },
    );
const reverse = (receipt: Receipt) =>
    post(`${invoiceBase.value}/payments/${receipt.publicId}/reverse`, {
        payment_lock_version: receipt.lockVersion,
        reason: form.reason,
        recording_error_only: form.recording_error_only,
    });
const complete = () => {
    if (
        window.confirm(
            'Complete Visitation? This preserves the finalized financial evidence and closes ordinary Visit editing.',
        )
    ) {
        post(`${base.value}/complete`, {
            visit_lock_version: props.billing.visit.lockVersion,
        });
    }
};
</script>

<template>
    <Head title="Patient Billing" />
    <main v-if="contextCleared" class="p-4 text-sm" role="status">
        Billing context cleared.
        <Link href="/workspace" class="underline">Return to workspace</Link>
    </main>
    <main v-else class="mx-auto w-full max-w-[1600px] px-4 py-4 text-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h1 class="font-medium">
                {{
                    billing.visit.status === 'completed'
                        ? 'Completed Visit'
                        : 'Patient Billing'
                }}
            </h1>
            <Link href="/registration" class="underline">Registration</Link>
        </div>
        <p
            v-if="error"
            role="alert"
            class="mb-3 rounded border border-red-200 bg-red-50 p-2 text-red-800"
        >
            {{ error }}
        </p>
        <div
            class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,2.5fr)_minmax(0,1.5fr)]"
        >
            <aside class="space-y-3 border-r pr-3">
                <div>
                    <h2 class="font-medium">{{ billing.patient.name }}</h2>
                    <p class="text-xs text-muted-foreground">
                        {{ billing.patient.patientNumber }}
                    </p>
                </div>
                <p>{{ billing.visit.visitNumber }}<br />{{ billing.branch }}</p>
                <p>Registration coverage: {{ billing.visit.coverage }}</p>
                <p class="text-xs text-muted-foreground">
                    Registration coverage is not financial approval.
                </p>
                <p v-if="billing.visit.completedAt">
                    Completed {{ billing.visit.completedAt }}
                </p>
                <div v-if="billing.oldOutstanding.length">
                    <h2 class="font-medium">Earlier outstanding</h2>
                    <p
                        v-for="debt in billing.oldOutstanding"
                        :key="debt.invoiceNumber"
                        class="mt-2"
                    >
                        <Link :href="debt.url" class="underline"
                            >{{ debt.invoiceNumber }} ·
                            {{ myr(debt.amountSen) }}</Link
                        ><br /><span class="text-xs"
                            >Due {{ debt.dueDate }}. Retained on its originating
                            Invoice.</span
                        >
                    </p>
                </div>
            </aside>
            <section class="min-w-0">
                <nav
                    aria-label="Invoice content"
                    class="mb-3 flex gap-4 border-b"
                >
                    <button
                        v-for="value in [
                            'all',
                            'items',
                            'services',
                            'documents',
                        ]"
                        :key="value"
                        class="border-b px-1 py-2 capitalize focus-visible:outline-2"
                        :class="
                            tab === value
                                ? 'border-foreground'
                                : 'border-transparent'
                        "
                        :aria-pressed="tab === value"
                        @click="tab = value"
                    >
                        {{ value }}
                    </button>
                </nav>
                <template v-if="tab !== 'documents'"
                    ><p
                        v-if="!billing.invoice"
                        class="py-5 text-muted-foreground"
                    >
                        Awaiting Billing. Build the draft from completed
                        fulfilment and confirmed services. No stock is moved
                        here.
                    </p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b text-muted-foreground">
                                <tr>
                                    <th class="py-2">Charge</th>
                                    <th>Quantity</th>
                                    <th>Unit price</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(line, index) in lines"
                                    :key="index"
                                    class="border-b"
                                >
                                    <td class="py-3 pr-2">
                                        {{ line.name
                                        }}<span
                                            class="block text-muted-foreground"
                                            >{{ line.type }}</span
                                        >
                                    </td>
                                    <td>{{ line.quantity }} {{ line.unit }}</td>
                                    <td>{{ myr(line.unitPriceSen) }}</td>
                                    <td class="text-right">
                                        {{ myr(line.totalSen) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p
                            v-if="!lines.length"
                            class="py-3 text-muted-foreground"
                        >
                            No itemized lines in this authorized view.
                        </p>
                    </div>
                </template>
                <div v-else class="space-y-3">
                    <a
                        v-if="billing.can.print"
                        :href="`${invoiceBase}/print`"
                        target="_blank"
                        rel="noopener"
                        class="underline"
                        >Print Invoice</a
                    >
                    <p
                        v-for="receipt in billing.payments"
                        :key="receipt.publicId"
                    >
                        {{ receipt.number }} · {{ myr(receipt.amountSen) }} ·
                        {{ receipt.method }} · {{ receipt.status
                        }}<a
                            v-if="billing.can.print"
                            :href="`${invoiceBase}/receipts/${receipt.publicId}/print`"
                            target="_blank"
                            rel="noopener"
                            class="ml-2 underline"
                            >Print Receipt</a
                        ><Button
                            v-if="
                                billing.can.reverse &&
                                receipt.status === 'posted'
                            "
                            variant="outline"
                            size="sm"
                            :disabled="
                                busy ||
                                !form.recording_error_only ||
                                !form.reason
                            "
                            @click="reverse(receipt)"
                            >Reverse recording error</Button
                        >
                    </p>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Button
                        v-if="billing.can.build"
                        variant="outline"
                        size="sm"
                        :disabled="busy"
                        @click="post(`${base}/build`)"
                        >Build / refresh draft</Button
                    ><Button
                        v-if="billing.can.finalize"
                        size="sm"
                        :disabled="busy"
                        @click="post(`${invoiceBase}/finalize`)"
                        >Finalize Invoice</Button
                    >
                </div>
            </section>
            <aside class="space-y-3 border-l pl-3">
                <template v-if="billing.invoice"
                    ><h2 class="font-medium">
                        {{ billing.invoice.number ?? 'Draft Invoice' }} ·
                        {{ billing.invoice.status }}
                    </h2>
                    <dl class="grid grid-cols-2 gap-y-1">
                        <template v-for="[key, label] in totals" :key="key"
                            ><dt>{{ label }}</dt>
                            <dd class="text-right tabular-nums">
                                {{ myr(billing.invoice.state[key]) }}
                            </dd></template
                        >
                    </dl>
                    <p v-if="billing.invoice.correctionHold" role="status">
                        Correction hold — review required.
                    </p></template
                >
                <div
                    v-if="
                        billing.can.pay ||
                        billing.can.panelPropose ||
                        billing.can.deferPropose
                    "
                    class="space-y-2 border-t pt-3"
                >
                    <label class="block"
                        >Amount (MYR)<input
                            v-model="form.amount"
                            inputmode="decimal"
                            class="billing-input"
                            autocomplete="off"
                    /></label>
                    <template v-if="billing.can.pay"
                        ><label class="block"
                            >Payment method<select
                                v-model="form.method"
                                class="billing-input"
                            >
                                <option value="">Select method</option>
                                <option
                                    v-for="method in billing.methods"
                                    :key="method.code"
                                    :value="method.code"
                                >
                                    {{ method.name }}
                                </option>
                            </select></label
                        ><label class="block"
                            >Transaction reference (no card details)<input
                                v-model="form.reference"
                                class="billing-input"
                                maxlength="100"
                                autocomplete="off" /></label
                        ><Button
                            size="sm"
                            :disabled="busy || !form.method"
                            @click="payment"
                            >Add Payment</Button
                        ></template
                    >
                    <details
                        v-if="
                            billing.can.panelPropose || billing.can.deferPropose
                        "
                        class="border-t pt-2"
                    >
                        <summary class="cursor-pointer py-1">
                            Panel / pay later request
                        </summary>
                        <label class="block"
                            >Reason / verification evidence<textarea
                                v-model="form.reason"
                                maxlength="500"
                                class="billing-input"
                            /></label
                        ><template v-if="billing.can.panelPropose"
                            ><label class="block"
                                >Verified Panel<select
                                    v-model="form.panel_id"
                                    class="billing-input"
                                >
                                    <option value="">Select Panel</option>
                                    <option
                                        v-for="panel in billing.panels"
                                        :key="panel.id"
                                        :value="panel.id"
                                    >
                                        {{ panel.name }}
                                    </option>
                                </select></label
                            ><label class="block"
                                >Member reference (if required)<input
                                    v-model="form.member_reference"
                                    class="billing-input"
                                    maxlength="100" /></label
                            ><Button
                                variant="outline"
                                size="sm"
                                :disabled="busy"
                                @click="propose('panel')"
                                >Propose Panel responsibility</Button
                            ></template
                        ><template v-if="billing.can.deferPropose"
                            ><label class="mt-2 block"
                                >Pay later due date<input
                                    v-model="form.due_date"
                                    type="date"
                                    class="billing-input" /></label
                            ><Button
                                variant="outline"
                                size="sm"
                                :disabled="busy"
                                @click="propose('deferment')"
                                >Request pay later</Button
                            ></template
                        >
                    </details>
                </div>
                <div
                    v-for="kind in ['panel', 'deferment'] as const"
                    :key="kind"
                >
                    <template v-if="billing[kind]"
                        ><p class="text-xs">
                            {{ kind === 'panel' ? 'Panel' : 'Pay later' }}:
                            {{ billing[kind]!.status }} ·
                            {{ myr(billing[kind]!.amountSen) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ billing[kind]!.reason }}
                        </p>
                        <p v-if="billing[kind]!.panelName" class="text-xs">
                            {{ billing[kind]!.panelName
                            }}<span v-if="billing[kind]!.memberReference">
                                · {{ billing[kind]!.memberReference }}</span
                            >
                        </p>
                        <p v-if="billing[kind]!.dueDate" class="text-xs">
                            Due {{ billing[kind]!.dueDate }}
                        </p>
                        <Button
                            v-if="
                                billing[kind]!.status === 'proposed' &&
                                (kind === 'panel'
                                    ? billing.can.panelApprove
                                    : billing.can.deferApprove)
                            "
                            variant="outline"
                            size="sm"
                            :disabled="busy"
                            @click="approve(kind, billing[kind]!)"
                            >Independently approve</Button
                        ></template
                    >
                </div>
                <details v-if="billing.can.reverse || billing.can.void">
                    <summary class="cursor-pointer">
                        Governed correction
                    </summary>
                    <p class="text-xs">
                        Recording errors only. No refunds or post-completion
                        accounting correction.
                    </p>
                    <label class="block"
                        >Reason<textarea
                            v-model="form.reason"
                            class="billing-input"
                            maxlength="500"
                        /></label
                    ><label class="flex gap-2"
                        ><input
                            v-model="form.recording_error_only"
                            type="checkbox"
                        />No real refund: recording error only</label
                    ><Button
                        v-if="billing.can.void"
                        variant="outline"
                        size="sm"
                        :disabled="busy || !form.reason"
                        @click="
                            post(`${invoiceBase}/void`, { reason: form.reason })
                        "
                        >Void for reissue</Button
                    >
                </details>
                <Button
                    v-if="billing.can.complete"
                    class="w-full"
                    :disabled="busy"
                    @click="complete"
                    >Complete Visitation</Button
                >
                <p class="text-xs text-muted-foreground">
                    Billing never deducts stock. Panel and approved pay later
                    are not cash receipts.
                </p>
            </aside>
        </div>
    </main>
</template>

<style scoped>
.billing-input {
    display: block;
    width: 100%;
    margin-block: 0.25rem 0.5rem;
    padding: 0.4rem 0.5rem;
    border: 1px solid var(--border);
    border-radius: 0.4rem;
    background: var(--background);
    font: inherit;
}
.billing-input:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
</style>
