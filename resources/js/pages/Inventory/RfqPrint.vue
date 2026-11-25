<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { trimQuantity } from '@/lib/supplier-terms';
import type { PrintLetterhead } from '@/types/letterhead';

// STK-48: an A4 request for quotation for the supplier: items and quantities only, never a price, note or cost.
// White in both themes. KPOne sends nothing; staff print it or save it as PDF and send it themselves.
type Line = {
    number: number;
    skuCode: string;
    item: string;
    stockUnit: string;
    quantity: string;
};

const props = defineProps<{
    letterhead: PrintLetterhead;
    rfq: {
        number: string;
        status: string;
        roundPublicId: string;
        date: string;
        quoteBy: string;
        supplier: {
            name: string;
            contact: string | null;
            phone: string | null;
            email: string | null;
        };
        deliverTo: { branch: string; location: string };
        lines: Line[];
        printedAt: string;
    };
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
const phones = computed(() =>
    [
        props.letterhead.details.phone,
        props.letterhead.details.phone_secondary,
    ].filter((number): number is string => !!number),
);
const print = () => window.print();
</script>

<template>
    <Head :title="`Request for quotation ${rfq.number}`" />
    <div
        class="mx-auto flex w-full max-w-3xl min-w-0 flex-col gap-4 px-4 py-6 print:max-w-none print:p-0"
    >
        <section
            class="flex flex-col gap-3 rounded-xl border bg-card p-3 text-card-foreground print:hidden"
            aria-labelledby="rfq-toolbar-heading"
        >
            <h2 id="rfq-toolbar-heading" class="sr-only">
                Request for quotation print
            </h2>
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <Button as-child variant="ghost" class="min-h-11 justify-start">
                    <Link :href="`/inventory/rfqs/rounds/${rfq.roundPublicId}`"
                        >← Quote round</Link
                    >
                </Button>
                <Button
                    type="button"
                    class="min-h-11 w-full sm:w-auto"
                    data-testid="rfq-print"
                    @click="print"
                >
                    Print or save as PDF
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">
                Use A4 paper. KPOne does not send this: print it or save it as
                PDF and send it to the supplier yourself, then mark it as sent.
            </p>
        </section>

        <article
            class="rfq-sheet document-print"
            aria-label="Request for quotation"
        >
            <header class="rfq-letterhead" aria-label="Clinic letterhead">
                <img
                    v-if="letterhead.logo"
                    :src="letterhead.logo.url"
                    alt="Clinic logo"
                    class="max-h-20 w-24 shrink-0 object-contain"
                />
                <div class="min-w-0 break-words">
                    <p class="text-lg font-bold uppercase">
                        {{ letterhead.details.clinic_name }}
                    </p>
                    <p v-for="line in addressLines" :key="line">{{ line }}</p>
                    <p v-if="letterhead.details.email">
                        Email: {{ letterhead.details.email }}
                    </p>
                    <p v-if="phones.length > 0">
                        Tel:
                        <template v-for="(number, index) in phones" :key="index"
                            ><template v-if="index > 0">&nbsp;/ </template
                            ><span class="whitespace-nowrap">{{
                                number
                            }}</span></template
                        >
                    </p>
                </div>
            </header>

            <h1 class="my-5 text-center text-lg font-bold underline">
                REQUEST FOR QUOTATION
            </h1>

            <div class="rfq-parties">
                <section aria-label="Supplier">
                    <h2 class="rfq-label">To</h2>
                    <p class="font-semibold">{{ rfq.supplier.name }}</p>
                    <p v-if="rfq.supplier.contact">
                        Attn: {{ rfq.supplier.contact }}
                    </p>
                    <p v-if="rfq.supplier.phone">
                        Tel: {{ rfq.supplier.phone }}
                    </p>
                    <p v-if="rfq.supplier.email">
                        Email: {{ rfq.supplier.email }}
                    </p>
                </section>
                <dl class="rfq-facts">
                    <div>
                        <dt>RFQ No</dt>
                        <dd class="whitespace-nowrap">{{ rfq.number }}</dd>
                    </div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ rfq.date }}</dd>
                    </div>
                    <div>
                        <dt>Deliver to</dt>
                        <dd>
                            {{ rfq.deliverTo.branch }} ·
                            {{ rfq.deliverTo.location }}
                        </dd>
                    </div>
                </dl>
            </div>

            <p class="rfq-quote-by" data-testid="rfq-quote-by">
                Please quote by {{ rfq.quoteBy }}.
            </p>
            <p class="mb-3">
                Please give your price per pack and how many units are in one
                pack for each item, your lead time in days and how long your
                quote is valid. Say if you cannot supply an item.
            </p>

            <table class="rfq-table" aria-label="Items to quote">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Item</th>
                        <th scope="col" class="text-right">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in rfq.lines" :key="line.number">
                        <td class="tabular-nums">{{ line.number }}</td>
                        <td>
                            <span class="font-medium">{{ line.item }}</span>
                            <span class="block text-xs"
                                >SKU {{ line.skuCode }}</span
                            >
                        </td>
                        <td class="text-right tabular-nums">
                            {{ trimQuantity(line.quantity) }}
                            {{ line.stockUnit }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-4 text-xs">Printed {{ rfq.printedAt }}</p>
            <p v-if="letterhead.footerText" class="rfq-footer">
                {{ letterhead.footerText }}
            </p>
        </article>
    </div>
</template>

<style>
@page {
    size: A4;
    margin: 15mm;
    @bottom-right {
        content: 'Page ' counter(page) ' of ' counter(pages);
        font-size: 8pt;
    }
}
/* The sheet stays white with black text on screen and on paper, in every theme (PRINT-1). */
.rfq-sheet {
    min-width: 0;
    border-radius: 0.5rem;
    background: #fff;
    color: #000;
    padding: 2rem;
    font-size: 0.875rem;
    line-height: 1.45;
    overflow-wrap: anywhere;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.25);
}
@media screen and (max-width: 640px) {
    .rfq-sheet {
        padding: 1rem;
    }
}
.rfq-letterhead {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    border-bottom: 1px solid #555;
    padding-bottom: 1rem;
}
.rfq-parties {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 1rem 2rem;
    margin-bottom: 1rem;
}
.rfq-label {
    font-weight: 600;
}
.rfq-facts {
    display: grid;
    gap: 0.25rem;
}
.rfq-facts div {
    display: flex;
    gap: 0.5rem;
}
.rfq-facts dt {
    min-width: 5.5rem;
    font-weight: 600;
}
.rfq-facts dt::after {
    content: ':';
}
.rfq-quote-by {
    margin: 0.5rem 0;
    font-weight: 700;
}
.rfq-table {
    width: 100%;
    border-collapse: collapse;
}
.rfq-table th,
.rfq-table td {
    overflow-wrap: normal;
    word-break: normal;
    border-bottom: 1px solid #555;
    padding: 0.35rem 0.5rem;
    text-align: left;
    vertical-align: top;
}
.rfq-table th.text-right,
.rfq-table td.text-right {
    text-align: right;
}
.rfq-table thead {
    display: table-header-group;
}
.rfq-table tr {
    break-inside: avoid;
}
.rfq-footer {
    margin-top: 2rem;
    border-top: 1px solid #555;
    padding-top: 0.75rem;
    text-align: center;
    font-size: 0.75rem;
    font-style: italic;
    white-space: pre-wrap;
    break-inside: avoid;
}
@media print {
    .rfq-sheet {
        border-radius: 0;
        box-shadow: none;
        padding: 0;
    }
}
</style>
