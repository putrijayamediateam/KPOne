<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DateField } from '@/components/ui/date-field';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge } from '@/components/ui/status';
import { Textarea } from '@/components/ui/textarea';
import {
    cancelReasons,
    chooseReasons,
    rfqStatusLabel,
    rfqStatusTone,
    rm,
    rmFour,
    roundStateLabel,
    roundStateTone,
    tieBreakWords,
    unitHint,
} from '@/lib/rfq';
import type { RfqStatus, RoundState } from '@/lib/rfq';

// STK-48: one quote round. Suppliers are columns, items are rows; prices only reach a cost-view holder.
type Line = {
    id: number;
    sku: string;
    item: string;
    stockUnit: string;
    quantity: string;
    cheapest?: string | null;
    tieBreak?: string | null;
};
type Cell = {
    cannotSupply: boolean;
    packPriceSen?: number;
    packSize?: string;
    unitHundredthSen?: number | null;
    lineSen?: number | null;
} | null;
type Rfq = {
    publicId: string;
    number: string;
    supplier: string;
    supplierActive: boolean;
    status: RfqStatus;
    lockVersion: number;
    sent: { by: string; on: string } | null;
    chosen: {
        by: string;
        on: string;
        reason: string | null;
        expiredConfirmed: boolean;
    } | null;
    closed: { by: string; on: string; reason: string | null } | null;
    order: { number: string; status: string } | null;
    quote: {
        version: number;
        leadTimeDays: number;
        validUntil: string;
        validUntilIso: string;
        expired: boolean;
        reference: string | null;
        recordedBy: string;
        recordedOn: string;
        suppliedCount: number;
        totalSen?: number;
        cheapestTotal?: boolean;
        totalTieBreak?: string | null;
        gapSen?: number | null;
    } | null;
    cells: Record<number, Cell>;
};

const props = defineProps<{
    rfq: {
        expectedBranchId: number;
        branchName: string;
        can: {
            act: boolean;
            seePrices: boolean;
            recordQuote: boolean;
            choose: boolean;
            convert: boolean;
            cancel: boolean;
        };
        today: string;
        validUntilMax: string;
        round: {
            publicId: string;
            status: 'open' | 'converted' | 'cancelled';
            state: RoundState;
            lockVersion: number;
            location: string;
            quoteBy: string;
            startedOn: string;
            startedBy: string;
            cancelReason: string | null;
        };
        lines: Line[];
        rfqs: Rfq[];
    };
}>();

const HIDDEN = 'Prices are hidden for your role.';
const busy = ref(false);
const open = computed(() => props.rfq.round.status === 'open');
const lineCount = computed(() => props.rfq.lines.length);
const chosen = computed(
    () => props.rfq.rfqs.find((r) => r.status === 'chosen') ?? null,
);
const days = (n: number) => (n === 1 ? '1 day' : `${n} days`);
const cheapestWords = (tieBreak: string | null | undefined) =>
    tieBreak ? `Cheapest, ${tieBreakWords[tieBreak] ?? tieBreak}` : 'Cheapest';
const totalLabel = (r: Rfq) =>
    r.quote && r.quote.suppliedCount < lineCount.value
        ? `Total for ${r.quote.suppliedCount} of ${lineCount.value} lines`
        : 'Total';
const isCheapestLine = (line: Line, r: Rfq) =>
    props.rfq.can.seePrices && line.cheapest === r.publicId;
const post = (
    url: string,
    data: Record<string, unknown>,
    onError: (bag: Record<string, string>) => void,
    onSuccess: () => void = () => {},
) =>
    router.post(
        url,
        {
            expected_branch_id: props.rfq.expectedBranchId,
            round_public_id: props.rfq.round.publicId,
            ...data,
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess,
            onError: (bag) => onError(bag as Record<string, string>),
        },
    );
const pageError = ref('');
const markSent = (r: Rfq) =>
    post(
        `/inventory/rfqs/${r.publicId}/sent`,
        { lock_version: r.lockVersion },
        (bag) => (pageError.value = Object.values(bag)[0] ?? ''),
    );

