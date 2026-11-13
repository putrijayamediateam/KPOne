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
const scheduledText = (charge: ChargeRow) =>
    charge.scheduledPrice
        ? `From ${charge.scheduledPrice.effectiveLabel}: RM ${(charge.scheduledPrice.amountSen / 100).toFixed(2)}`
        : null;

// --- row menu
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
const publish = usePricingPublish(activeBranchId);
const bookOptions = computed(() => [
    { value: '', label: 'Select a price book' },
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
                        :class="[outlineButton, 'max-sm:order-3']"
                        >Check price list</Link
                    >
                    <button
                        type="button"
                        :class="[outlineButton, 'max-sm:order-2']"
                        data-action="new-price-book"
                        @click="bookOpen = true"
                    >
                        New price book
                    </button>
                    <button
                        type="button"
                        :class="[filledButton, 'max-sm:order-1']"
                        data-action="new-charge"
                        @click="chargeOpen = true"
                    >
                        New charge
                    </button>
                </div>
            </template>

            <template #main>
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
                        <div class="w-full sm:w-44">
                            <OperationalSelect
                                v-model="typeFilter"
                                label="Type"
                                :options="[
                                    { value: '', label: 'All types' },
                                    ...typeOptions,
                                ]"
                            />
                        </div>
                        <div class="w-full sm:w-40">
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
                </V2Toolbar>

                <p class="text-[13px] text-muted-foreground">
                    {{ filtered.length }}
                    {{ filtered.length === 1 ? 'charge' : 'charges' }}
                </p>

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
                                    >{{ charge.code }}</span
                                >
                            </td>
                            <td data-label="Type" class="whitespace-nowrap">
                                {{ typeLabel(charge.type) }}
                            </td>
                            <td data-label="Current price" class="text-right">
                                <div class="flex flex-col items-end">
                                    <span>{{ priceText(charge) }}</span>
                                    <span
                                        v-if="scheduledText(charge)"
                                        class="text-xs text-muted-foreground"
                                        >{{ scheduledText(charge) }}</span
                                    >
                                </div>
                            </td>
                            <td data-label="Status">
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
                                        @click="publish.begin(charge)"
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
                <p>
                    <span class="text-muted-foreground">Current price</span>
                    ·
                    <span class="font-medium">{{
                        priceText(publish.target.value as ChargeRow)
                    }}</span>
                </p>
                <p
                    v-if="scheduledText(publish.target.value as ChargeRow)"
                    class="text-muted-foreground"
                >
                    {{ scheduledText(publish.target.value as ChargeRow) }}
                </p>
            </div>
            <V2Field
                v-slot="{ id }"
                label="Price book"
                required
                :error="
                    publish.form.error && !publish.form.priceBook
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
                :error="publish.form.priceBook ? publish.form.error : undefined"
            >
                <input
                    :id="id"
                    v-model="publish.form.amount"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="e.g. 25.00"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                    data-field="amount"
                    @keydown.enter.prevent="publish.submit()"
                />
            </V2Field>
            <button
                v-if="publish.form.stale"
                type="button"
                :class="outlineButton"
                data-action="reload-prices"
                @click="publish.reload()"
            >
                Reload prices
            </button>
        </V2Dialog>

        <V2Dialog
            :open="toDeactivate !== null"
            :title="
                toDeactivate
                    ? `Deactivate ${toDeactivate.displayName}?`
                    : 'Deactivate charge?'
            "
            description="An inactive charge can no longer be billed. Its prices and history are kept."
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
                    :class="inputClass"
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
                    :class="inputClass"
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
                    :class="inputClass"
                />
            </V2Field>
        </V2Dialog>
    </PageFrame>
</template>
