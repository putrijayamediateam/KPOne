<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

// STK-24: an A4 print of one controlled medicine's register for a date range. Every printed page carries the draft
// banner (a named @page), because this is not a statutory register until a pharmacist approves its format.
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

defineProps<{
    register: {
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
        lines: { total: number; rows: Line[] };
        printedOn: string;
    };
}>();

const print = () => window.print();
</script>

<template>
    <Head :title="`Controlled register: ${register.sku.medicine}`" />
    <div
        class="mx-auto flex w-full max-w-4xl min-w-0 flex-col gap-4 px-4 py-6 print:max-w-none print:p-0"
    >
        <section
            class="flex flex-col gap-3 rounded-xl border bg-card p-3 text-card-foreground print:hidden"
            aria-labelledby="cd-toolbar-heading"
        >
            <h2 id="cd-toolbar-heading" class="sr-only">Register print</h2>
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <Button as-child variant="ghost" class="min-h-11 justify-start">
                    <Link
                        :href="`/inventory/controlled-register/${register.sku.publicId}`"
                        >← Register</Link
                    >
                </Button>
                <Button
                    type="button"
                    class="min-h-11 w-full sm:w-auto"
                    data-testid="cd-print-button"
                    @click="print"
                >
                    Print or save as PDF
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">
                Use A4 paper. This shows the register as recorded now, and
                opening it is recorded.
            </p>
        </section>
        <article
            class="cd-sheet document-print"
            aria-label="Controlled register"
        >
            <p class="cd-banner" data-testid="cd-print-banner">
                DRAFT REGISTER — PENDING PHARMACIST REVIEW
            </p>
            <h1 class="text-center text-lg font-bold underline">
                CONTROLLED MEDICINE REGISTER
            </h1>
            <dl class="cd-facts my-4">
                <div>
                    <dt>Medicine</dt>
                    <dd>{{ register.sku.medicine }}</dd>
                </div>
                <div>
                    <dt>SKU</dt>
                    <dd>{{ register.sku.skuCode }}</dd>
                </div>
                <div>
                    <dt>Branch</dt>
                    <dd>{{ register.branchName }}</dd>
                </div>
                <div>
                    <dt>Dates</dt>
                    <dd>
                        {{ register.filters.from }} to {{ register.filters.to }}
                    </dd>
                </div>
                <div>
                    <dt>Unit</dt>
                    <dd>{{ register.sku.stockUnit }}</dd>
                </div>
                <div>
                    <dt>Printed</dt>
                    <dd>{{ register.printedOn }}</dd>
                </div>
            </dl>
            <p class="mb-2 font-semibold">
                Balance at the start: {{ register.opening }}
            </p>
            <div class="cd-table-frame">
                <table class="cd-table">
                    <thead>
                        <tr>
                            <th scope="col">Date and time</th>
                            <th scope="col">What happened</th>
                            <th scope="col" class="text-right">In</th>
                            <th scope="col" class="text-right">Out</th>
                            <th scope="col" class="text-right">Balance</th>
                            <th scope="col">Batch</th>
                            <th scope="col">Location</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Recorded by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!register.lines.rows.length">
                            <td colspan="9">No movements in these dates.</td>
                        </tr>
                        <tr v-for="line in register.lines.rows" :key="line.id">
                            <td>{{ line.when }}</td>
                            <td>{{ line.what }}</td>
                            <td class="text-right">{{ line.in ?? '' }}</td>
                            <td class="text-right">{{ line.out ?? '' }}</td>
                            <td class="text-right font-semibold">
                                {{ line.balance }}
                            </td>
                            <td>{{ line.batch ?? '—' }}</td>
                            <td>{{ line.location }}</td>
                            <td>{{ line.reference }}</td>
                            <td>{{ line.recordedBy }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="cd-end">
                <p class="mt-3 font-semibold">
                    Balance at the end: {{ register.closing }}
                </p>
                <p class="mt-4 text-xs">
                    Read from KPOne stock records. No patient or prescriber is
                    shown in this draft. Not a statutory register until a
                    pharmacist approves its format.
                </p>
            </div>
        </article>
    </div>
</template>

<style>
/* Every printed page carries the draft banner and "Page N of M". */
@page cd-register {
    size: A4 landscape;
    margin: 12mm;
    @top-center {
        content: 'DRAFT REGISTER — PENDING PHARMACIST REVIEW';
        font-size: 9pt;
        font-weight: 700;
    }
    @bottom-right {
        content: 'Page ' counter(page) ' of ' counter(pages);
        font-size: 8pt;
    }
}
/* The sheet stays white with black text on screen and on paper, in every theme (PRINT-1). */
.cd-sheet {
    page: cd-register;
    min-width: 0;
    border-radius: 0.5rem;
    background: #fff;
    color: #000;
    padding: 2rem;
    font-size: 0.8125rem;
    line-height: 1.4;
    overflow-wrap: anywhere;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.25);
}
@media screen and (max-width: 640px) {
    .cd-sheet {
        padding: 1rem;
    }
}
.cd-banner {
    margin-bottom: 1rem;
    border: 3px solid #000;
    padding: 0.5rem;
    text-align: center;
    font-size: 1rem;
    font-weight: 800;
    letter-spacing: 0.04em;
}
.cd-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 0.25rem 2rem;
}
.cd-facts div {
    display: flex;
    gap: 0.5rem;
}
.cd-facts dt {
    min-width: 5rem;
    font-weight: 600;
}
.cd-facts dt::after {
    content: ':';
}
.cd-table-frame {
    overflow-x: auto;
}
.cd-table {
    width: 100%;
    border-collapse: collapse;
}
.cd-table th,
.cd-table td {
    overflow-wrap: normal;
    word-break: normal;
    border-bottom: 1px solid #555;
    padding: 0.3rem 0.4rem;
    text-align: left;
    vertical-align: top;
}
.cd-table th.text-right,
.cd-table td.text-right {
    text-align: right;
}
.cd-table thead {
    display: table-header-group;
}
.cd-table tr {
    break-inside: avoid;
}
.cd-end {
    break-inside: avoid;
}
@media print {
    .cd-table-frame {
        overflow: visible;
    }
    .cd-sheet {
        border-radius: 0;
        box-shadow: none;
        padding: 0;
    }
}
</style>
