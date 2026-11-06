<script setup lang="ts">
import { computed } from 'vue';
import BillToRecipient from '@/components/billing/BillToRecipient.vue';
import { amount, myr } from '@/types/billing';
import type { BillToRecipientData } from '@/types/billTo';
import type { PrintLetterhead } from '@/types/letterhead';

/**
 * BIL-9 (owner decisions 2026-11-06): the issued invoice and receipt in the clinic's own paper layout. Used only for
 * versions issued with `layout: 'bil9'`; earlier versions keep the layout they were issued in (decision 4).
 */
type Line = {
    name: string;
    quantity: string;
    unit: string | null;
    unitPriceSen: number;
    totalSen: number;
    category: string;
};
const props = defineProps<{
    billing: {
        patient: {
            name: string;
            patientNumber: string;
            identifier: string | null;
            phone: string | null;
        };
        invoice: {
            number: string;
            finalizedAt?: string | null;
            status?: string;
            lines: Line[];
            categories?: { name: string; totalSen: number }[];
            adjustment: { subtotalSen: number; adjustmentSen: number };
            state?: {
                total: number;
                self_pay: number;
                panel: number;
                deferred: number;
                due_now: number;
            };
        };
    };
    receipt: {
        number: string;
        receivedAt: string;
        status: string;
        amountSen: number;
        method: string;
    } | null;
    billTo?: BillToRecipientData | null;
    letterhead: PrintLetterhead & {
        details: PrintLetterhead['details'] & {
            phone_secondary?: string | null;
        };
    };
    labelText: string | null;
    issued: { version: number; issuedAt: string; notes: string[] } | null;
}>();

const details = computed(() => props.letterhead.details);
const addressLines = computed(() =>
    [
        details.value.address_line_1,
        details.value.address_line_2,
        [details.value.postcode, details.value.city].filter(Boolean).join(' '),
        [details.value.state, details.value.country].filter(Boolean).join(', '),
    ].filter((line): line is string => !!line),
);
const phones = computed(() =>
    [details.value.phone, details.value.phone_secondary]
        .filter((phone): phone is string => !!phone)
        .join(' / '),
);
const invoice = computed(() => props.billing.invoice);
const isVoid = computed(
    () => !props.receipt && invoice.value.status === 'voided',
);
const adjustmentSen = computed(() => invoice.value.adjustment.adjustmentSen);
// "2.000" prints as "2"; "1.500" as "1.5".
const quantity = (value: string) =>
    value.includes('.') ? value.replace(/\.?0+$/, '') : value;
</script>

