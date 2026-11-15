<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { PageFrame } from '@/components/ui/page-frame';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2SelectionBar from '@/components/v2/V2SelectionBar.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { trimQuantity } from '@/lib/supplier-terms';

/**
 * Version 2 Stock other branches need (inventory V2 page 10a). Everything from `type Row` to `visit`, and from the tick
 * block to `generalError`, are verbatim copies of Classic (a test compares them). Only the layout is new: one table (cards
 * below 1280px), a tick per row, the selection bar carrying "Request these", and the V2 sheet for the stock request,
 * which calls Classic's `sendRequest` unchanged. Nothing is sent from the page itself.
 */
type Row = {
    key: string;
    skuPublicId: string;
    skuCode: string;
    itemName: string;
    stockUnit: string;
    to: {
        branch: string;
        location: string;
        locationPublicId: string;
        onHand: string;
        reorderLevel: string;
        maxLevel: string | null;
        onOrder: string;
        incoming: string;
        need: string;
    };
    from: {
        location: string;
        locationPublicId: string;
        usable: string;
        target: string;
        sending: string;
        alreadySuggested: string;
        spare: string;
    };
    suggestedQuantity: string;
};

const props = defineProps<{
    suggestions: {
        branchName: string;
        expectedBranchId: number;
        canRequest: boolean;
        filters: { q: string };
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();

const q = ref(props.suggestions.filters.q);
const visit = (page = 1) =>
    router.get(
        '/inventory/branch-replenishment',
        { q: q.value || undefined, page },
        { preserveScroll: true },
    );

// Tick suggestions that go from one of our locations to one of theirs, then check and send them as a stock request.
const ticked = ref<string[]>([]);
const tickedRows = computed(() =>
    props.suggestions.rows.filter((row) => ticked.value.includes(row.key)),
);
const oneDestination = computed(
    () =>
        new Set(tickedRows.value.map((row) => row.to.locationPublicId)).size <=
        1,
);
const onePair = computed(
    () =>
        new Set(
            tickedRows.value.map(
                (row) =>
                    `${row.from.locationPublicId}>${row.to.locationPublicId}`,
            ),
        ).size <= 1,
);

const sendOpen = ref(false);
const busy = ref(false);
const sendErrors = ref<Record<string, string>>({});
const draft = reactive<{
    from: string;
    fromName: string;
    to: string;
    toName: string;
    lines: {
        sku: string;
        label: string;
        unit: string;
        quantity: string;
        suggested: string;
        spare: string;
    }[];
}>({ from: '', fromName: '', to: '', toName: '', lines: [] });

const requestThese = () => {
    if (!tickedRows.value.length || !onePair.value) {
        return;
    }

    const first = tickedRows.value[0];
    draft.from = first.from.locationPublicId;
    draft.fromName = first.from.location;
    draft.to = first.to.locationPublicId;
    draft.toName = `${first.to.branch} · ${first.to.location}`;
    draft.lines = tickedRows.value.map((row) => ({
        sku: row.skuPublicId,
        label: `${row.skuCode} · ${row.itemName}`,
        unit: row.stockUnit,
        quantity: trimQuantity(row.suggestedQuantity),
        suggested: trimQuantity(row.suggestedQuantity),
        spare: trimQuantity(row.from.spare),
    }));
    sendErrors.value = {};
    localErrors.value = {};
    // STK-39: a new key for each request sheet; a retry from the same sheet reuses it.
    sendKey.value = crypto.randomUUID();
    sendOpen.value = true;
};

// Checked here first so the person sees why at once; the server checks the same and decides.
const localErrors = ref<Record<string, string>>({});
const sendKey = ref(crypto.randomUUID());
const checkLines = (): boolean => {
    localErrors.value = {};
    draft.lines.forEach((line, index) => {
        const value = line.quantity.trim();

        if (!/^\d{1,12}(\.\d{1,3})?$/.test(value) || Number(value) <= 0) {
            localErrors.value[`lines.${index}.quantity`] =
                'Enter a quantity above 0.';
        } else if (Number(value) > Number(line.spare)) {
            localErrors.value[`lines.${index}.quantity`] =
                `This branch can spare ${line.spare}. Lower the quantity.`;
        }
    });

    return Object.keys(localErrors.value).length === 0;
};
const lineError = (index: number): string | undefined =>
    localErrors.value[`lines.${index}.quantity`] ??
    sendErrors.value[`lines.${index}.quantity`];

const sendRequest = () => {
    sendErrors.value = {};

    if (!checkLines()) {
        return;
    }

    router.post(
        '/inventory/stock-requests',
        {
            expected_branch_id: props.suggestions.expectedBranchId,
            source_location_public_id: draft.from,
            destination_location_public_id: draft.to,
            lines: draft.lines.map((line) => ({
                sku_public_id: line.sku,
                quantity: line.quantity.trim(),
            })),
            from: 'branch-replenishment',
            retry_key: sendKey.value,
        },
        {
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => {
                sendOpen.value = false;
                ticked.value = [];
            },
            onError: (bag) => {
                sendErrors.value = bag as Record<string, string>;
            },
        },
    );
};
// Any refusal that is not about one line's quantity shows once under the list.
const generalError = computed(
    () =>
        Object.entries(sendErrors.value).find(
            ([key]) => !/^lines\.\d+\.quantity$/.test(key),
        )?.[1],
);

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock other branches need' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
const tick = (row: Row, value: boolean | 'indeterminate') => {
    ticked.value =
        value === true
            ? [...ticked.value, row.key]
            : ticked.value.filter((key) => key !== row.key);
};
const tickedSummary = computed(() => {
    const count = ticked.value.length;
    const first = tickedRows.value[0];

    return `${count} ${count === 1 ? 'suggestion' : 'suggestions'} ticked${onePair.value && first ? ` · to ${first.to.branch}` : ''}`;
});
const pairHint = computed(() =>
    onePair.value
        ? undefined
        : !oneDestination.value
          ? 'Tick suggestions for one branch at a time.'
          : 'Tick suggestions sent from one of our locations at a time.',
);
const lineCount = computed(() => draft.lines.length);
</script>

<template>
    <Head title="Stock other branches need" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Stock other branches need"
            :description="`${suggestions.branchName} · Other branches at or below their reorder level, and stock this branch can spare. Nothing is sent from this page.`"
        >
            <template #main>
                <div data-testid="replenishment-method">
                    <V2DisclosureBar title="How the suggestion is worked out">
                        <p class="text-sm">
                            Their need is what brings them up to their maximum
                            (or reorder level). What we can spare is our usable
                            stock above our own level, less what is already
                            waiting to be sent. The suggestion is the smaller of
                            the two, in whole units.
                        </p>
                    </V2DisclosureBar>
                </div>

                <form
                    class="flex min-w-0 flex-wrap items-end gap-2"
                    role="search"
                    @submit.prevent="visit()"
                >
                    <label
                        class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                        >Search SKU, item, branch or location
                        <input
                            v-model="q"
                            type="search"
                            maxlength="100"
                            class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px] lg:h-9"
                    /></label>
                    <button
                        type="submit"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >
                        Search
                    </button>
                </form>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-replenishment"
                >
                    <h2
                        id="v2-replenishment"
                        class="v2-section-title text-lg font-semibold"
                    >
                        Suggestions ({{ suggestions.total }})
                    </h2>
                    <p
                        v-if="suggestions.canRequest && suggestions.rows.length"
                        class="text-sm text-muted-foreground"
                        data-testid="replenishment-request-bar"
                    >
                        Tick suggestions for one branch, sent from one of our
                        locations, to send them in a stock request.
                    </p>
                    <V2Table
                        label="Stock other branches need"
                        :columns="suggestions.canRequest ? 5 : 4"
                        cards="xl"
                        min-width="980px"
                        :empty="suggestions.rows.length === 0"
                        empty-message="No other branch needs stock this branch can spare."
                    >
                        <template #head>
                            <tr>
                                <th
                                    v-if="suggestions.canRequest"
                                    scope="col"
                                    class="w-12"
                                >
                                    <span class="sr-only">Tick</span>
                                </th>
                                <th scope="col">SKU, from and to</th>
                                <th scope="col" class="text-right">
                                    They need
                                </th>
                                <th scope="col" class="text-right">
                                    We can spare
                                </th>
                                <th scope="col" class="text-right">
                                    Suggested to send
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in suggestions.rows" :key="row.key">
                                <td v-if="suggestions.canRequest" data-select>
                                    <span
                                        class="-m-3 inline-flex min-h-11 min-w-11 items-center justify-center"
                                        ><Checkbox
                                            :model-value="
                                                ticked.includes(row.key)
                                            "
                                            :aria-label="`Send ${row.skuCode} to ${row.to.branch}, ${row.to.location}`"
                                            @update:model-value="
                                                (value) => tick(row, value)
                                            "
                                    /></span>
                                </td>
                                <td
                                    data-stack
                                    data-label="SKU, from and to"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm font-medium break-words"
                                            >{{ row.itemName }}</span
                                        >
                                        <span
                                            class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{ unbroken(row.skuCode) }}</span
                                        >
                                        <span
                                            class="block text-xs break-words text-muted-foreground"
                                            >From {{ row.from.location }} to
                                            {{ row.to.branch }} ·
                                            {{ row.to.location }}</span
                                        >
                                    </div>
                                </td>
                                <td
                                    data-stack
                                    :data-label="`${row.to.branch} needs`"
                                >
                                    <dl
                                        class="grid grid-cols-[auto_auto] justify-end gap-x-3 gap-y-0.5 text-sm tabular-nums"
                                        :aria-label="`${row.to.branch} needs`"
                                    >
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            On hand
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.to.onHand,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Reorder level
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.to.reorderLevel,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Maximum
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                row.to.maxLevel !== null
                                                    ? quantityWithUnit(
                                                          row.to.maxLevel,
                                                          row.stockUnit,
                                                      )
                                                    : 'Not set'
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            On order
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.to.onOrder,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Incoming
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.to.incoming,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="font-medium whitespace-nowrap"
                                        >
                                            Need
                                        </dt>
                                        <dd
                                            class="text-right font-medium whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.to.need,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                    </dl>
                                </td>
                                <td
                                    data-stack
                                    :data-label="`${row.from.location} can spare`"
                                >
                                    <dl
                                        class="grid grid-cols-[auto_auto] justify-end gap-x-3 gap-y-0.5 text-sm tabular-nums"
                                        :aria-label="`${row.from.location} can spare`"
                                    >
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Usable
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.from.usable,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Our own level
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.from.target,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Waiting to be sent
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.from.sending,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <template
                                            v-if="
                                                row.from.alreadySuggested !==
                                                '0.000'
                                            "
                                        >
                                            <dt
                                                class="whitespace-nowrap text-muted-foreground"
                                            >
                                                Already suggested
                                            </dt>
                                            <dd
                                                class="text-right whitespace-nowrap"
                                            >
                                                {{
                                                    quantityWithUnit(
                                                        row.from
                                                            .alreadySuggested,
                                                        row.stockUnit,
                                                    )
                                                }}
                                            </dd>
                                        </template>
                                    </dl>
                                </td>
                                <td
                                    data-label="Suggested to send"
                                    class="text-right text-sm font-semibold whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        quantityWithUnit(
                                            row.suggestedQuantity,
                                            row.stockUnit,
                                        )
                                    }}
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="suggestions.total > 0"
                        :current-page="suggestions.page"
                        :last-page="suggestions.lastPage"
                        :total="suggestions.total"
                        :per-page="25"
                        @change="(n) => visit(n)"
                    />
                </section>
                <V2SelectionBar
                    :visible="ticked.length > 0"
                    :summary="tickedSummary"
                    action-label="Request these"
                    secondary-label="Clear"
                    :action-disabled="!onePair"
                    :hint="pairHint"
                    hint-tone="warning"
                    data-testid="replenishment-request-these"
                    @action="requestThese"
                    @secondary="ticked = []"
                />
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="sendOpen"
            title="New stock request"
            :description="`From ${draft.fromName} to ${draft.toName}. It is approved and sent from this branch in Inventory operations, and received by ${draft.toName.split(' · ')[0]}.`"
            :confirm-label="`Send request (${lineCount} ${lineCount === 1 ? 'line' : 'lines'})`"
            :busy="busy"
            size="tall"
            @update:open="(value) => (sendOpen = value)"
            @confirm="sendRequest"
        >
            <p
                v-if="generalError"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                {{ generalError }}
            </p>
            <ul class="flex flex-col gap-4" aria-label="Lines">
                <li
                    v-for="(line, index) in draft.lines"
                    :key="line.sku"
                    class="flex min-w-0 flex-col gap-1.5"
                >
                    <label
                        :for="`v2-line-${index}`"
                        class="text-sm font-medium break-words"
                        >{{ line.label }} ({{ line.unit }})</label
                    >
                    <input
                        :id="`v2-line-${index}`"
                        v-model="line.quantity"
                        inputmode="decimal"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base tabular-nums md:text-[15px] lg:h-9"
                        :class="lineError(index) ? 'border-destructive' : ''"
                        :aria-describedby="`line-${index}-cap line-${index}-error`"
                        :aria-invalid="lineError(index) ? true : undefined"
                    />
                    <p
                        :id="`line-${index}-cap`"
                        class="text-xs text-muted-foreground"
                    >
                        Suggested
                        {{ quantityWithUnit(line.suggested, line.unit) }}. This
                        branch can spare
                        {{ quantityWithUnit(line.spare, line.unit) }}.
                    </p>
                    <p
                        v-if="lineError(index)"
                        :id="`line-${index}-error`"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ lineError(index) }}
                    </p>
                </li>
            </ul>
            <template #footer-note>
                <p class="text-sm" role="status">
                    Creates a stock request only. No stock moves until it is
                    approved and sent.
                </p>
            </template>
        </V2Dialog>
    </PageFrame>
</template>

<style scoped>
/* Phone and tablet cards: a cell with several lines puts its label on its own line and uses the full width. */
@media (max-width: 1279.98px) {
    :deep(.v2-cards-xl td[data-stack]) {
        flex-direction: column;
        align-items: stretch;
        gap: 0.25rem;
        text-align: left;
    }
    :deep(.v2-cards-xl td[data-stack] dl) {
        justify-content: start;
    }
}
</style>
