<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    labels: {
        clinic: string;
        branch: string;
        patient: { name: string; patientNumber: string };
        date: string;
        items: Array<{
            name: string;
            strength: string | null;
            quantity: string | null;
            unit: string;
            dosage: string;
            frequency: string;
            duration: string | null;
            instruction: string | null;
        }>;
    };
}>();

// Browser printing has no request or fulfilment side effect.
const printLabels = () => window.print();
</script>

<template>
    <Head title="Medicine label preview" />
    <main class="label-preview">
        <header class="print-toolbar">
            <h1>Medicine label preview</h1>
            <p>
                Saved fulfilment data only. Printing does not confirm dispensing
                or change stock.
            </p>
            <p>
                Unsaved workspace edits are not included. Verify the saved
                quantity before using a label.
            </p>
            <Button type="button" @click="printLabels"
                ><Printer class="size-4" />Print</Button
            >
        </header>
        <section aria-label="Medicine labels">
            <article
                v-for="(item, index) in labels.items"
                :key="index"
                class="medicine-label"
            >
                <h2>{{ labels.clinic }} — {{ labels.branch }}</h2>
                <p class="label-patient">
                    {{ labels.patient.name }} ·
                    {{ labels.patient.patientNumber }}
                </p>
                <h3>{{ item.name }} {{ item.strength }}</h3>
                <p v-if="item.quantity !== null" class="label-quantity">
                    QTY: {{ item.quantity }} {{ item.unit }}
                </p>
                <p v-else class="quantity-warning">
                    QTY: Not yet recorded — save actual quantity in Dispensary
                    before use.
                </p>
                <p>{{ item.dosage }} · {{ item.frequency }}</p>
                <p v-if="item.duration">Duration: {{ item.duration }}</p>
                <p v-if="item.instruction">{{ item.instruction }}</p>
                <p class="label-date">{{ labels.date }}</p>
            </article>
        </section>
    </main>
</template>

<style scoped>
.label-preview {
    min-height: 100vh;
    padding: 24px;
    background: #fff;
    color: #171717;
    font-family: var(--font-sans), sans-serif;
}
.print-toolbar {
    margin: 0 auto 20px;
    max-width: 640px;
    font-size: 13px;
}
.print-toolbar h1 {
    font-size: 18px;
    font-weight: 600;
}
.print-toolbar p {
    margin: 8px 0;
}
.medicine-label {
    margin: 0 auto 16px;
    max-width: 100mm;
    padding: 5mm;
    border: 1px solid #d4d4d4;
    font-size: 12px;
    line-height: 1.4;
    overflow-wrap: anywhere;
    break-inside: avoid;
}
.medicine-label h2 {
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
}
.medicine-label h3 {
    margin-top: 8px;
    font-size: 14px;
    font-weight: 600;
}
.label-quantity,
.quantity-warning {
    margin: 4px 0;
    font-weight: 600;
}
.label-date {
    margin-top: 10px;
    font-size: 11px;
}
@media print {
    .print-toolbar {
        display: none;
    }
    .label-preview {
        min-height: 0;
        padding: 0;
    }
    .medicine-label {
        border: 0;
        margin: 0 auto;
        break-after: page;
    }
    .medicine-label:last-child {
        break-after: auto;
    }
}
</style>
