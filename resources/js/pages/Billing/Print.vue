<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { BillingPage, Receipt } from '@/types/billing';
import { myr } from '@/types/billing';
defineProps<{ billing: BillingPage; receipt: Receipt | null }>();
const print = () => window.print();
</script>
<template>
    <Head :title="receipt ? 'Receipt' : 'Invoice'" />
    <main class="mx-auto max-w-3xl p-8 text-sm">
        <button
            class="mb-4 rounded border px-3 py-2 print:hidden"
            @click="print"
        >
            Print {{ receipt ? 'Receipt' : 'Invoice' }}
        </button>
        <h1 class="text-lg font-medium">
            {{ billing.clinic }} · {{ billing.branch }}
        </h1>
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
            <table class="my-4 w-full text-left">
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
                        <td class="py-2">{{ line.name }}</td>
                        <td>{{ line.quantity }} {{ line.unit }}</td>
                        <td>{{ myr(line.unitPriceSen) }}</td>
                        <td>{{ myr(line.totalSen) }}</td>
                    </tr>
                </tbody>
            </table>
            <p>Total {{ myr(billing.invoice.state.total) }}</p>
            <p>Self-pay received {{ myr(billing.invoice.state.self_pay) }}</p>
            <p>Panel responsibility {{ myr(billing.invoice.state.panel) }}</p>
            <p>Approved pay later {{ myr(billing.invoice.state.deferred) }}</p>
            <p>Due now {{ myr(billing.invoice.state.due_now) }}</p></template
        >
        <p class="mt-8 text-xs text-muted-foreground">
            Development / synthetic validation document. Not a production tax or
            e-Invoice document.
        </p>
    </main>
</template>
