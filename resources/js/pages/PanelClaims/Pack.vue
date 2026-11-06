<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { myr } from '@/types/billing';
import type { PrintLetterhead } from '@/types/letterhead';

// PNL-8 (owner decisions 2026-11-05): a printable pack of one claim or up to 50 claims of one Panel. Saved claim
// evidence only, with the branch letterhead and the IC or passport number; photos are listed, never printed.
type PackClaim = {
    publicId: string;
    claimNo: string;
    status: string;
    patientName: string;
    identifierType: string | null;
    identifier: string | null;
    identityHidden: boolean;
    memberReference: string | null;
    visitNumber: string;
    visitDate: string;
    invoiceNumber: string;
    submittedOn: string | null;
    remarks: string | null;
    lines: {
        number: number;
        name: string;
        code: string | null;
        quantity: string;
        unitPriceSen: number;
        totalSen: number;
    }[];
    invoiceSubtotalSen: number;
    invoiceAdjustmentSen: number;
    invoiceTotalSen: number;
    claimOnlyAdjustmentSen: number;
    claimAmountSen: number;
    photos: string[];
};

const props = defineProps<{
    // CAT-9 sends details.phone_secondary; the shared PrintLetterhead type does not list it yet.
    letterhead: PrintLetterhead & {
        details: { phone_secondary?: string | null };
    };
    pack: {
        panelName: string;
        branchName: string;
        printedAt: string;
        claimCount: number;
        totalClaimSen: number;
        claims: PackClaim[];
    };
    backUrl: string;
    backLabel: string;
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
// PNL-19: the branch's optional second phone (CAT-9) prints on the same line as "Tel: A / B", as on referral and
// attendance letters; each number stays whole when the line wraps, and a lone second phone never leaves "Tel:  / B".
const phones = computed(() =>
    [
        props.letterhead.details.phone,
        props.letterhead.details.phone_secondary,
    ].filter((number): number is string => !!number),
);
const letterheadIncomplete = computed(
    () =>
        !props.letterhead.details.clinic_name ||
        !props.letterhead.details.address_line_1 ||
        (!props.letterhead.details.phone && !props.letterhead.details.email),
);
const single = computed(() => props.pack.claims.length === 1);
const countLabel = computed(
    () =>
        `${props.pack.claimCount} claim${props.pack.claimCount === 1 ? '' : 's'}`,
);
const signed = (sen: number) => (sen < 0 ? '−' : '+') + myr(Math.abs(sen));
const quantity = (value: string) => value.replace(/\.?0+$/, '') || '0';
const print = () => window.print();
</script>

<template>
    <Head title="Panel claim pack" />
    <div
        class="mx-auto flex w-full max-w-3xl min-w-0 flex-col gap-4 px-4 py-6 print:max-w-none print:p-0"
    >
        <section
            class="flex flex-col gap-3 rounded-xl border bg-card p-3 text-card-foreground print:hidden"
            aria-labelledby="pack-toolbar-heading"
            data-testid="pack-toolbar"
        >
            <h2 id="pack-toolbar-heading" class="sr-only">Claim pack</h2>
            <div
                class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between"
            >
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4"
                >
                    <Button
                        as-child
                        variant="ghost"
                        class="min-h-11 justify-start"
                    >
                        <Link :href="backUrl">← {{ backLabel }}</Link>
                    </Button>
                    <p class="text-sm font-medium">
                        {{ countLabel }} · {{ pack.panelName }}
                    </p>
                </div>
                <Button
                    type="button"
                    class="min-h-11 w-full sm:w-auto"
                    data-testid="pack-print"
                    @click="print"
                >
                    Print or save as PDF
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">
                Photos are not printed. Use A4 paper. Opening this pack is
                recorded.
            </p>
            <p
                v-if="letterheadIncomplete"
                role="alert"
                class="rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                data-testid="pack-letterhead-incomplete"
            >
                Clinic letterhead is incomplete. Set it in Business details.
            </p>
        </section>

        <main class="flex min-w-0 flex-col gap-4">
            <article
                v-if="!single"
                class="pack-sheet document-print"
                aria-label="Cover page"
                data-testid="pack-cover"
            >
                <header class="pack-letterhead" aria-label="Clinic letterhead">
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
                        <p v-for="line in addressLines" :key="line">
                            {{ line }}
                        </p>
                        <p v-if="letterhead.details.email">
                            {{ letterhead.details.email }}
                        </p>
                        <p
                            v-if="phones.length > 0"
                            data-testid="pack-letterhead-phones"
                        >
                            Tel:
                            <template
                                v-for="(number, index) in phones"
                                :key="index"
                                ><template v-if="index > 0">&nbsp;/ </template
                                ><span class="whitespace-nowrap">{{
                                    number
                                }}</span></template
                            >
                        </p>
                    </div>
                </header>
                <h2 class="my-5 text-center text-lg font-bold underline">
                    PANEL CLAIM PACK
                </h2>
                <dl class="pack-facts">
                    <div>
                        <dt>Panel</dt>
                        <dd>{{ pack.panelName }}</dd>
                    </div>
                    <div>
                        <dt>Branch</dt>
                        <dd>{{ pack.branchName }}</dd>
                    </div>
                    <div>
                        <dt>Printed</dt>
                        <dd>{{ pack.printedAt }}</dd>
                    </div>
                    <div>
                        <dt>Claims</dt>
                        <dd class="tabular-nums">{{ pack.claimCount }}</dd>
                    </div>
                </dl>
                <div class="pack-table-frame">
                    <table class="pack-table" aria-label="Claims in this pack">
                        <thead>
                            <tr>
                                <th scope="col" class="pack-optional">#</th>
                                <th scope="col" class="sm:whitespace-nowrap">
                                    Claim no
                                </th>
                                <th scope="col">Patient</th>
                                <th scope="col" class="pack-optional">
                                    Visit date
                                </th>
                                <th scope="col">Invoice</th>
                                <th scope="col" class="pack-optional">
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    class="text-right whitespace-nowrap"
                                >
                                    Claim (RM)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(claim, index) in pack.claims"
                                :key="claim.publicId"
                            >
                                <td class="pack-optional tabular-nums">
                                    {{ index + 1 }}
                                </td>
                                <td class="tabular-nums sm:whitespace-nowrap">
                                    {{ claim.claimNo }}
                                </td>
                                <td>{{ claim.patientName }}</td>
                                <td class="pack-optional whitespace-nowrap">
                                    {{ claim.visitDate }}
                                </td>
                                <td class="sm:whitespace-nowrap">
                                    {{ claim.invoiceNumber }}
                                </td>
                                <td class="pack-optional">
                                    {{ claim.status }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{
                                        myr(claim.claimAmountSen).replace(
                                            'RM ',
                                            '',
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    class="pack-avoid mt-3 text-right font-bold tabular-nums"
                    data-testid="pack-total"
                >
                    Total claimed: {{ myr(pack.totalClaimSen) }}
                </p>
                <p v-if="letterhead.footerText" class="pack-footer">
                    {{ letterhead.footerText }}
                </p>
            </article>

            <article
                v-for="claim in pack.claims"
                :key="claim.publicId"
                class="pack-sheet pack-claim document-print"
                :aria-label="`Panel claim ${claim.claimNo}`"
                data-testid="pack-claim"
            >
                <header class="pack-letterhead" aria-label="Clinic letterhead">
                    <img
                        v-if="letterhead.logo"
                        :src="letterhead.logo.url"
                        alt="Clinic logo"
                        class="max-h-20 w-24 shrink-0 object-contain"
                    />
                    <div class="min-w-0 break-words">
                        <component
                            :is="single ? 'h1' : 'p'"
                            class="text-lg font-bold"
                        >
                            {{ letterhead.details.clinic_name }}
                        </component>
                        <p v-for="line in addressLines" :key="line">
                            {{ line }}
                        </p>
                        <p v-if="letterhead.details.email">
                            {{ letterhead.details.email }}
                        </p>
                        <p
                            v-if="phones.length > 0"
                            data-testid="pack-letterhead-phones"
                        >
                            Tel:
                            <template
                                v-for="(number, index) in phones"
                                :key="index"
                                ><template v-if="index > 0">&nbsp;/ </template
                                ><span class="whitespace-nowrap">{{
                                    number
                                }}</span></template
                            >
                        </p>
                    </div>
                </header>
                <div
                    class="my-5 flex flex-wrap items-start justify-between gap-3"
                >
                    <h2 class="text-lg font-bold">
                        PANEL CLAIM {{ claim.claimNo }}
                    </h2>
                    <div class="sm:text-right">
                        <p>Status: {{ claim.status }}</p>
                        <p v-if="claim.submittedOn">
                            Submitted: {{ claim.submittedOn }}
                        </p>
                        <p>Panel: {{ pack.panelName }}</p>
                    </div>
                </div>
                <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                    <section class="min-w-0 break-words">
                        <h3 class="mb-1 font-semibold">Patient</h3>
                        <p>Name: {{ claim.patientName }}</p>
                        <p data-testid="pack-identity">
                            IC / Passport:
                            <template v-if="claim.identifier">
                                {{ claim.identifierType }}
                                {{ claim.identifier }}
                            </template>
                            <template v-else-if="claim.identityHidden">
                                Not shown for your role
                            </template>
                            <template v-else>Not recorded</template>
                        </p>
                        <p>
                            Member reference:
                            {{ claim.memberReference || 'Not recorded' }}
                        </p>
                    </section>
                    <section class="min-w-0 break-words">
                        <h3 class="mb-1 font-semibold">Visit</h3>
                        <p>Branch: {{ pack.branchName }}</p>
                        <p>
                            Visit: {{ claim.visitNumber }} ·
                            {{ claim.visitDate }}
                        </p>
                        <p>Invoice: {{ claim.invoiceNumber }}</p>
                    </section>
                </div>
                <h3 class="mt-5 mb-2 font-semibold">Invoice items</h3>
                <div class="pack-table-frame">
                    <table class="pack-table" aria-label="Invoice items">
                        <thead>
                            <!-- PNL-12: printed only; repeats on every page of a long claim so a loose page can be matched. -->
                            <tr class="pack-ref" data-testid="pack-ref">
                                <th colspan="5" scope="colgroup">
                                    Panel claim {{ claim.claimNo }} ·
                                    {{ claim.invoiceNumber }}
                                </th>
                            </tr>
                            <tr>
                                <th scope="col">Item</th>
                                <th scope="col" class="pack-optional">Code</th>
                                <th scope="col" class="text-right">Qty</th>
                                <th scope="col" class="text-right">
                                    Unit (RM)
                                </th>
                                <th scope="col" class="text-right">
                                    Total (RM)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in claim.lines" :key="line.number">
                                <td>{{ line.name }}</td>
                                <td class="pack-optional">
                                    {{ line.code ?? '' }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ quantity(line.quantity) }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{
                                        myr(line.unitPriceSen).replace(
                                            'RM ',
                                            '',
                                        )
                                    }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ myr(line.totalSen).replace('RM ', '') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <!-- PNL-12: the totals, remarks and photos stay together; if they move to a new page the reference line goes with them. -->
                <div class="pack-avoid" data-testid="pack-closing">
                    <p class="pack-ref-line" data-testid="pack-ref-totals">
                        Panel claim {{ claim.claimNo }} ·
                        {{ claim.invoiceNumber }}
                    </p>
                    <dl class="pack-totals">
                        <div>
                            <dt>Invoice subtotal</dt>
                            <dd>{{ myr(claim.invoiceSubtotalSen) }}</dd>
                        </div>
                        <div v-if="claim.invoiceAdjustmentSen !== 0">
                            <dt>Invoice adjustment</dt>
                            <dd>{{ signed(claim.invoiceAdjustmentSen) }}</dd>
                        </div>
                        <div>
                            <dt>Invoice total</dt>
                            <dd>{{ myr(claim.invoiceTotalSen) }}</dd>
                        </div>
                        <div v-if="claim.claimOnlyAdjustmentSen !== 0">
                            <dt>Claim adjustment</dt>
                            <dd>{{ signed(claim.claimOnlyAdjustmentSen) }}</dd>
                        </div>
                        <div class="pack-totals-final">
                            <dt>Amount claimed (Panel share)</dt>
                            <dd>{{ myr(claim.claimAmountSen) }}</dd>
                        </div>
                    </dl>
                    <p
                        v-if="claim.remarks"
                        class="pack-avoid mt-4 break-words whitespace-pre-wrap"
                    >
                        <span class="font-semibold">Remarks: </span>
                        {{ claim.remarks }}
                    </p>
                    <p
                        class="pack-avoid mt-2 [overflow-wrap:anywhere]"
                        data-testid="pack-photos"
                    >
                        <span class="font-semibold">Photos attached: </span>
                        <template v-if="claim.photos.length">
                            {{ claim.photos.length }} ({{
                                claim.photos.join(', ')
                            }})
                        </template>
                        <template v-else>none</template>
                    </p>
                </div>
                <p v-if="letterhead.footerText" class="pack-footer">
                    {{ letterhead.footerText }}
                </p>
            </article>
        </main>
    </div>
</template>

<style>
@page {
    size: A4;
    margin: 15mm;
}
/* The sheets stay white with black text on screen and on paper, in every theme (PRINT-1). */
.pack-sheet {
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
/* On a phone screen the row number and item code give way so the money columns stay in view. */
@media screen and (max-width: 640px) {
    .pack-sheet {
        padding: 1rem;
    }
    .pack-optional {
        display: none;
    }
    .pack-table th,
    .pack-table td {
        padding: 0.35rem 0.25rem;
    }
}
.pack-letterhead {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    border-bottom: 1px solid #555;
    padding-bottom: 1rem;
}
.pack-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: 0.25rem 1.5rem;
    margin-bottom: 1rem;
}
.pack-facts div {
    display: flex;
    gap: 0.5rem;
}
.pack-facts dt {
    font-weight: 600;
}
.pack-facts dt::after {
    content: ':';
}
.pack-table-frame {
    overflow-x: auto;
}
.pack-table {
    width: 100%;
    border-collapse: collapse;
}
.pack-table th,
.pack-table td {
    overflow-wrap: normal;
    word-break: normal;
    border-bottom: 1px solid #555;
    padding: 0.35rem 0.5rem;
    text-align: left;
    vertical-align: top;
}
.pack-table th.text-right,
.pack-table td.text-right {
    text-align: right;
}
.pack-table thead {
    display: table-header-group;
}
.pack-table .pack-ref {
    display: none;
}
.pack-table .pack-ref th {
    border: 0;
    padding: 0 0 0.25rem;
    font-size: 0.75rem;
    font-weight: 400;
    text-align: left;
}
.pack-ref-line {
    display: none;
    margin: 0.75rem 0 0;
    font-size: 0.75rem;
    font-weight: 400;
}
@media print {
    .pack-table .pack-ref {
        display: table-row;
    }
    .pack-ref-line {
        display: block;
    }
}
.pack-table tr {
    break-inside: avoid;
}
.pack-totals {
    margin: 0.75rem 0 0 auto;
    display: grid;
    max-width: 20rem;
    gap: 0.25rem;
}
.pack-totals div {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
}
.pack-totals dd {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.pack-totals-final {
    border-top: 1px solid #000;
    padding-top: 0.35rem;
    font-weight: 700;
}
.pack-avoid {
    break-inside: avoid;
}
.pack-footer {
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
    .pack-table-frame {
        overflow: visible;
    }
    .pack-sheet {
        border-radius: 0;
        box-shadow: none;
        padding: 0;
    }
    .pack-sheet + .pack-sheet {
        break-before: page;
    }
}
</style>
