<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BillingPage, Receipt } from '@/types/billing';
import { myr } from '@/types/billing';
import type { PrintLetterhead } from '@/types/letterhead';
const props = defineProps<{
    billing: BillingPage;
    receipt: Receipt | null;
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
                <h1 class="text-lg font-medium">
                    {{ letterhead.details.clinic_name }}
                </h1>
                <p v-for="line in addressLines" :key="line">{{ line }}</p>
                <p v-if="letterhead.details.phone">
                    {{ letterhead.details.phone }}
                </p>
                <p v-if="letterhead.details.email">
                    {{ letterhead.details.email }}
                </p>
            </div>
        </header>
        <p>{{ billing.patient.name }} · {{ billing.patient.patientNumber }}</p>
        <p>{{ billing.visit.visitNumber }}</p>
        <h2 class="my-4 font-medium">
            {{ receipt?.number ?? billing.invoice?.number }} ·
            {{ receipt?.status ?? billing.invoice?.status }}
        </h2>
        <template v-if="receipt"
            ><p>Received: {{ receipt.receivedAt }}</p>
            <p>{{ receipt.method }} · {{ myr(receipt.amountSen) }}</p>
            <p v-if="receipt.status === 'reversed'">
                Recording reversed — this is not a refund receipt.
            </p>
            <p>Originating Invoice: {{ billing.invoice?.number }}</p></template
        ><template v-else-if="billing.invoice"
            ><p>Finalized {{ billing.invoice.finalizedAt }}</p>
            <table class="my-4 w-full table-fixed text-left text-xs sm:text-sm">
                <thead>
                    <tr>
                        <th>Charge</th>
                        <th>Quantity</th>
                        <th>Unit price</th>
                        <th>Total</th>
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
            class="document-footer mt-8 border-t pt-3 text-xs break-words whitespace-pre-wrap"
        >
            {{ letterhead.footerText }}
        </p>
        <p class="mt-8 text-xs text-muted-foreground">
            Development / synthetic validation document. Not a production tax or
            e-Invoice document.
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