<template>
    <header
        class="mb-1 flex items-start gap-4 border-b-[0.5pt] border-current pb-3"
        aria-label="Clinic letterhead"
    >
        <img
            v-if="letterhead.logo"
            :src="letterhead.logo.url"
            alt="Clinic logo"
            class="size-[24mm] shrink-0 object-contain"
        />
        <div class="min-w-0 break-words" data-testid="letterhead-text">
            <h1 class="text-base font-bold uppercase">
                {{ details.clinic_name }}
            </h1>
            <p v-for="line in addressLines" :key="line" class="uppercase">
                {{ line }}
            </p>
            <p v-if="details.email">Email: {{ details.email }}</p>
            <p v-if="phones" data-testid="letterhead-phones">
                Tel: {{ phones }}
            </p>
        </div>
    </header>

    <h2 class="mt-5 text-center text-base font-bold underline">
        {{ receipt ? 'OFFICIAL RECEIPT' : 'OFFICIAL INVOICE' }}
    </h2>
    <div class="mt-2 mb-4 text-center" data-testid="issued-label">
        <p
            v-if="isVoid"
            class="mb-1 text-base font-bold tracking-widest"
            data-testid="void-label"
        >
            VOID
        </p>
        <p
            v-if="labelText"
            class="inline-block border-[1.5px] border-current px-3 py-0.5 text-[9pt] font-bold tracking-wider uppercase"
        >
            {{ labelText }}
        </p>
        <p v-if="issued" class="mt-1 text-[9pt]">
            Version {{ issued.version }} · issued {{ issued.issuedAt }} (branch
            time)
        </p>
        <p
            v-for="note in issued?.notes ?? []"
            :key="note"
            class="mx-auto mt-2 max-w-xl border border-current px-3 py-1 text-[9pt]"
        >
            {{ note }}
        </p>
    </div>

    <section class="my-3 grid grid-cols-2 gap-4" aria-label="Document details">
        <div class="min-w-0 break-words" aria-label="Patient recipient">
            <p class="font-bold">To</p>
            <p>
                <span v-if="!receipt && billTo">Patient: </span
                >{{ billing.patient.name }}
            </p>
            <p v-if="billing.patient.identifier">
                {{ billing.patient.identifier }}
            </p>
            <p v-if="billing.patient.phone">{{ billing.patient.phone }}</p>
        </div>
        <div class="min-w-0 text-right break-words">
            <p>
                <span class="font-bold">{{
                    receipt ? 'Receipt No:' : 'Invoice No:'
                }}</span>
                {{ receipt?.number ?? invoice.number }}
            </p>
            <p>
                <span class="font-bold">Date:</span>
                {{ receipt?.receivedAt ?? invoice.finalizedAt }}
            </p>
            <p v-if="receipt">
                <span class="font-bold">Invoice No:</span>
                {{ invoice.number }}
            </p>
        </div>
    </section>

    <section
        v-if="!receipt && billTo"
        class="my-3 border-[0.5pt] border-current p-3"
        aria-label="Bill to"
    >
        <p class="mb-1 font-bold">Bill to</p>
        <BillToRecipient :recipient="billTo" />
    </section>

    <template v-if="receipt">
        <p class="my-3" data-testid="receipt-sentence">
            Received from {{ billing.patient.name }} the sum of
            {{ myr(receipt.amountSen) }} by {{ receipt.method }} being payment
            for the treatment charges.
        </p>
        <p v-if="receipt.status === 'reversed'" class="my-2 font-bold">
            Recording reversed — this is not a refund receipt.
        </p>
    </template>

    <table
        class="mt-3 w-full table-fixed border-t-[1.5pt] border-current text-left text-[10pt]"
        aria-label="Items"
    >
        <colgroup>
            <col class="w-[52%]" />
            <col class="w-[10%]" />
            <col class="w-[19%]" />
            <col class="w-[19%]" />
        </colgroup>
        <thead>
            <tr class="border-b-[0.5pt] border-current">
                <th class="py-1.5 font-bold">Item</th>
                <th class="py-1.5 text-center font-bold">Qty</th>
                <th class="py-1.5 text-right font-bold">Unit Price (RM)</th>
                <th class="py-1.5 text-right font-bold">Total Price (RM)</th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="(line, index) in invoice.lines"
                :key="index"
                class="border-b-[0.5pt] border-current/40 align-top"
            >
                <td class="py-1.5 pr-2 break-words">{{ line.name }}</td>
                <td class="py-1.5 text-center tabular-nums">
                    {{ quantity(line.quantity) }}
                </td>
                <td class="py-1.5 text-right tabular-nums">
                    {{ amount(line.unitPriceSen) }}
                </td>
                <td class="py-1.5 text-right tabular-nums">
                    {{ amount(line.totalSen) }}
                </td>
            </tr>
        </tbody>
    </table>

    <div class="document-closing">
        <table
            v-if="!receipt && invoice.categories"
            class="mt-4 w-full table-fixed border-t-[1.5pt] border-current text-left text-[10pt]"
            aria-label="Categories"
        >
            <colgroup>
                <col class="w-[81%]" />
                <col class="w-[19%]" />
            </colgroup>
            <thead>
                <tr class="border-b-[0.5pt] border-current">
                    <th class="py-1.5 font-bold">Category</th>
                    <th class="py-1.5 text-right font-bold">
                        Total Amount (RM)
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in invoice.categories" :key="row.name">
                    <td class="py-1">{{ row.name }}</td>
                    <td class="py-1 text-right tabular-nums">
                        {{ amount(row.totalSen) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <dl
            class="mt-4 ml-auto grid w-1/2 grid-cols-[1fr_auto] gap-x-4 gap-y-1 border-b-[1.5pt] border-current pb-2 text-[10pt]"
            aria-label="Totals"
            data-testid="totals"
        >
            <dt class="font-bold">Subtotal</dt>
            <dd class="text-right tabular-nums">
                {{ myr(invoice.adjustment.subtotalSen) }}
            </dd>
            <dt class="font-bold">
                {{ adjustmentSen > 0 ? 'Surcharge' : 'Discount' }}
            </dt>
            <dd class="text-right tabular-nums">
                {{ myr(adjustmentSen) }}
            </dd>
            <dt class="font-bold">Tax</dt>
            <dd class="text-right tabular-nums">{{ myr(0) }}</dd>
            <template v-if="receipt">
                <dt class="font-bold">Total paid</dt>
                <dd class="text-right tabular-nums">
                    {{ myr(receipt.amountSen) }}
                </dd>
            </template>
            <template v-else-if="invoice.state">
                <dt class="font-bold">Invoice total</dt>
                <dd class="text-right tabular-nums">
                    {{ myr(invoice.state.total) }}
                </dd>
                <dt class="font-bold">Total paid</dt>
                <dd class="text-right tabular-nums">
                    {{ myr(invoice.state.self_pay) }}
                </dd>
                <template v-if="invoice.state.panel !== 0">
                    <dt class="font-bold">Panel covers</dt>
                    <dd class="text-right tabular-nums">
                        {{ myr(invoice.state.panel) }}
                    </dd>
                </template>
                <template v-if="invoice.state.deferred !== 0">
                    <dt class="font-bold">Pay later</dt>
                    <dd class="text-right tabular-nums">
                        {{ myr(invoice.state.deferred) }}
                    </dd>
                </template>
                <dt class="font-bold">Total due</dt>
                <dd class="text-right tabular-nums">
                    {{ myr(invoice.state.due_now) }}
                </dd>
            </template>
        </dl>
    </div>

    <p
        v-if="letterhead.footerText"
        class="document-footer mt-6 text-center text-[9pt] break-words whitespace-pre-wrap italic"
    >
        {{ letterhead.footerText }}
    </p>
</template>
