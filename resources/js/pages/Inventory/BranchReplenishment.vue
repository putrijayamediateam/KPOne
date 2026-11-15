<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { trimQuantity } from '@/lib/supplier-terms';

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

const quantity = (value: string, unit: string) =>
    `${trimQuantity(value)} ${unit}`;

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
    sendOpen.value = true;
};

// Checked here first so the person sees why at once; the server checks the same and decides.
const localErrors = ref<Record<string, string>>({});
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
</script>

<template>
    <Head title="Stock other branches need" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Stock other branches need"
            :description="`${suggestions.branchName} · Other branches at or below their reorder level, and stock this branch can spare. Nothing is sent from this page.`"
        />
        <p
            class="rounded-lg border bg-muted/40 p-3 text-sm"
            data-testid="replenishment-method"
        >
            Their need is what brings them up to their maximum (or reorder
            level). What we can spare is our usable stock above our own level,
            less what is already waiting to be sent. The suggestion is the
            smaller of the two, in whole units.
        </p>

        <form
            class="flex flex-wrap items-end gap-2"
            role="search"
            @submit.prevent="visit()"
        >
            <label
                class="grid min-w-0 basis-full gap-1 text-sm sm:flex-1 sm:basis-auto"
                >Search SKU, item, branch or location
                <Input v-model="q" maxlength="100" class="h-11 lg:h-9" />
            </label>
            <Button type="submit" variant="outline" class="h-11 lg:h-9"
                >Search</Button
            >
        </form>

        <div
            v-if="suggestions.canRequest && suggestions.rows.length"
            class="flex flex-wrap items-center gap-3 rounded-lg border p-3"
            data-testid="replenishment-request-bar"
        >
            <p class="min-w-0 flex-1 text-sm">
                <template v-if="!ticked.length"
                    >Tick suggestions to send them in a stock request.</template
                >
                <template v-else-if="onePair"
                    >{{ ticked.length }}
                    {{ ticked.length === 1 ? 'suggestion' : 'suggestions' }}
                    ticked.</template
                >
                <template v-else-if="!oneDestination"
                    >Tick suggestions for one branch at a time.</template
                >
                <template v-else
                    >Tick suggestions sent from one of our locations at a
                    time.</template
                >
            </p>
            <Button
                type="button"
                class="min-h-11 w-full sm:w-auto lg:min-h-9"
                :disabled="!ticked.length || !onePair"
                data-testid="replenishment-request-these"
                @click="requestThese"
                >Request these</Button
            >
        </div>

        <p
            v-if="suggestions.rows.length === 0"
            class="text-sm text-muted-foreground"
        >
            No other branch needs stock this branch can spare.
        </p>
        <ul v-else class="space-y-3" aria-label="Stock other branches need">
            <li
                v-for="row in suggestions.rows"
                :key="row.key"
                class="space-y-3 rounded-lg border bg-card p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <component
                        :is="suggestions.canRequest ? 'label' : 'div'"
                        class="flex min-h-11 min-w-0 cursor-pointer items-start gap-3 lg:min-h-0"
                        data-testid="replenishment-card-header"
                    >
                        <input
                            v-if="suggestions.canRequest"
                            v-model="ticked"
                            type="checkbox"
                            class="mt-0.5 size-6 shrink-0 lg:size-4"
                            :value="row.key"
                            :aria-label="`Send ${row.skuCode} to ${row.to.branch}, ${row.to.location}`"
                        />
                        <span class="min-w-0">
                            <span class="block font-medium break-words">
                                {{ row.skuCode }} · {{ row.itemName }}
                            </span>
                            <span class="block text-sm text-muted-foreground">
                                From {{ row.from.location }} to
                                {{ row.to.branch }} · {{ row.to.location }}
                            </span>
                        </span>
                    </component>
                    <div class="sm:text-right">
                        <p class="text-xs text-muted-foreground">
                            Suggested to send
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ quantity(row.suggestedQuantity, row.stockUnit) }}
                        </p>
                    </div>
                </div>
                <div class="grid gap-3 text-sm md:grid-cols-2">
                    <section
                        class="min-w-0 rounded-md border p-3"
                        :aria-label="`${row.to.branch} needs`"
                    >
                        <h3 class="font-medium">{{ row.to.branch }} needs</h3>
                        <dl
                            class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 min-[480px]:grid-cols-3"
                        >
                            <div>
                                <dt class="text-muted-foreground">On hand</dt>
                                <dd class="tabular-nums">
                                    {{ quantity(row.to.onHand, row.stockUnit) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Reorder level
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        quantity(
                                            row.to.reorderLevel,
                                            row.stockUnit,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Maximum</dt>
                                <dd
                                    v-if="row.to.maxLevel !== null"
                                    class="tabular-nums"
                                >
                                    {{
                                        quantity(row.to.maxLevel, row.stockUnit)
                                    }}
                                </dd>
                                <dd v-else class="text-muted-foreground">
                                    Not set
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">On order</dt>
                                <dd class="tabular-nums">
                                    {{
                                        quantity(row.to.onOrder, row.stockUnit)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Incoming</dt>
                                <dd class="tabular-nums">
                                    {{
                                        quantity(row.to.incoming, row.stockUnit)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Need</dt>
                                <dd class="font-medium tabular-nums">
                                    {{ quantity(row.to.need, row.stockUnit) }}
                                </dd>
                            </div>
                        </dl>
                    </section>
                    <section
                        class="min-w-0 rounded-md border p-3"
                        :aria-label="`${row.from.location} can spare`"
                    >
                        <h3 class="font-medium">
                            {{ row.from.location }} can spare
                        </h3>
                        <dl
                            class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 min-[480px]:grid-cols-3"
                        >
                            <div>
                                <dt class="text-muted-foreground">Usable</dt>
                                <dd class="tabular-nums">
                                    {{
                                        quantity(row.from.usable, row.stockUnit)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Our own level
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        quantity(row.from.target, row.stockUnit)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Waiting to be sent
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        quantity(
                                            row.from.sending,
                                            row.stockUnit,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div v-if="row.from.alreadySuggested !== '0.000'">
                                <dt class="text-muted-foreground">
                                    Already suggested
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        quantity(
                                            row.from.alreadySuggested,
                                            row.stockUnit,
                                        )
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </li>
        </ul>
        <CompactPagination
            v-if="suggestions.total > 0"
            :current-page="suggestions.page"
            :last-page="suggestions.lastPage"
            :total="suggestions.total"
            @change="(page) => visit(page)"
        />

        <Dialog v-model:open="sendOpen">
            <DialogContent
                class="max-h-[90vh] overflow-x-hidden overflow-y-auto sm:max-w-xl"
            >
                <DialogHeader>
                    <DialogTitle>New stock request</DialogTitle>
                    <DialogDescription
                        >From {{ draft.fromName }} to {{ draft.toName }}. It is
                        approved and sent from this branch in Inventory
                        operations, and received by
                        {{ draft.toName.split(' · ')[0] }}.</DialogDescription
                    >
                </DialogHeader>
                <form
                    class="min-w-0 space-y-3"
                    novalidate
                    @submit.prevent="sendRequest"
                >
                    <ul class="space-y-2" aria-label="Lines">
                        <li
                            v-for="(line, index) in draft.lines"
                            :key="line.sku"
                            class="min-w-0"
                        >
                            <label class="grid gap-1 text-sm"
                                >{{ line.label }} ({{ line.unit }})
                                <Input
                                    v-model="line.quantity"
                                    inputmode="decimal"
                                    class="h-11 lg:h-9"
                                    :aria-describedby="`line-${index}-cap line-${index}-error`"
                                    :aria-invalid="
                                        lineError(index) ? true : undefined
                                    "
                                />
                            </label>
                            <p
                                :id="`line-${index}-cap`"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Suggested {{ line.suggested }} {{ line.unit }}.
                                This branch can spare {{ line.spare }}.
                            </p>
                            <InputError
                                :id="`line-${index}-error`"
                                :message="lineError(index)"
                            />
                        </li>
                    </ul>
                    <InputError :message="generalError" />
                    <div
                        class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end [&>button]:min-h-11 max-sm:[&>button]:w-full"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="sendOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >Send request</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
