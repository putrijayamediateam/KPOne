<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import BillToRecipient from '@/components/billing/BillToRecipient.vue';
import type { BillingPage, Receipt } from '@/types/billing';
import { myr } from '@/types/billing';
import type { BillToRecipientData } from '@/types/billTo';
import type { PrintLetterhead } from '@/types/letterhead';
const props = defineProps<{
    billing: BillingPage;
    receipt: Receipt | null;
    billTo?: BillToRecipientData | null;
    letterhead: PrintLetterhead;
}>();
const addressLines = computed(() =>
    [
        props.letterhead.details.address_line_1,
        props.letterhead.details.address_line_2,
        [props.letterhead.details.postcode, props.letterhead.details.city]
            .filter(Boolean)
            .join(' '),
        [props.letterhead.details.state, props.letterhead.details.country]
            .filter(Boolean)
            .join(', '),
    ].filter((line): line is string => !!line),
);
const print = () => window.print();
</script>
<template>
    <Head :title="receipt ? 'Receipt' : 'Invoice'" />
    <main
        class="document-print mx-auto max-w-3xl min-w-0 p-4 text-sm sm:p-8 print:bg-white print:text-black"
    >
        <button
            class="mb-4 rounded border px-3 py-2 print:hidden"
            @click="print"
        >
            Print {{ receipt ? 'Receipt' : 'Invoice' }}
        </button>
        <header
            class="mb-5 flex items-start gap-4 border-b pb-4"
            aria-label="Clinic letterhead"
        >
            <img
                v-if="letterhead.logo"
                :src="letterhead.logo.url"
                alt="Clinic logo"
                class="max-h-20 w-24 shrink-0 object-contain"
            />
            <div class="min-w-0 break-words">
                <h1 class="text-lg font-bold">
                    {{ letterhead.details.clinic_name }}
                </h1>
                <p v-for="line in addressLines" :key="line">{{ line }}</p>
                <p v-if="letterhead.details.email">
                    {{ letterhead.details.email }}
                </p>
                <p v-if="letterhead.details.phone">
                    Tel: {{ letterhead.details.phone }}
                </p>
            </div>
        </header>
        <h2 class="my-6 text-center text-lg font-bold underline">
            {{ receipt ? 'OFFICIAL RECEIPT' : 'OFFICIAL INVOICE' }}
        </h2>
        <section
            class="my-4 grid min-w-0 gap-4 sm:grid-cols-2"
            aria-label="Document details"
        >
            <div class="min-w-0 break-words" aria-label="Patient recipient">
                <h3 class="mb-1 font-semibold">To</h3>
                <p>
                    <span v-if="!receipt && billTo">Patient: </span
                    >{{ billing.patient.name }}
                </p>
                <p>{{ billing.patient.patientNumber }}</p>
            </div>
            <div class="min-w-0 text-right break-words">
                <p>
                    <span class="font-semibold">{{
                        receipt ? 'Receipt No:' : 'Invoice No:'
                    }}</span>
                    {{ receipt?.number ?? billing.invoice?.number }}
                </p>
                <p>
                    <span class="font-semibold">Date:</span>
                    {{ receipt?.receivedAt ?? billing.invoice?.finalizedAt }}
                </p>
                <p>
                    <span class="font-semibold">Status:</span>
                    {{ receipt?.status ?? billing.invoice?.status }}
                </p>
            </div>
        </section>
        <p class="my-2">{{ billing.visit.visitNumber }}</p>
        <section
            v-if="!receipt && billTo"
            class="my-4 rounded border p-4"
            aria-label="Bill to"
        >
            <h2 class="mb-2 font-semibold">Bill to</h2>
            <BillToRecipient :recipient="billTo" />
        </section>
        <template v-if="receipt"
            ><p class="my-4">
                Received for {{ billing.patient.name }} the sum of
                {{ myr(receipt.amountSen) }} via {{ receipt.method }} towards
                Invoice {{ billing.invoice?.number }}.
            </p>
            <p v-if="receipt.status === 'reversed'">
                Recording reversed — this is not a refund receipt.
            </p>
            <p>Originating Invoice: {{ billing.invoice?.number }}</p></template
        ><template v-else-if="billing.invoice"
            ><table
                class="my-4 w-full table-fixed text-left text-xs sm:text-sm"
            >
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Unit price (RM)</th>
                        <th>Total (RM)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(line, index) in billing.invoice.lines"
                        :key="index"
                        class="border-b"
                    >
                        <td class="py-2 break-words">{{ line.name }}</td>
                        <td>{{ line.quantity }} {{ line.unit }}</td>
                        <td>{{ myr(line.unitPriceSen) }}</td>
                        <td>{{ myr(line.totalSen) }}</td>
                    </tr>
                </tbody>
            </table>
            <dl class="my-4 space-y-2" aria-label="Invoice amount breakdown">
                <div class="flex justify-between">
                    <dt>Original subtotal</dt>
                    <dd>{{ myr(billing.invoice.adjustment.subtotalSen) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>
                        {{
                            billing.invoice.adjustment.adjustmentSen < 0
                                ? 'Discount'
                                : billing.invoice.adjustment.adjustmentSen > 0
                                  ? 'Surcharge'
                                  : 'Invoice adjustment'
                        }}
                    </dt>
                    <dd>
                        {{
                            billing.invoice.adjustment.adjustmentSen < 0
                                ? '−'
                                : '+'
                        }}{{
                            myr(
                                Math.abs(
                                    billing.invoice.adjustment.adjustmentSen,
                                ),
                            )
                        }}
                    </dd>
                </div>
                <div class="flex justify-between font-semibold">
                    <dt>Adjusted total</dt>
                    <dd>{{ myr(billing.invoice.state.total) }}</dd>
                </div>
            </dl>
            <p>Self-pay received {{ myr(billing.invoice.state.self_pay) }}</p>
            <p>Panel responsibility {{ myr(billing.invoice.state.panel) }}</p>
            <p>Approved pay later {{ myr(billing.invoice.state.deferred) }}</p>
            <p>Due now {{ myr(billing.invoice.state.due_now) }}</p></template
        >
        <p
            v-if="letterhead.footerText"
            class="document-footer mt-8 border-t pt-3 text-center text-xs break-words whitespace-pre-wrap italic"
        >
            {{ letterhead.footerText }}
        </p>
    </main>
</template>

<style>
@page {
    size: A4;
    margin: 15mm;
}
@media print {
    .document-print {
        max-width: none;
        padding: 0;
        background: white;
        color: black;
    }
    .document-print [aria-label='Bill to'],
    .document-print header,
    .document-print tr,
    .document-footer {
        break-inside: avoid;
    }
    .document-print thead {
        display: table-header-group;
    }
    .document-print th,
    .document-print td {
        overflow-wrap: anywhere;
    }
}
</style>
