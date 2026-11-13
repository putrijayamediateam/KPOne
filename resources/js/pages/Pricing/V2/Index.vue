<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePricingPublish } from '@/composables/pricing/usePricingPublish';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Pricing (shown only in V2; Classic is `pages/Pricing/Index.vue`, untouched). Same props, same routes
 * (`/pricing/price-books`, `/pricing/charges`, `/pricing/charges/{id}/publish|activate|deactivate`), same permissions,
 * `expected_version` and the empty-amount rule. Look only.
 */
type ChargeRow = {
    publicId: string;
    code: string;
    displayName: string;
    type: 'consultation' | 'medicine' | 'service';
    unit: string;
    isActive: boolean;
    currentPrice: {
        priceBookPublicId: string;
        amountSen: number | null;
        version: number;
    } | null;
    scheduledPrice?: {
        amountSen: number;
        effectiveAt: string;
        effectiveLabel: string;
    } | null;
};
type PriceBookRow = {
    publicId: string;
    name: string;
    currency: string;
    branchId: number | null;
    scopeKey: string;
    isActive: boolean;
};

const props = defineProps<{
    canPublish: boolean;
    branches: Array<{ id: number; name: string }>;
    medicines: Array<{ publicId: string; displayName: string; code: string }>;
    services: Array<{ publicId: string; displayName: string; code: string }>;
    priceBooks: PriceBookRow[];
    charges: ChargeRow[];
}>();

usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Pricing' },
]);

const page = usePage<{ branchContext?: { active?: { id: number } | null } }>();
const activeBranchId = page.props.branchContext?.active?.id ?? null;

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const fieldClass = (invalid?: boolean) =>
    invalid
        ? inputClass.replace('border-input', 'border-destructive')
        : inputClass;
const textLink =
    'inline-flex h-11 items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none lg:h-9';

const typeOptions = [
    { value: 'consultation', label: 'Consultation' },
    { value: 'medicine', label: 'Medicine' },
    { value: 'service', label: 'Clinical service' },
];
const typeLabel = (type: ChargeRow['type']) =>
    typeOptions.find((option) => option.value === type)?.label ?? type;

// --- list: search and filters happen in the browser over the rows the server already sent.
const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');
const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.charges.filter(
        (charge) =>
            (!term ||
                charge.displayName.toLowerCase().includes(term) ||
                charge.code.toLowerCase().includes(term)) &&
            (!typeFilter.value || charge.type === typeFilter.value) &&
            (!statusFilter.value ||
                (statusFilter.value === 'active') === charge.isActive),
    );
});
const filterCount = computed(
    () => [typeFilter.value, statusFilter.value].filter(Boolean).length,
);
const sheetOpen = ref(false);
const resetFilters = () => {
    typeFilter.value = '';
    statusFilter.value = '';
};
const noPrice = computed(() =>
    props.charges.filter(
        (charge) =>
            charge.isActive &&
            (!charge.currentPrice || charge.currentPrice.amountSen === null),
    ),
);

const priceText = (charge: ChargeRow) =>
    charge.currentPrice && charge.currentPrice.amountSen !== null
        ? `RM ${(charge.currentPrice.amountSen / 100).toFixed(2)}`
        : '—';
// A non-breaking space keeps "9:22 AM" together, so "AM" never sits alone on a line.
const scheduledText = (charge: ChargeRow) =>
    charge.scheduledPrice
        ? `Next: RM ${(charge.scheduledPrice.amountSen / 100).toFixed(2)} from ${charge.scheduledPrice.effectiveLabel.replace(/ (AM|PM)$/, '\u00A0$1')}`
        : null;
const hasPrice = (charge: ChargeRow) =>
    !!charge.currentPrice && charge.currentPrice.amountSen !== null;