// Record a quote: one block per line, live price per stock unit.
type QuoteLine = { cannot: boolean; price: string; pack: string };
const quoting = ref<Rfq | null>(null);
const quote = reactive({
    lead: '',
    validUntil: '',
    reference: '',
    lines: {} as Record<number, QuoteLine>,
    key: crypto.randomUUID(),
});
const quoteErrors = ref<Record<string, string>>({});
const openQuote = (r: Rfq) => {
    quote.lead = r.quote ? String(r.quote.leadTimeDays) : '';
    quote.validUntil = r.quote && !r.quote.expired ? r.quote.validUntilIso : '';
    quote.reference = r.quote?.reference ?? '';
    quote.lines = Object.fromEntries(
        props.rfq.lines.map((l) => {
            const cell = r.cells[l.id];

            return [
                l.id,
                {
                    cannot: cell?.cannotSupply ?? false,
                    price:
                        cell?.packPriceSen !== undefined
                            ? `${Math.trunc(cell.packPriceSen / 100)}.${String(cell.packPriceSen % 100).padStart(2, '0')}`
                            : '',
                    pack: cell?.packSize ?? '',
                },
            ];
        }),
    );
    quote.key = crypto.randomUUID();
    quoteErrors.value = {};
    quoting.value = r;
};
const quoteGeneral = computed(
    () =>
        Object.entries(quoteErrors.value).find(
            ([key]) =>
                !/^(lines\.\d+\.|lead_time_days$|valid_until$|quote_reference$)/.test(
                    key,
                ),
        )?.[1] ?? null,
);
const saveQuote = () => {
    const r = quoting.value;

    if (!r) {
        return;
    }

    quoteErrors.value = {};

    if (!/^\d{1,3}$/.test(quote.lead.trim())) {
        quoteErrors.value.lead_time_days = 'Enter the lead time in whole days.';
    }

    if (!quote.validUntil) {
        quoteErrors.value.valid_until =
            'Choose the date the quote is valid until.';
    }

    props.rfq.lines.forEach((l, index) => {
        const line = quote.lines[l.id];

        if (!line.cannot && !/^\d{1,6}(\.\d{1,2})?$/.test(line.price.trim())) {
            quoteErrors.value[`lines.${index}.pack_price`] =
                'Enter the pack price in RM, for example 12.00.';
        }

        if (
            !line.cannot &&
            (!/^\d{1,6}(\.\d{1,3})?$/.test(line.pack.trim()) ||
                Number(line.pack) <= 0)
        ) {
            quoteErrors.value[`lines.${index}.pack_size`] =
                'Enter how many stock units are in one pack.';
        }
    });

    if (Object.keys(quoteErrors.value).length) {
        return;
    }

    post(
        `/inventory/rfqs/${r.publicId}/quote`,
        {
            lock_version: r.lockVersion,
            idempotency_key: quote.key,
            lead_time_days: quote.lead.trim(),
            valid_until: quote.validUntil,
            quote_reference: quote.reference.trim() || null,
            lines: props.rfq.lines.map((l) => ({
                line_id: l.id,
                cannot_supply: quote.lines[l.id].cannot,
                pack_price: quote.lines[l.id].cannot
                    ? null
                    : quote.lines[l.id].price.trim(),
                pack_size: quote.lines[l.id].cannot
                    ? null
                    : quote.lines[l.id].pack.trim(),
            })),
        },
        (bag) => {
            quoteErrors.value = bag;
            quote.key = crypto.randomUUID();
        },
        () => (quoting.value = null),
    );
};

// Choose: the gap to the cheapest and a reason when it is not the cheapest.
const choosing = ref<Rfq | null>(null);
const choice = reactive({ reason: '', note: '', confirmed: false });
const chooseErrors = ref<Record<string, string>>({});
const openChoose = (r: Rfq) => {
    choice.reason = '';
    choice.note = '';
    choice.confirmed = false;
    chooseErrors.value = {};
    choosing.value = r;
};
const needsReason = computed(
    () => choosing.value !== null && !choosing.value.quote?.cheapestTotal,
);
const gapText = computed(() => {
    const gap = choosing.value?.quote?.gapSen;

    return gap !== null && gap !== undefined && gap > 0
        ? `${rm(gap)} more than the cheapest`
        : null;
});
const submitChoose = () => {
    const r = choosing.value;

    if (!r) {
        return;
    }

    chooseErrors.value = {};

    if (needsReason.value && !choice.reason) {
        chooseErrors.value.reason = 'Choose a reason.';
    }

    if (choice.reason === 'other' && !choice.note.trim()) {
        chooseErrors.value.note = 'Say why in a short note.';
    }

    if (r.quote?.expired && !choice.confirmed) {
        chooseErrors.value.still_valid_confirmed = 'Tick to continue.';
    }

    if (Object.keys(chooseErrors.value).length) {
        return;
    }

    post(
        `/inventory/rfqs/${r.publicId}/choose`,
        {
            lock_version: r.lockVersion,
            reason: needsReason.value ? choice.reason : null,
            note: choice.reason === 'other' ? choice.note.trim() : null,
            still_valid_confirmed: r.quote?.expired ? choice.confirmed : false,
        },
        (bag) => (chooseErrors.value = bag),
        () => (choosing.value = null),
    );
};
const chooseGeneral = computed(
    () =>
        Object.entries(chooseErrors.value).find(
            ([key]) => !/^(reason|note|still_valid_confirmed)$/.test(key),
        )?.[1] ?? null,
);

