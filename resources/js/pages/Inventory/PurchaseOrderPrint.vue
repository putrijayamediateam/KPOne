<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { trimQuantity } from '@/lib/supplier-terms';
import type { PrintLetterhead } from '@/types/letterhead';

// STK-20: an A4 purchase order for the supplier, rendered live from the saved order. Prices print only for a
// Director or Finance Officer; anything not yet approved carries a banner so it is never mistaken for an order.
type Line = {
    number: number;
    skuCode: string;
    item: string;
    stockUnit: string;
    quantity: string;
    packs: { count: number; description: string } | null;
    unitCostSen?: number | null;
    lineSen?: number | null;
};

const props = defineProps<{
    letterhead: PrintLetterhead;
    order: {
        publicId: string;
        number: string;
        status: string;
        isOrder: boolean;
        orderDate: string | null;
        approvedAt: string | null;
        preparedBy: string | null;
        approvedBy: string | null;
        supplier: {
            name: string;
            contact: string | null;
            phone: string | null;
            email: string | null;
        };
        deliverTo: { branch: string; location: string };
        lines: Line[];
        showPrices: boolean;
        totalSen: number | null;
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
// Only an approved order prints as an order; every other state is a banner, never a clean sheet.
const bannerFor: Record<string, string> = {
    draft: 'NOT APPROVED, NOT AN ORDER',
    submitted: 'WAITING FOR APPROVAL, NOT AN ORDER',
    cancelled: 'CANCELLED, NOT AN ORDER',
};
const banner = computed(() =>
    props.order.isOrder
        ? null
        : (bannerFor[props.order.status] ?? 'NOT APPROVED, NOT AN ORDER'),
);
// The supplier sees "Approved" and its date, never internal receiving words.
const statusLine = computed(() =>
    props.order.isOrder
        ? `Approved${props.order.approvedAt ? ` · ${props.order.approvedAt}` : ''}`
        : 'Not an order',
);
// Printed money uses a thousands separator ("2,403.50"), in whole sen with no float maths.
const rm = (sen: number | null | undefined) => {
    if (sen === null || sen === undefined) {
        return '—';
    }

    const ringgit = String(Math.trunc(sen / 100)).replace(
        /\B(?=(\d{3})+(?!\d))/g,
        ',',
    );

    return `${ringgit}.${String(sen % 100).padStart(2, '0')}`;
};
// A sheet that is not an order repeats its banner at the top of every printed page (a named @page).
const printPage = computed(() =>
    props.order.isOrder
        ? undefined
        : props.order.status === 'submitted'
          ? 'po-waiting'
          : props.order.status === 'cancelled'
            ? 'po-cancelled'
            : 'po-not-approved',
);
const print = () => window.print();
</script>

<template>
    <Head :title="`Purchase order ${order.number}`" />
    <div
        class="mx-auto flex w-full max-w-3xl min-w-0 flex-col gap-4 px-4 py-6 print:max-w-none print:p-0"
    >
        <section
            class="flex flex-col gap-3 rounded-xl border bg-card p-3 text-card-foreground print:hidden"
            aria-labelledby="po-toolbar-heading"
        >
            <h2 id="po-toolbar-heading" class="sr-only">
                Purchase order print
            </h2>
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <Button as-child variant="ghost" class="min-h-11 justify-start">
                    <Link href="/inventory">← Inventory</Link>
                </Button>
                <Button
                    type="button"
                    class="min-h-11 w-full sm:w-auto"
                    data-testid="po-print"
                    @click="print"
                >
                    Print or save as PDF
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">
                Use A4 paper. This shows the order as saved now, and opening it
                is recorded.
            </p>
        </section>

        <article
            class="po-sheet document-print"
            :style="printPage ? { page: printPage } : undefined"
            aria-label="Purchase order"
        >
            <p v-if="banner" class="po-banner" data-testid="po-banner">
                {{ banner }}
            </p>
            <header class="po-letterhead" aria-label="Clinic letterhead">
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
                PURCHASE ORDER
            </h1>

            <div class="po-parties">
                <section aria-label="Supplier">
                    <h2 class="po-label">Supplier</h2>
                    <p class="font-semibold">{{ order.supplier.name }}</p>
                    <p v-if="order.supplier.contact">
                        Attn: {{ order.supplier.contact }}
                    </p>
                    <p v-if="order.supplier.phone">
                        Tel: {{ order.supplier.phone }}
                    </p>
                    <p v-if="order.supplier.email">
                        Email: {{ order.supplier.email }}
                    </p>
                </section>
                <dl class="po-facts">
                    <div>
                        <dt>PO No</dt>
                        <dd class="whitespace-nowrap">{{ order.number }}</dd>
                    </div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ order.orderDate }}</dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd>{{ statusLine }}</dd>
                    </div>
                    <div>
                        <dt>Deliver to</dt>
                        <dd>
                            {{ order.deliverTo.branch }} ·
                            {{ order.deliverTo.location }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="po-table-frame">
                <table class="po-table" aria-label="Items ordered">
                    <thead>
                        <tr>
                            <th scope="col" class="po-optional">#</th>
                            <th scope="col">Item</th>
                            <th scope="col" class="text-right">Quantity</th>
                            <template v-if="order.showPrices">
                                <th
                                    scope="col"
                                    class="po-optional text-right whitespace-nowrap"
                                >
                                    Unit price (RM)
                                </th>
                                <th
                                    scope="col"
                                    class="text-right whitespace-nowrap"
                                >
                                    Total (RM)
                                </th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in order.lines" :key="line.number">
                            <td class="po-optional tabular-nums">
                                {{ line.number }}
                            </td>
                            <td>
                                <span class="font-medium">{{ line.item }}</span>
                                <span class="block text-xs"
                                    >SKU {{ line.skuCode }}</span
                                >
                                <span
                                    v-if="
                                        order.showPrices &&
                                        line.unitCostSen !== null &&
                                        line.unitCostSen !== undefined
                                    "
                                    class="po-narrow-price text-xs"
                                    >RM {{ rm(line.unitCostSen) }} each</span
                                >
                            </td>
                            <td class="text-right tabular-nums">
                                {{ trimQuantity(line.quantity) }}
                                {{ line.stockUnit }}
                                <span
                                    v-if="line.packs"
                                    class="block text-xs whitespace-nowrap"
                                    >{{ line.packs.count }} ×
                                    {{ line.packs.description }}</span
                                >
                            </td>
                            <template v-if="order.showPrices">
                                <td class="po-optional text-right tabular-nums">
                                    {{ rm(line.unitCostSen) }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ rm(line.lineSen) }}
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
            <!-- The totals and signatures stay with the last rows of the table (never alone on a new page). -->
            <div class="po-end">
                <p v-if="order.showPrices" class="po-total">
                    <span>Estimated total</span>
                    <span class="tabular-nums">{{
                        order.totalSen === null
                            ? 'Not all lines have a price'
                            : `RM ${rm(order.totalSen)}`
                    }}</span>
                </p>
                <p v-else class="mt-3" data-testid="po-no-prices">
                    Prices as agreed with the supplier.
                </p>

                <div class="po-sign">
                    <div>
                        <p class="po-label">Prepared by</p>
                        <p>{{ order.preparedBy ?? '—' }}</p>
                    </div>
                    <div v-if="order.isOrder">
                        <p class="po-label">Approved by</p>
                        <p>
                            {{ order.approvedBy ?? '—' }}
                            <template v-if="order.approvedAt">
                                · {{ order.approvedAt }}</template
                            >
                        </p>
                        <p class="po-signature" data-testid="po-signature">
                            Signature
                        </p>
                    </div>
                </div>
            </div>
            <p class="mt-4 text-xs">Printed {{ order.printedAt }}</p>
            <p v-if="letterhead.footerText" class="po-footer">
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
/* Every printed page of a sheet that is not an order carries its banner at the top. */
@page po-not-approved {
    @top-center {
        content: 'NOT APPROVED, NOT AN ORDER';
        font-size: 10pt;
        font-weight: 700;
    }
}
@page po-waiting {
    @top-center {
        content: 'WAITING FOR APPROVAL, NOT AN ORDER';
        font-size: 10pt;
        font-weight: 700;
    }
}
@page po-cancelled {
    @top-center {
        content: 'CANCELLED, NOT AN ORDER';
        font-size: 10pt;
        font-weight: 700;
    }
}
/* The sheet stays white with black text on screen and on paper, in every theme (PRINT-1). */
.po-sheet {
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
    .po-sheet {
        padding: 1rem;
    }
    .po-optional {
        display: none;
    }
}
.po-banner {
    margin-bottom: 1rem;
    border: 3px solid #000;
    padding: 0.5rem;
    text-align: center;
    font-size: 1.125rem;
    font-weight: 800;
    letter-spacing: 0.04em;
}
.po-letterhead {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    border-bottom: 1px solid #555;
    padding-bottom: 1rem;
}
.po-parties {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 1rem 2rem;
    margin-bottom: 1rem;
}
.po-label {
    font-weight: 600;
}
.po-facts {
    display: grid;
    gap: 0.25rem;
}
.po-facts div {
    display: flex;
    gap: 0.5rem;
}
.po-facts dt {
    min-width: 5.5rem;
    font-weight: 600;
}
.po-facts dt::after {
    content: ':';
}
.po-table-frame {
    overflow-x: auto;
}
.po-table {
    width: 100%;
    border-collapse: collapse;
}
.po-table th,
.po-table td {
    overflow-wrap: normal;
    word-break: normal;
    border-bottom: 1px solid #555;
    padding: 0.35rem 0.5rem;
    text-align: left;
    vertical-align: top;
}
.po-table th.text-right,
.po-table td.text-right {
    text-align: right;
}
.po-table thead {
    display: table-header-group;
}
.po-table tr {
    break-inside: avoid;
}
.po-total {
    margin: 0.75rem 0 0 auto;
    display: flex;
    max-width: 20rem;
    justify-content: space-between;
    gap: 1rem;
    border-top: 1px solid #000;
    padding-top: 0.35rem;
    font-weight: 700;
}
.po-end {
    break-inside: avoid;
    break-before: avoid;
}
.po-narrow-price {
    display: none;
}
@media screen and (max-width: 640px) {
    .po-narrow-price {
        display: block;
    }
}
.po-sign {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: 1.5rem;
    margin-top: 2rem;
    break-inside: avoid;
}
.po-signature {
    margin-top: 2.5rem;
    border-top: 1px solid #000;
    padding-top: 0.25rem;
    max-width: 16rem;
    font-size: 0.75rem;
}
.po-footer {
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
    .po-table-frame {
        overflow: visible;
    }
    .po-sheet {
        border-radius: 0;
        box-shadow: none;
        padding: 0;
    }
}
</style>
