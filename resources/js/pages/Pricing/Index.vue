<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { rmToSen } from '@/lib/money';

type ChargeRow = {
    publicId: string;
    code: string;
    displayName: string;
    type: 'consultation' | 'medicine' | 'service';
    unit: string;
    isActive: boolean;
    currentPrice: {
        priceBookPublicId: string;
        amountSen: number;
        version: number;
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

const page = usePage<{ branchContext?: { active?: { id: number } | null } }>();
const activeBranchId = page.props.branchContext?.active?.id ?? null;

const bookOptions = () => [
    { value: '', label: 'Select a price book' },
    ...props.priceBooks
        .filter((book) => book.isActive)
        .map((book) => ({
            value: book.publicId,
            label: `${book.name} (${book.branchId === null ? 'Organisation-wide' : 'Branch'})`,
        })),
];

const bookForm = useForm({ branch_id: '', name: '', currency: 'MYR' });
const submitBook = () => {
    bookForm.post('/pricing/price-books', {
        preserveScroll: true,
        onSuccess: () => bookForm.reset(),
    });
};
// The domain service rejects a duplicate Price Book scope with a
// ValidationException keyed on a synthetic 'scope' field, which has no
// corresponding form input, so it is read via a loose cast rather than
// bookForm.errors (typed strictly to branch_id/name/currency).
const bookScopeError = () =>
    (bookForm.errors as Record<string, string | undefined>).scope ??
    bookForm.errors.branch_id;

const chargeTypeOptions = [
    { value: 'consultation', label: 'Consultation' },
    { value: 'medicine', label: 'Medicine' },
    { value: 'service', label: 'Clinical service' },
];
const chargeForm = useForm({
    type: 'consultation' as 'consultation' | 'medicine' | 'service',
    medicine_public_id: '',
    service_public_id: '',
    code: '',
    display_name: '',
});
const sourceOptions = () =>
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
          : [];
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
            onSuccess: () => chargeForm.reset(),
        });
};

const publishForms = reactive<
    Record<
        string,
        { priceBook: string; amount: string; busy: boolean; error: string }
    >
>({});
const publishForm = (charge: ChargeRow) => {
    if (!publishForms[charge.publicId]) {
        publishForms[charge.publicId] = {
            priceBook: charge.currentPrice?.priceBookPublicId ?? '',
            amount: charge.currentPrice
                ? (charge.currentPrice.amountSen / 100).toFixed(2)
                : '',
            busy: false,
            error: '',
        };
    }

    return publishForms[charge.publicId];
};
const emptyAmountMessage = 'Enter an amount. Type 0.00 if this is free.';
const publish = (charge: ChargeRow) => {
    const state = publishForm(charge);

    if (!activeBranchId) {
        state.error = 'Select an active branch before publishing a price.';

        return;
    }

    // CAT-1b: an empty box is never read as RM 0.00; a typed 0.00 is allowed.
    // The server refuses an empty amount with the same message.
    const amountSen = rmToSen(state.amount);

    if (amountSen === null) {
        state.error = emptyAmountMessage;

        return;
    }

    if (!state.priceBook || !Number.isFinite(amountSen) || amountSen < 0) {
        state.error = 'Select a price book and enter a valid amount.';

        return;
    }

    state.busy = true;
    state.error = '';
    router.post(
        `/pricing/charges/${charge.publicId}/publish`,
        {
            price_book_public_id: state.priceBook,
            amount_sen: amountSen,
            expected_version: charge.currentPrice?.version ?? 0,
            expected_branch_id: activeBranchId,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                state.error =
                    Object.values(errors)[0] ??
                    'The price could not be published.';
            },
            onFinish: () => {
                state.busy = false;
            },
        },
    );
};

const typeLabel = (type: ChargeRow['type']) =>
    chargeTypeOptions.find((option) => option.value === type)?.label ?? type;
const priceText = (charge: ChargeRow) =>
    charge.currentPrice
        ? `RM ${(charge.currentPrice.amountSen / 100).toFixed(2)}`
        : '—';