// Make purchase order: a confirm that lists the lines left off.
const converting = ref(false);
const convertError = ref('');
const convertKey = ref(crypto.randomUUID());
const leftOff = computed(() =>
    chosen.value
        ? props.rfq.lines.filter((l) => chosen.value?.cells[l.id]?.cannotSupply)
        : [],
);
const convert = () =>
    post(
        `/inventory/rfqs/rounds/${props.rfq.round.publicId}/convert`,
        {
            lock_version: props.rfq.round.lockVersion,
            idempotency_key: convertKey.value,
        },
        (bag) =>
            (convertError.value =
                Object.values(bag)[0] ?? 'The order was not made.'),
        () => (converting.value = false),
    );

// Cancel an RFQ or the whole round, with a reason.
const cancelling = ref<{ kind: 'rfq' | 'round'; rfq: Rfq | null } | null>(null);
const cancel = reactive({ reason: '', note: '' });
const cancelErrors = ref<Record<string, string>>({});
const reasonOptions = Object.entries(cancelReasons).map(([value, label]) => ({
    value,
    label,
}));
const openCancel = (kind: 'rfq' | 'round', r: Rfq | null = null) => {
    cancel.reason = '';
    cancel.note = '';
    cancelErrors.value = {};
    cancelling.value = { kind, rfq: r };
};
const submitCancel = () => {
    const target = cancelling.value;

    if (!target) {
        return;
    }

    cancelErrors.value = {};

    if (!cancel.reason) {
        cancelErrors.value.reason = 'Choose a reason.';
    }

    if (cancel.reason === 'other' && !cancel.note.trim()) {
        cancelErrors.value.note = 'Say why in a short note.';
    }

    if (Object.keys(cancelErrors.value).length) {
        return;
    }

    const url =
        target.kind === 'round'
            ? `/inventory/rfqs/rounds/${props.rfq.round.publicId}/cancel`
            : `/inventory/rfqs/${target.rfq?.publicId}/cancel`;
    post(
        url,
        {
            lock_version:
                target.kind === 'round'
                    ? props.rfq.round.lockVersion
                    : target.rfq?.lockVersion,
            reason: cancel.reason,
            note: cancel.note.trim() || null,
        },
        (bag) => (cancelErrors.value = bag),
        () => (cancelling.value = null),
    );
};
const cancelGeneral = computed(
    () =>
        Object.entries(cancelErrors.value).find(
            ([key]) => !/^(reason|note)$/.test(key),
        )?.[1] ?? null,
);
const canChoose = (r: Rfq) =>
    props.rfq.can.choose &&
    r.status === 'quoted' &&
    (r.quote?.suppliedCount ?? 0) > 0;
const canQuote = (r: Rfq) =>
    props.rfq.can.recordQuote && ['draft', 'sent', 'quoted'].includes(r.status);
const canCancelRfq = (r: Rfq) =>
    props.rfq.can.cancel &&
    ['draft', 'sent', 'quoted', 'chosen'].includes(r.status);
</script>