// --- row menu
const PUBLISH_REASON = "You can't publish prices with your role.";
const menuFor = (charge: ChargeRow): RowMenuGroup[] => [
    {
        label: 'Open',
        items: [
            {
                id: 'history',
                label: 'Price history',
                allowed: true,
                href: `/pricing/charges/${charge.publicId}/history`,
            },
        ],
    },
    ...(props.canPublish
        ? []
        : [
              {
                  label: 'Money',
                  items: [
                      {
                          id: 'publish-disabled',
                          label: 'Publish price',
                          allowed: false,
                          reason: PUBLISH_REASON,
                      },
                  ],
              },
          ]),
    {
        label: 'Status',
        items: [
            charge.isActive
                ? {
                      id: 'deactivate',
                      label: 'Deactivate charge',
                      allowed: true,
                      tone: 'danger',
                  }
                : { id: 'activate', label: 'Activate charge', allowed: true },
        ],
    },
];
const toDeactivate = ref<ChargeRow | null>(null);
const toggling = ref(false);
const sendToggle = (charge: ChargeRow) => {
    toggling.value = true;
    router.post(
        `/pricing/charges/${charge.publicId}/${charge.isActive ? 'deactivate' : 'activate'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = false;
                toDeactivate.value = null;
            },
        },
    );
};
const onMenu = (charge: ChargeRow, id: string) => {
    if (id === 'deactivate') {
        toDeactivate.value = charge;
    } else if (id === 'activate') {
        sendToggle(charge);
    }
};

// --- publish price
const publish = usePricingPublish(activeBranchId, (publicId) =>
    props.charges.find((charge) => charge.publicId === publicId),
);
const activeBooks = computed(() =>
    props.priceBooks.filter((book) => book.isActive),
);
const beginPublish = (charge: ChargeRow) =>
    publish.begin(
        charge,
        activeBooks.value.length === 1 ? activeBooks.value[0].publicId : '',
    );
const chosenBook = computed(() =>
    props.priceBooks.find((book) => book.publicId === publish.form.priceBook),
);
const bookInfo = computed(() => {
    const charge = publish.target.value as ChargeRow | null;
    const book = chosenBook.value;

    if (!charge || !book) {
        return 'Choose a price book to see its current price.';
    }

    if (!charge.currentPrice) {
        return 'No price has been published for this charge yet.';
    }

    if (charge.currentPrice.priceBookPublicId === book.publicId) {
        return `Current price in ${book.name}: ${priceText(charge)}`;
    }

    return `The current price in ${book.name} is not shown here. Open Price history to check it before you publish.`;
});
const bookScheduled = computed(() => {
    const charge = publish.target.value as ChargeRow | null;

    return charge &&
        chosenBook.value &&
        charge.currentPrice?.priceBookPublicId === chosenBook.value.publicId
        ? scheduledText(charge)
        : null;
});
const bookOptions = computed(() => [
    { value: '', label: 'Choose a price book' },
    ...props.priceBooks
        .filter((book) => book.isActive)
        .map((book) => ({
            value: book.publicId,
            label: `${book.name} (${book.branchId === null ? 'Organisation-wide' : 'Branch'})`,
        })),
]);

// --- new price book
const bookOpen = ref(false);
const bookForm = useForm({ branch_id: '', name: '', currency: 'MYR' });
const bookScopeError = () =>
    (bookForm.errors as Record<string, string | undefined>).scope ??
    bookForm.errors.branch_id;
const submitBook = () => {
    bookForm.post('/pricing/price-books', {
        preserveScroll: true,
        onSuccess: () => {
            bookForm.reset();
            bookOpen.value = false;
        },
    });
};

// --- new charge definition
const chargeOpen = ref(false);
const chargeForm = useForm({
    type: 'consultation' as ChargeRow['type'],
    medicine_public_id: '',
    service_public_id: '',
    code: '',
    display_name: '',
});
const sourceOptions = computed(() =>
    chargeForm.type === 'medicine'
        ? props.medicines.map((m) => ({
              value: m.publicId,
              label: `${m.code} · ${m.displayName}`,
          }))
        : chargeForm.type === 'service'
          ? props.services.map((s) => ({
                value: s.publicId,
                label: `${s.code} · ${s.displayName}`,
            }))
          : [],
);
const submitCharge = () => {
    chargeForm
        .transform(
            ({ type, medicine_public_id, service_public_id, ...rest }) => ({
                type,
                ...rest,
                ...(type === 'medicine' ? { medicine_public_id } : {}),
                ...(type === 'service' ? { service_public_id } : {}),
            }),
        )
        .post('/pricing/charges', {
            preserveScroll: true,
            onSuccess: () => {
                chargeForm.reset();
                chargeOpen.value = false;
            },
        });
};
</script>

<template>
    <Head title="Pricing" />
    <PageFrame>
        <V2WorkLayout
            title="Pricing"
            description="Manage charge definitions, price books and published prices."
        >
            <template #actions>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <Link
                        href="/pricing/price-check"
                        :class="[outlineButton, 'max-sm:hidden']"
                        >Check price list</Link
                    >
                    <button
                        type="button"
                        :class="[outlineButton, 'max-sm:hidden']"
                        data-action="new-price-book"
                        @click="bookOpen = true"
                    >
                        New price book
                    </button>
                    <button
                        type="button"
                        :class="[filledButton, 'max-sm:w-full']"
                        data-action="new-charge"
                        @click="chargeOpen = true"
                    >
                        New charge
                    </button>
                    <div class="flex gap-5 sm:hidden" data-test="page-links">
                        <Link href="/pricing/price-check" :class="textLink"
                            >Check price list</Link
                        >
                        <button
                            type="button"
                            :class="textLink"
                            data-action="new-price-book-phone"
                            @click="bookOpen = true"
                        >
                            New price book
                        </button>
                    </div>
                </div>
            </template>

            <template #main>
                <p
                    v-if="!canPublish"
                    class="text-sm text-muted-foreground"
                    data-test="cannot-publish-note"
                >
                    {{ PUBLISH_REASON }} You can still see current prices and
                    open Price history.
                </p>
                <V2DisclosureBar
                    v-if="noPrice.length"
                    :title="`${noPrice.length} ${noPrice.length === 1 ? 'charge has' : 'charges have'} no price`"
                    tone="warning"
                >
                    <ul class="grid gap-1 text-sm">
                        <li v-for="charge in noPrice" :key="charge.publicId">
                            {{ charge.displayName }}
                            <span class="text-muted-foreground"
                                >· {{ charge.code }}</span
                            >
                        </li>
                    </ul>
                </V2DisclosureBar>

                <V2Toolbar
                    v-model="search"
                    search-label="Search charges"
                    search-placeholder="Charge name or code"
                >
                    <template #filters>
                        <div class="hidden w-44 sm:block">
                            <OperationalSelect
                                v-model="typeFilter"
                                label="Type"
                                :options="[
                                    { value: '', label: 'All types' },
                                    ...typeOptions,
                                ]"
                            />
                        </div>
                        <div class="hidden w-40 sm:block">
                            <OperationalSelect
                                v-model="statusFilter"
                                label="Status"
                                :options="[
                                    { value: '', label: 'Any status' },
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                ]"
                            />
                        </div>
                    </template>
                    <template #primary>
                        <button
                            type="button"
                            :class="[outlineButton, 'sm:hidden']"
                            data-action="open-filters"
                            @click="sheetOpen = true"
                        >
                            Filters{{ filterCount ? ` (${filterCount})` : '' }}
                        </button>
                    </template>
                </V2Toolbar>

                <div
                    class="flex flex-wrap items-center gap-x-3 text-[13px] text-muted-foreground"
                >
                    <p>
                        {{ filtered.length }}
                        {{ filtered.length === 1 ? 'charge' : 'charges' }}
                    </p>
                    <template v-if="filterCount">
                        <p>
                            {{ filterCount }}
                            {{ filterCount === 1 ? 'filter' : 'filters' }}
                            applied
                        </p>
                        <button
                            type="button"
                            class="inline-flex h-11 items-center rounded-[10px] px-2 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                            data-action="reset-filters"
                            @click="resetFilters"
                        >
                            Reset
                        </button>
                    </template>
                </div>

                <V2Table
                    label="Charges"
                    :columns="5"
                    cards="xl"
                    min-width="760px"
                    :empty="!filtered.length"
                    empty-message="No charges match. Create a charge definition to start pricing."
                >
                    <template #head>
                        <tr>
                            <th scope="col">Charge</th>
                            <th scope="col" class="whitespace-nowrap">Type</th>
                            <th
                                scope="col"
                                class="text-right whitespace-nowrap"
                            >
                                Current price
                            </th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="charge in filtered"
                            :key="charge.publicId"
                            :data-charge="charge.code"
                            data-testid="charge-row"
                        >
                            <td data-label="Charge" data-primary>
                                <span class="block text-sm font-medium">{{
                                    charge.displayName
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ charge.code
                                    }}<span class="xl:hidden">
                                        · {{ typeLabel(charge.type) }}</span
                                    ></span
                                >
                            </td>
                            <td
                                data-label="Type"
                                class="whitespace-nowrap max-xl:hidden!"
                            >
                                {{ typeLabel(charge.type) }}
                            </td>
                            <td data-label="Current price" class="text-right">
                                <div class="flex flex-col items-end">
                                    <span class="flex items-center gap-2">
                                        <V2Chip
                                            class="xl:hidden"
                                            :tone="
                                                charge.isActive
                                                    ? 'success'
                                                    : 'neutral'
                                            "
                                            :label="
                                                charge.isActive
                                                    ? 'Active'
                                                    : 'Inactive'
                                            "
                                        />
                                        <span
                                            v-if="hasPrice(charge)"
                                            data-test="price"
                                            >{{ priceText(charge) }}</span
                                        >
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            data-test="no-price"
                                            >No price</span
                                        ></span
                                    >
                                    <span
                                        v-if="scheduledText(charge)"
                                        class="text-xs whitespace-nowrap text-muted-foreground max-xl:hidden"
                                        >{{ scheduledText(charge) }}</span
                                    >
                                </div>
                            </td>
                            <td
                                v-if="scheduledText(charge)"
                                class="text-xs text-muted-foreground xl:hidden"
                                data-test="next-price-row"
                            >
                                {{ scheduledText(charge) }}
                            </td>
                            <td data-label="Status" class="max-xl:hidden!">
                                <V2Chip
                                    :tone="
                                        charge.isActive ? 'success' : 'neutral'
                                    "
                                    :label="
                                        charge.isActive ? 'Active' : 'Inactive'
                                    "
                                />
                            </td>
                            <td data-actions class="text-right">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <button
                                        v-if="canPublish"
                                        type="button"
                                        :class="[
                                            outlineButton,
                                            'max-xl:flex-1',
                                        ]"
                                        data-action="publish-price"
                                        :aria-label="`Publish price for ${charge.displayName}`"
                                        @click="beginPublish(charge)"
                                    >
                                        Publish price
                                    </button>
                                    <V2RowMenu
                                        :label="`More actions for ${charge.displayName}`"
                                        :groups="menuFor(charge)"
                                        :busy="toggling"
                                        show-label
                                        @select="onMenu(charge, $event)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </template>
                </V2Table>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="publish.open.value"
            :title="
                publish.target.value
                    ? `Publish price for ${publish.target.value.displayName}`
                    : 'Publish price'
            "
            description="The new price starts now. Earlier versions stay in Price history."
            confirm-label="Publish price"
            :busy="publish.form.busy"
            @update:open="publish.open.value = $event"
            @confirm="publish.submit()"
        >
            <div
                v-if="publish.target.value"
                class="rounded-[10px] border bg-muted/40 px-3 py-2 text-sm"
                data-test="publish-current"
            >
                <p>{{ bookInfo }}</p>
                <p v-if="bookScheduled" class="text-muted-foreground">
                    {{ bookScheduled }}
                </p>
            </div>
            <p
                v-if="publish.form.reloaded"
                role="status"
                class="rounded-[10px] border border-primary/40 bg-primary/5 px-3 py-2 text-sm"
                data-test="publish-reloaded"
            >
                Prices reloaded. Check the current price, then press Publish
                price.
            </p>
            <div
                v-if="publish.form.stale"
                role="alert"
                class="flex flex-col gap-2 rounded-[10px] border border-destructive px-3 py-2 text-sm"
                data-test="publish-stale"
            >
                <p class="text-destructive">{{ publish.form.error }}</p>
                <button
                    type="button"
                    :class="outlineButton"
                    data-action="reload-prices"
                    @click="publish.reload()"
                >
                    Reload prices
                </button>
            </div>
            <V2Field
                v-slot="{ id }"
                label="Price book"
                required
                :error="
                    publish.form.error &&
                    !publish.form.stale &&
                    !publish.form.priceBook
                        ? publish.form.error
                        : undefined
                "
            >
                <OperationalSelect
                    :id="id"
                    v-model="publish.form.priceBook"
                    label="Price book"
                    :options="bookOptions"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Amount (RM)"
                required
                :error="
                    publish.form.priceBook && !publish.form.stale
                        ? publish.form.error
                        : undefined
                "
            >
                <input
                    :id="id"
                    v-model="publish.form.amount"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="e.g. 25.00"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="fieldClass(invalid)"
                    data-field="amount"
                    @keydown.enter.prevent="publish.submit()"
                />
            </V2Field>
        </V2Dialog>

        <V2Dialog
            v-model:open="sheetOpen"
            title="Filters"
            confirm-label="Apply"
            cancel-label="Close"
            @confirm="sheetOpen = false"
        >
            <div class="grid gap-3">
                <V2Field v-slot="{ id }" label="Type">
                    <OperationalSelect
                        :id="id"
                        v-model="typeFilter"
                        label="Type"
                        :options="[
                            { value: '', label: 'All types' },
                            ...typeOptions,
                        ]"
                    />
                </V2Field>
                <V2Field v-slot="{ id }" label="Status">
                    <OperationalSelect
                        :id="id"
                        v-model="statusFilter"
                        label="Status"
                        :options="[
                            { value: '', label: 'Any status' },
                            { value: 'active', label: 'Active' },
                            { value: 'inactive', label: 'Inactive' },
                        ]"
                    />
                </V2Field>
                <button
                    type="button"
                    :class="outlineButton"
                    @click="resetFilters"
                >
                    Reset
                </button>
            </div>
        </V2Dialog>

        <V2Dialog
            :open="toDeactivate !== null"
            :title="
                toDeactivate
                    ? `Deactivate ${toDeactivate.displayName}?`
                    : 'Deactivate charge?'
            "
            description="It will not be offered on new invoices. Existing invoices are not changed. You can activate it again."
            confirm-label="Deactivate charge"
            tone="danger"
            :busy="toggling"
            @update:open="toDeactivate = $event ? toDeactivate : null"
            @confirm="toDeactivate && sendToggle(toDeactivate)"
        />

        <V2Dialog
            :open="bookOpen"
            title="New price book"
            confirm-label="Create price book"
            :busy="bookForm.processing"
            @update:open="bookOpen = $event"
            @confirm="submitBook()"
        >
            <V2Field v-slot="{ id }" label="Scope" :error="bookScopeError()">
                <OperationalSelect
                    :id="id"
                    v-model="bookForm.branch_id"
                    label="Scope"
                    :invalid="!!bookScopeError()"
                    :options="[
                        { value: '', label: 'Organisation-wide' },
                        ...branches.map((b) => ({
                            value: String(b.id),
                            label: b.name,
                        })),
                    ]"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Name"
                required
                :error="bookForm.errors.name"
            >
                <input
                    :id="id"
                    v-model="bookForm.name"
                    maxlength="150"
                    autocomplete="off"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="fieldClass(invalid)"
                />
            </V2Field>
        </V2Dialog>

        <V2Dialog
            :open="chargeOpen"
            title="New charge definition"
            confirm-label="Create charge"
            :busy="chargeForm.processing"
            @update:open="chargeOpen = $event"
            @confirm="submitCharge()"
        >
            <V2Field
                v-slot="{ id }"
                label="Type"
                :error="chargeForm.errors.type"
            >
                <OperationalSelect
                    :id="id"
                    v-model="chargeForm.type"
                    label="Type"
                    :invalid="!!chargeForm.errors.type"
                    :options="typeOptions"
                />
            </V2Field>
            <V2Field
                v-if="chargeForm.type === 'medicine'"
                v-slot="{ id }"
                label="Medicine"
                required
                :error="chargeForm.errors.medicine_public_id"
            >
                <OperationalSelect
                    :id="id"
                    v-model="chargeForm.medicine_public_id"
                    label="Medicine"
                    :invalid="!!chargeForm.errors.medicine_public_id"
                    :options="sourceOptions"
                />
            </V2Field>
            <V2Field
                v-else-if="chargeForm.type === 'service'"
                v-slot="{ id }"
                label="Clinical service"
                required
                :error="chargeForm.errors.service_public_id"
            >
                <OperationalSelect
                    :id="id"
                    v-model="chargeForm.service_public_id"
                    label="Clinical service"
                    :invalid="!!chargeForm.errors.service_public_id"
                    :options="sourceOptions"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Code"
                required
                :error="chargeForm.errors.code"
            >
                <input
                    :id="id"
                    v-model="chargeForm.code"
                    maxlength="64"
                    autocomplete="off"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="fieldClass(invalid)"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Display name"
                required
                :error="chargeForm.errors.display_name"
            >
                <input
                    :id="id"
                    v-model="chargeForm.display_name"
                    maxlength="500"
                    autocomplete="off"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="fieldClass(invalid)"
                />
            </V2Field>
        </V2Dialog>
    </PageFrame>
</template>