const toggleCharge = (charge: ChargeRow) => {
    router.post(
        `/pricing/charges/${charge.publicId}/${charge.isActive ? 'deactivate' : 'activate'}`,
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl min-w-0 flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Pricing"
            description="Manage charge definitions, price books and published prices."
        >
            <template #actions>
                <Button variant="outline" size="sm" as-child>
                    <Link href="/pricing/price-check">Check price list</Link>
                </Button>
            </template>
        </PageHeader>

        <section class="grid gap-4 md:grid-cols-2">
            <div class="min-w-0 rounded-xl border bg-card p-4">
                <h2 class="text-sm font-semibold">New price book</h2>
                <form class="mt-3 grid gap-3" @submit.prevent="submitBook">
                    <div class="grid gap-2">
                        <Label id="book-scope-label" for="book-scope"
                            >Scope</Label
                        >
                        <OperationalSelect
                            id="book-scope"
                            v-model="bookForm.branch_id"
                            label="Scope"
                            labelledby="book-scope-label"
                            :invalid="!!bookScopeError()"
                            :options="[
                                { value: '', label: 'Organisation-wide' },
                                ...branches.map((b) => ({
                                    value: String(b.id),
                                    label: b.name,
                                })),
                            ]"
                        />
                        <InputError :message="bookScopeError()" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="book-name">Name *</Label>
                        <Input
                            id="book-name"
                            v-model="bookForm.name"
                            autocomplete="off"
                            maxlength="150"
                            aria-required="true"
                            :aria-invalid="!!bookForm.errors.name || undefined"
                        />
                        <InputError :message="bookForm.errors.name" />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        class="justify-self-start"
                        :disabled="bookForm.processing"
                        >Create price book</Button
                    >
                </form>
            </div>

            <div class="min-w-0 rounded-xl border bg-card p-4">
                <h2 class="text-sm font-semibold">New charge definition</h2>
                <form class="mt-3 grid gap-3" @submit.prevent="submitCharge">
                    <div class="grid gap-2">
                        <Label id="charge-type-label" for="charge-type"
                            >Type</Label
                        >
                        <OperationalSelect
                            id="charge-type"
                            v-model="chargeForm.type"
                            label="Type"
                            labelledby="charge-type-label"
                            :invalid="!!chargeForm.errors.type"
                            :options="chargeTypeOptions"
                        />
                        <InputError :message="chargeForm.errors.type" />
                    </div>
                    <div
                        v-if="chargeForm.type === 'medicine'"
                        class="grid gap-2"
                    >
                        <Label id="charge-medicine-label" for="charge-medicine"
                            >Medicine *</Label
                        >
                        <OperationalSelect
                            id="charge-medicine"
                            v-model="chargeForm.medicine_public_id"
                            label="Medicine"
                            labelledby="charge-medicine-label"
                            :invalid="!!chargeForm.errors.medicine_public_id"
                            :options="sourceOptions()"
                        />
                        <InputError
                            :message="chargeForm.errors.medicine_public_id"
                        />
                    </div>
                    <div
                        v-else-if="chargeForm.type === 'service'"
                        class="grid gap-2"
                    >
                        <Label id="charge-service-label" for="charge-service"
                            >Clinical service *</Label
                        >
                        <OperationalSelect
                            id="charge-service"
                            v-model="chargeForm.service_public_id"
                            label="Clinical service"
                            labelledby="charge-service-label"
                            :invalid="!!chargeForm.errors.service_public_id"
                            :options="sourceOptions()"
                        />
                        <InputError
                            :message="chargeForm.errors.service_public_id"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="charge-code">Code *</Label>
                        <Input
                            id="charge-code"
                            v-model="chargeForm.code"
                            autocomplete="off"
                            maxlength="64"
                            aria-required="true"
                            :aria-invalid="
                                !!chargeForm.errors.code || undefined
                            "
                        />
                        <InputError :message="chargeForm.errors.code" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="charge-display-name">Display name *</Label>
                        <Input
                            id="charge-display-name"
                            v-model="chargeForm.display_name"
                            autocomplete="off"
                            maxlength="500"
                            aria-required="true"
                            :aria-invalid="
                                !!chargeForm.errors.display_name || undefined
                            "
                        />
                        <InputError :message="chargeForm.errors.display_name" />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        class="justify-self-start"
                        :disabled="chargeForm.processing"
                        >Create charge definition</Button
                    >
                </form>
            </div>
        </section>

        <section class="grid gap-3 md:hidden" aria-label="Charge definitions">
            <h2 class="text-sm font-semibold">Charge definitions</h2>
            <EmptyState
                v-if="charges.length === 0"
                title="No charge definitions yet."
                description="Create one above to start pricing."
            />
            <article
                v-for="charge in charges"
                :key="charge.publicId"
                class="grid min-w-0 gap-3 rounded-xl border bg-card p-4"
                data-testid="charge-card"
            >
                <div class="flex min-w-0 items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="font-medium break-words">
                            {{ charge.displayName }}
                        </h3>
                        <p class="text-xs break-words text-muted-foreground">
                            {{ charge.code }} · {{ typeLabel(charge.type) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <StatusBadge
                            :status="charge.isActive ? 'active' : 'inactive'"
                            :tone="charge.isActive ? 'success' : 'neutral'"
                        />
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="min-h-11 min-w-11"
                                    :aria-label="`More actions for ${charge.displayName}`"
                                    data-action="charge-card-menu"
                                >
                                    <Ellipsis
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-48">
                                <DropdownMenuItem
                                    class="min-h-11"
                                    @select="toggleCharge(charge)"
                                    >{{
                                        charge.isActive
                                            ? 'Deactivate'
                                            : 'Activate'
                                    }}</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="text-muted-foreground">Current price</span>
                    <span class="font-medium tabular-nums">{{
                        priceText(charge)
                    }}</span>
                </div>
                <div v-if="canPublish" class="grid gap-3">
                    <div class="grid gap-2">
                        <Label :for="`m-book-${charge.publicId}`"
                            >Price book *</Label
                        >
                        <OperationalSelect
                            :id="`m-book-${charge.publicId}`"
                            v-model="publishForm(charge).priceBook"
                            :label="`Price book for ${charge.displayName}`"
                            :options="bookOptions()"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`m-amount-${charge.publicId}`"
                            >Amount (RM) *</Label
                        >
                        <Input
                            :id="`m-amount-${charge.publicId}`"
                            :aria-describedby="
                                publishForm(charge).error
                                    ? `m-amount-error-${charge.publicId}`
                                    : undefined
                            "
                            v-model="publishForm(charge).amount"
                            :aria-label="`Amount (RM) for ${charge.displayName}`"
                            aria-required="true"
                            :aria-invalid="
                                !!publishForm(charge).error || undefined
                            "
                            inputmode="decimal"
                            placeholder="e.g. 25.00"
                        />
                    </div>
                    <p
                        v-if="publishForm(charge).error"
                        :id="`m-amount-error-${charge.publicId}`"
                        class="text-xs text-destructive"
                        role="alert"
                    >
                        {{ publishForm(charge).error }}
                    </p>
                    <Button
                        class="min-h-11 w-full"
                        variant="outline"
                        :disabled="publishForm(charge).busy"
                        @click="publish(charge)"
                    >
                        {{
                            publishForm(charge).busy
                                ? 'Publishing…'
                                : 'Publish price'
                        }}
                    </Button>
                </div>
                <Button
                    variant="ghost"
                    class="min-h-11 w-full"
                    as-child
                    data-action="price-history"
                >
                    <Link
                        :href="`/pricing/charges/${charge.publicId}/history`"
                        :aria-label="`Price history for ${charge.displayName}`"
                        >Price history</Link
                    >
                </Button>
            </article>
        </section>

        <div class="hidden min-w-0 md:block">
            <OperationalTable
                label="Charge definitions"
                :columns="canPublish ? 5 : 4"
                :empty="charges.length === 0"
                empty-message="No charge definitions yet. Create one above to start pricing."
            >
                <template #head>
                    <tr>
                        <th>Charge</th>
                        <th>Type</th>
                        <th class="text-right">Current price</th>
                        <th v-if="canPublish">Publish price</th>
                        <th class="text-right">Status</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="charge in charges" :key="charge.publicId">
                        <td>
                            <div class="font-medium">
                                {{ charge.displayName }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ charge.code }}
                            </div>
                        </td>
                        <td>{{ typeLabel(charge.type) }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{ priceText(charge) }}
                        </td>
                        <td v-if="canPublish">
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="grid min-w-48 flex-1 gap-1">
                                    <Label
                                        :for="`d-book-${charge.publicId}`"
                                        class="text-xs"
                                        >Price book *</Label
                                    >
                                    <OperationalSelect
                                        :id="`d-book-${charge.publicId}`"
                                        v-model="publishForm(charge).priceBook"
                                        :label="`Price book for ${charge.displayName}`"
                                        :options="bookOptions()"
                                    />
                                </div>
                                <div class="grid w-28 gap-1">
                                    <Label
                                        :for="`d-amount-${charge.publicId}`"
                                        class="text-xs"
                                        >Amount (RM) *</Label
                                    >
                                    <Input
                                        :id="`d-amount-${charge.publicId}`"
                                        :aria-describedby="
                                            publishForm(charge).error
                                                ? `d-amount-error-${charge.publicId}`
                                                : undefined
                                        "
                                        v-model="publishForm(charge).amount"
                                        :aria-label="`Amount (RM) for ${charge.displayName}`"
                                        aria-required="true"
                                        :aria-invalid="
                                            !!publishForm(charge).error ||
                                            undefined
                                        "
                                        inputmode="decimal"
                                        placeholder="e.g. 25.00"
                                    />
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="publishForm(charge).busy"
                                    @click="publish(charge)"
                                >
                                    {{
                                        publishForm(charge).busy
                                            ? 'Publishing…'
                                            : 'Publish price'
                                    }}
                                </Button>
                            </div>
                            <p
                                v-if="publishForm(charge).error"
                                :id="`d-amount-error-${charge.publicId}`"
                                class="mt-1 text-xs text-destructive"
                                role="alert"
                            >
                                {{ publishForm(charge).error }}
                            </p>
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <StatusBadge
                                    :status="
                                        charge.isActive ? 'active' : 'inactive'
                                    "
                                    :tone="
                                        charge.isActive ? 'success' : 'neutral'
                                    "
                                />
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    as-child
                                    data-action="price-history"
                                >
                                    <Link
                                        :href="`/pricing/charges/${charge.publicId}/history`"
                                        :aria-label="`Price history for ${charge.displayName}`"
                                        >Price history</Link
                                    >
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-8"
                                            :aria-label="`More actions for ${charge.displayName}`"
                                            data-action="charge-row-menu"
                                        >
                                            <Ellipsis
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-48"
                                    >
                                        <DropdownMenuItem
                                            @select="toggleCharge(charge)"
                                            >{{
                                                charge.isActive
                                                    ? 'Deactivate'
                                                    : 'Activate'
                                            }}</DropdownMenuItem
                                        >
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </td>
                    </tr>
                </template>
            </OperationalTable>
        </div>
    </main>
</template>