<template>
    <Head title="Quote round" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory/rfqs"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" /> Requests for
            quotation</ActionLink
        >
        <PageHeader title="Quote round">
            <template #title-extra>
                <StatusBadge
                    :status="rfq.round.state"
                    :label="roundStateLabel[rfq.round.state]"
                    :tone="roundStateTone[rfq.round.state]"
                />
            </template>
            <template #description>
                To {{ rfq.round.location }} · please quote by
                {{ rfq.round.quoteBy }} · started {{ rfq.round.startedOn }} by
                {{ rfq.round.startedBy }}
            </template>
            <template #actions>
                <Button
                    v-if="rfq.can.convert"
                    type="button"
                    class="min-h-11 lg:min-h-9"
                    data-testid="rfq-convert"
                    :disabled="busy"
                    @click="
                        convertError = '';
                        converting = true;
                    "
                    >Make purchase order</Button
                >
                <Button
                    v-if="rfq.can.cancel"
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    data-testid="rfq-cancel-round"
                    @click="openCancel('round')"
                    >Cancel round</Button
                >
            </template>
        </PageHeader>
        <p
            v-if="!rfq.can.seePrices"
            class="rounded-md border p-3 text-sm"
            data-testid="rfq-prices-hidden"
        >
            {{ HIDDEN }}
        </p>
        <p v-if="pageError" role="alert" class="text-sm text-destructive">
            {{ pageError }}
        </p>

        <!-- From 1024 px: suppliers as columns, items as rows. -->
        <div class="hidden overflow-x-auto rounded-md border lg:block">
            <table class="w-full text-sm" data-testid="rfq-comparison">
                <caption class="sr-only">
                    Quotes compared by item
                </caption>
                <thead>
                    <tr class="border-b text-left align-top">
                        <th scope="col" class="p-3 font-medium">Item</th>
                        <th
                            v-for="r in rfq.rfqs"
                            :key="r.publicId"
                            scope="col"
                            class="min-w-48 p-3 font-medium"
                        >
                            <span class="block break-words">{{
                                r.supplier
                            }}</span>
                            <span
                                class="block text-xs font-normal text-muted-foreground"
                                >{{ r.number }}</span
                            >
                            <StatusBadge
                                class="mt-1"
                                :status="r.status"
                                :label="rfqStatusLabel[r.status]"
                                :tone="rfqStatusTone[r.status]"
                            />
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in rfq.lines"
                        :key="line.id"
                        class="border-b align-top"
                    >
                        <th scope="row" class="p-3 text-left font-normal">
                            <span class="font-medium">{{ line.item }}</span>
                            <span class="block text-xs text-muted-foreground"
                                >{{ line.sku }} · {{ line.quantity }}
                                {{ line.stockUnit }}</span
                            >
                        </th>
                        <td v-for="r in rfq.rfqs" :key="r.publicId" class="p-3">
                            <StatusBadge
                                v-if="!r.cells[line.id]"
                                status="waiting"
                                label="Waiting for a quote"
                                tone="neutral"
                            />
                            <span
                                v-else-if="r.cells[line.id]?.cannotSupply"
                                class="text-muted-foreground"
                                >Can't supply</span
                            >
                            <span
                                v-else-if="!rfq.can.seePrices"
                                class="text-muted-foreground"
                                >{{ HIDDEN }}</span
                            >
                            <template v-else>
                                <span class="block tabular-nums"
                                    >{{
                                        rmFour(
                                            r.cells[line.id]
                                                ?.unitHundredthSen ?? 0,
                                        )
                                    }}
                                    / {{ line.stockUnit }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground tabular-nums"
                                    >{{
                                        rm(r.cells[line.id]?.packPriceSen ?? 0)
                                    }}
                                    / pack of
                                    {{ r.cells[line.id]?.packSize }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground tabular-nums"
                                    >Line
                                    {{
                                        rm(r.cells[line.id]?.lineSen ?? 0)
                                    }}</span
                                >
                                <span
                                    v-if="isCheapestLine(line, r)"
                                    class="mt-1 inline-flex items-center gap-1.5 text-xs font-medium text-success"
                                    data-testid="rfq-cheapest"
                                    ><span
                                        aria-hidden="true"
                                        class="size-1.5 rounded-full bg-current"
                                    />{{ cheapestWords(line.tieBreak) }}</span
                                >
                            </template>
                        </td>
                    </tr>
                    <tr class="border-b align-top">
                        <th scope="row" class="p-3 text-left font-medium">
                            Lead time
                        </th>
                        <td v-for="r in rfq.rfqs" :key="r.publicId" class="p-3">
                            {{ r.quote ? days(r.quote.leadTimeDays) : '—' }}
                        </td>
                    </tr>
                    <tr class="border-b align-top">
                        <th scope="row" class="p-3 text-left font-medium">
                            Valid until
                        </th>
                        <td v-for="r in rfq.rfqs" :key="r.publicId" class="p-3">
                            <template v-if="r.quote">
                                {{ r.quote.validUntil }}
                                <StatusBadge
                                    v-if="r.quote.expired"
                                    class="ml-1"
                                    status="expired"
                                    label="Expired"
                                    tone="warning"
                                />
                            </template>
                            <template v-else>—</template>
                        </td>
                    </tr>
                    <tr class="border-b align-top">
                        <th scope="row" class="p-3 text-left font-medium">
                            Total
                        </th>
                        <td v-for="r in rfq.rfqs" :key="r.publicId" class="p-3">
                            <template v-if="!r.quote">—</template>
                            <span
                                v-else-if="!rfq.can.seePrices"
                                class="text-muted-foreground"
                                >{{ HIDDEN }}</span
                            >
                            <template v-else>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    data-testid="rfq-total-label"
                                    >{{ totalLabel(r) }}</span
                                >
                                <span class="font-semibold tabular-nums">{{
                                    rm(r.quote.totalSen ?? 0)
                                }}</span>
                                <span
                                    v-if="r.quote.cheapestTotal"
                                    class="mt-1 flex items-center gap-1.5 text-xs font-medium text-success"
                                    ><span
                                        aria-hidden="true"
                                        class="size-1.5 rounded-full bg-current"
                                    />{{
                                        cheapestWords(r.quote.totalTieBreak)
                                    }}</span
                                >
                            </template>
                        </td>
                    </tr>
                    <tr class="align-top">
                        <th scope="row" class="p-3 text-left font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                        <td v-for="r in rfq.rfqs" :key="r.publicId" class="p-3">
                            <div class="flex flex-col items-start gap-2">
                                <p
                                    v-if="r.sent"
                                    class="text-xs text-muted-foreground"
                                >
                                    Sent {{ r.sent.on }} by {{ r.sent.by }}
                                </p>
                                <p
                                    v-if="r.order"
                                    class="text-xs text-muted-foreground"
                                >
                                    Draft order {{ r.order.number }}
                                </p>
                                <ActionLink
                                    :href="`/inventory/rfqs/${r.publicId}/print`"
                                    variant="outline"
                                    class="min-h-9"
                                    :aria-label="`Print ${r.number}`"
                                    >Print</ActionLink
                                >
                                <Button
                                    v-if="
                                        rfq.can.act &&
                                        open &&
                                        r.status === 'draft'
                                    "
                                    type="button"
                                    variant="outline"
                                    class="min-h-9"
                                    :disabled="busy"
                                    :aria-label="`Mark ${r.number} as sent`"
                                    @click="markSent(r)"
                                    >Mark as sent</Button
                                >
                                <Button
                                    v-if="canQuote(r)"
                                    type="button"
                                    variant="outline"
                                    class="min-h-9"
                                    :aria-label="`Record the quote for ${r.number}`"
                                    @click="openQuote(r)"
                                    >{{
                                        r.quote
                                            ? 'Correct quote'
                                            : 'Record quote'
                                    }}</Button
                                >
                                <Button
                                    v-if="canChoose(r)"
                                    type="button"
                                    variant="outline"
                                    class="min-h-9"
                                    :aria-label="`Choose ${r.number}`"
                                    @click="openChoose(r)"
                                    >Choose</Button
                                >
                                <Button
                                    v-if="canCancelRfq(r)"
                                    type="button"
                                    variant="ghost"
                                    class="min-h-9"
                                    :aria-label="`Cancel ${r.number}`"
                                    @click="openCancel('rfq', r)"
                                    >Cancel RFQ</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Below 1024 px: one card per item with supplier rows inside, then one card per supplier. -->
        <div class="space-y-3 lg:hidden">
            <section
                v-for="line in rfq.lines"
                :key="line.id"
                class="rounded-md border p-3"
                data-testid="rfq-item-card"
            >
                <h2 class="font-medium break-words">{{ line.item }}</h2>
                <p class="text-xs text-muted-foreground">
                    {{ line.sku }} · {{ line.quantity }} {{ line.stockUnit }}
                </p>
                <ul class="mt-2 divide-y">
                    <li
                        v-for="r in rfq.rfqs"
                        :key="r.publicId"
                        class="flex flex-wrap items-start justify-between gap-2 py-2 text-sm"
                    >
                        <span class="min-w-0 break-words">{{
                            r.supplier
                        }}</span>
                        <span class="text-right">
                            <StatusBadge
                                v-if="!r.cells[line.id]"
                                status="waiting"
                                label="Waiting for a quote"
                                tone="neutral"
                            />
                            <span
                                v-else-if="r.cells[line.id]?.cannotSupply"
                                class="text-muted-foreground"
                                >Can't supply</span
                            >
                            <span
                                v-else-if="!rfq.can.seePrices"
                                class="text-muted-foreground"
                                >{{ HIDDEN }}</span
                            >
                            <template v-else>
                                <span class="block tabular-nums"
                                    >{{
                                        rmFour(
                                            r.cells[line.id]
                                                ?.unitHundredthSen ?? 0,
                                        )
                                    }}
                                    / {{ line.stockUnit }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        rm(r.cells[line.id]?.packPriceSen ?? 0)
                                    }}
                                    / pack of
                                    {{ r.cells[line.id]?.packSize }}</span
                                >
                                <span
                                    v-if="isCheapestLine(line, r)"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-success"
                                    ><span
                                        aria-hidden="true"
                                        class="size-1.5 rounded-full bg-current"
                                    />{{ cheapestWords(line.tieBreak) }}</span
                                >
                            </template>
                        </span>
                    </li>
                </ul>
            </section>
            <section
                v-for="r in rfq.rfqs"
                :key="r.publicId"
                class="space-y-2 rounded-md border p-3"
                data-testid="rfq-supplier-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="font-medium break-words">
                            {{ r.supplier }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ r.number }}
                        </p>
                    </div>
                    <StatusBadge
                        :status="r.status"
                        :label="rfqStatusLabel[r.status]"
                        :tone="rfqStatusTone[r.status]"
                    />
                </div>
                <dl v-if="r.quote" class="grid grid-cols-2 gap-1 text-sm">
                    <dt class="text-muted-foreground">Lead time</dt>
                    <dd>{{ days(r.quote.leadTimeDays) }}</dd>
                    <dt class="text-muted-foreground">Valid until</dt>
                    <dd>
                        {{ r.quote.validUntil }}
                        <StatusBadge
                            v-if="r.quote.expired"
                            status="expired"
                            label="Expired"
                            tone="warning"
                        />
                    </dd>
                    <dt class="text-muted-foreground">{{ totalLabel(r) }}</dt>
                    <dd>
                        <span
                            v-if="!rfq.can.seePrices"
                            class="text-muted-foreground"
                            >{{ HIDDEN }}</span
                        >
                        <template v-else>
                            <span class="font-semibold tabular-nums">{{
                                rm(r.quote.totalSen ?? 0)
                            }}</span>
                            <span
                                v-if="r.quote.cheapestTotal"
                                class="flex items-center gap-1.5 text-xs font-medium text-success"
                                ><span
                                    aria-hidden="true"
                                    class="size-1.5 rounded-full bg-current"
                                />{{
                                    cheapestWords(r.quote.totalTieBreak)
                                }}</span
                            >
                        </template>
                    </dd>
                </dl>
                <StatusBadge
                    v-else
                    status="waiting"
                    label="Waiting for a quote"
                    tone="neutral"
                />
                <p v-if="r.order" class="text-xs text-muted-foreground">
                    Draft order {{ r.order.number }}
                </p>
                <div class="flex flex-col gap-2 [&>*]:min-h-11">
                    <ActionLink
                        :href="`/inventory/rfqs/${r.publicId}/print`"
                        variant="outline"
                        :aria-label="`Print ${r.number}`"
                        >Print</ActionLink
                    >
                    <Button
                        v-if="rfq.can.act && open && r.status === 'draft'"
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="markSent(r)"
                        >Mark as sent</Button
                    >
                    <Button
                        v-if="canQuote(r)"
                        type="button"
                        variant="outline"
                        @click="openQuote(r)"
                        >{{
                            r.quote ? 'Correct quote' : 'Record quote'
                        }}</Button
                    >
                    <Button
                        v-if="canChoose(r)"
                        type="button"
                        variant="outline"
                        @click="openChoose(r)"
                        >Choose</Button
                    >
                    <Button
                        v-if="canCancelRfq(r)"
                        type="button"
                        variant="ghost"
                        @click="openCancel('rfq', r)"
                        >Cancel RFQ</Button
                    >
                </div>
            </section>
        </div>

        <!-- Record quote. -->
        <Sheet
            :open="quoting !== null"
            @update:open="(v: boolean) => !v && (quoting = null)"
        >
            <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Quote from {{ quoting?.supplier }}</SheetTitle>
                    <SheetDescription
                        >This records the supplier's answer. KPOne does not send
                        or receive anything.</SheetDescription
                    >
                </SheetHeader>
                <form
                    v-if="quoting"
                    class="flex flex-col gap-4 px-4 pb-6"
                    novalidate
                    @submit.prevent="saveQuote"
                >
                    <p
                        v-if="quoteGeneral"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ quoteGeneral }}
                    </p>
                    <fieldset
                        v-for="(line, index) in rfq.lines"
                        :key="line.id"
                        class="min-w-0 space-y-2 rounded-md border p-3"
                        data-testid="rfq-quote-line"
                    >
                        <legend class="px-1 text-sm font-medium">
                            {{ line.item }} · {{ line.quantity }}
                            {{ line.stockUnit }}
                        </legend>
                        <label class="flex min-h-11 items-center gap-2 text-sm">
                            <Checkbox
                                :model-value="quote.lines[line.id].cannot"
                                @update:model-value="
                                    (v) =>
                                        (quote.lines[line.id].cannot =
                                            v === true)
                                "
                                :aria-label="`Can't supply ${line.item}`"
                            />
                            <span>{{
                                quote.lines[line.id].cannot
                                    ? "Can't supply: on"
                                    : "Can't supply: off"
                            }}</span>
                        </label>
                        <template v-if="!quote.lines[line.id].cannot">
                            <div class="grid gap-2 sm:grid-cols-2">
                                <div class="space-y-1">
                                    <label
                                        :for="`quote-price-${index}`"
                                        class="text-sm"
                                        >Pack price (RM)</label
                                    >
                                    <Input
                                        :id="`quote-price-${index}`"
                                        v-model="quote.lines[line.id].price"
                                        inputmode="decimal"
                                        class="min-h-11"
                                    />
                                    <InputError
                                        :message="
                                            quoteErrors[
                                                `lines.${index}.pack_price`
                                            ]
                                        "
                                    />
                                </div>
                                <div class="space-y-1">
                                    <label
                                        :for="`quote-pack-${index}`"
                                        class="text-sm"
                                        >Stock units in one pack ({{
                                            line.stockUnit
                                        }})</label
                                    >
                                    <Input
                                        :id="`quote-pack-${index}`"
                                        v-model="quote.lines[line.id].pack"
                                        inputmode="decimal"
                                        class="min-h-11"
                                    />
                                    <InputError
                                        :message="
                                            quoteErrors[
                                                `lines.${index}.pack_size`
                                            ]
                                        "
                                    />
                                </div>
                            </div>
                            <p
                                class="text-sm text-muted-foreground tabular-nums"
                                aria-live="polite"
                            >
                                {{
                                    unitHint(
                                        quote.lines[line.id].price,
                                        quote.lines[line.id].pack,
                                        line.stockUnit,
                                    ) ?? ' '
                                }}
                            </p>
                        </template>
                    </fieldset>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label for="quote-lead" class="text-sm font-medium"
                                >Lead time (days)</label
                            >
                            <Input
                                id="quote-lead"
                                v-model="quote.lead"
                                inputmode="numeric"
                                class="min-h-11"
                            />
                            <InputError :message="quoteErrors.lead_time_days" />
                        </div>
                        <div class="space-y-1">
                            <label for="quote-valid" class="text-sm font-medium"
                                >Valid until</label
                            >
                            <DateField
                                id="quote-valid"
                                v-model="quote.validUntil"
                                :min="rfq.today"
                                :max="rfq.validUntilMax"
                                class="min-h-11"
                            />
                            <p class="text-xs text-muted-foreground">
                                From today to one year ahead.
                            </p>
                            <InputError :message="quoteErrors.valid_until" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label for="quote-reference" class="text-sm font-medium"
                            >Supplier quote reference (optional)</label
                        >
                        <Input
                            id="quote-reference"
                            v-model="quote.reference"
                            maxlength="60"
                            class="min-h-11"
                            aria-describedby="quote-reference-count"
                        />
                        <p
                            id="quote-reference-count"
                            class="text-xs text-muted-foreground"
                        >
                            {{ quote.reference.length }} / 60
                        </p>
                        <InputError :message="quoteErrors.quote_reference" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11"
                        :disabled="busy"
                        data-testid="rfq-save-quote"
                        >Save quote</Button
                    >
                </form>
            </SheetContent>
        </Sheet>

        <!-- Choose. -->
        <Dialog
            :open="choosing !== null"
            @update:open="(v: boolean) => !v && (choosing = null)"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Choose {{ choosing?.supplier }}?</DialogTitle>
                    <DialogDescription
                        >Choosing is not an approval: the purchase order is
                        still approved by the Director.</DialogDescription
                    >
                </DialogHeader>
                <form
                    v-if="choosing"
                    class="space-y-3"
                    novalidate
                    @submit.prevent="submitChoose"
                >
                    <p
                        v-if="chooseGeneral"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ chooseGeneral }}
                    </p>
                    <p v-if="gapText" class="text-sm" data-testid="rfq-gap">
                        {{ gapText }}
                    </p>
                    <fieldset v-if="needsReason" class="space-y-2">
                        <legend class="text-sm font-medium">
                            This is not the cheapest quote. Why choose it?
                        </legend>
                        <label
                            v-for="(label, value) in chooseReasons"
                            :key="value"
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9"
                        >
                            <input
                                v-model="choice.reason"
                                type="radio"
                                name="choose-reason"
                                :value="value"
                                class="size-4 accent-primary"
                            />
                            {{ label }}
                        </label>
                        <InputError :message="chooseErrors.reason" />
                    </fieldset>
                    <div v-if="choice.reason === 'other'" class="space-y-1">
                        <label for="choose-note" class="text-sm font-medium"
                            >Note</label
                        >
                        <Textarea
                            id="choose-note"
                            v-model="choice.note"
                            maxlength="200"
                        />
                        <InputError :message="chooseErrors.note" />
                    </div>
                    <div v-if="choosing.quote?.expired" class="space-y-1">
                        <label class="flex min-h-11 items-center gap-2 text-sm">
                            <Checkbox
                                :model-value="choice.confirmed"
                                @update:model-value="
                                    (v) => (choice.confirmed = v === true)
                                "
                            />
                            Still valid, confirmed with the supplier
                        </label>
                        <InputError
                            :message="chooseErrors.still_valid_confirmed"
                        />
                    </div>
                    <div
                        class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="choosing = null"
                            >Back</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >Choose this quote</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <OperationalConfirmDialog
            :open="converting"
            title="Make a draft purchase order?"
            :description="`A draft order for ${chosen?.supplier ?? 'the chosen supplier'} with the quoted prices as estimates. The other quotes close as Not chosen. Submit the order from Inventory > Operations.`"
            confirm-label="Make purchase order"
            processing-label="Making the order…"
            :processing="busy"
            :error="convertError"
            @update:open="(v: boolean) => !v && (converting = false)"
            @confirm="convert"
        >
            <div
                v-if="leftOff.length"
                class="text-sm"
                data-testid="rfq-left-off"
            >
                <p class="font-medium">Left off (the supplier can't supply):</p>
                <ul class="list-disc ps-5">
                    <li v-for="line in leftOff" :key="line.id">
                        {{ line.sku }} · {{ line.item }}
                    </li>
                </ul>
            </div>
        </OperationalConfirmDialog>

        <!-- Cancel an RFQ or the round. -->
        <Dialog
            :open="cancelling !== null"
            @update:open="(v: boolean) => !v && (cancelling = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        cancelling?.kind === 'round'
                            ? 'Cancel this quote round?'
                            : `Cancel ${cancelling?.rfq?.number ?? ''}?`
                    }}</DialogTitle>
                    <DialogDescription>{{
                        cancelling?.kind === 'round'
                            ? 'Every RFQ in the round closes. Nothing is deleted.'
                            : 'This RFQ closes. Nothing is deleted.'
                    }}</DialogDescription>
                </DialogHeader>
                <form
                    class="space-y-3"
                    novalidate
                    @submit.prevent="submitCancel"
                >
                    <p
                        v-if="cancelGeneral"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ cancelGeneral }}
                    </p>
                    <div class="space-y-1">
                        <label for="cancel-reason" class="text-sm font-medium"
                            >Reason</label
                        >
                        <OperationalSelect
                            id="cancel-reason"
                            v-model="cancel.reason"
                            label="Reason"
                            placeholder="Choose a reason"
                            trigger-class="min-h-11"
                            :options="reasonOptions"
                            :invalid="!!cancelErrors.reason"
                        />
                        <InputError :message="cancelErrors.reason" />
                    </div>
                    <div class="space-y-1">
                        <label for="cancel-note" class="text-sm font-medium">{{
                            cancel.reason === 'other'
                                ? 'Note'
                                : 'Note (optional)'
                        }}</label>
                        <Textarea
                            id="cancel-note"
                            v-model="cancel.note"
                            maxlength="200"
                        />
                        <InputError :message="cancelErrors.note" />
                    </div>
                    <div
                        class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="cancelling = null"
                            >Back</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >{{
                                cancelling?.kind === 'round'
                                    ? 'Cancel round'
                                    : 'Cancel RFQ'
                            }}</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
