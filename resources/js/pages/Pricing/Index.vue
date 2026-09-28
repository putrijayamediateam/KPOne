<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';

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
    { value: '', label: 'Select a Price Book' },
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

const chargeTypeOptions = [
    { value: 'consultation', label: 'Consultation' },
    { value: 'medicine', label: 'Medicine' },
    { value: 'service', label: 'Clinical Service' },
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
    chargeForm.post('/pricing/charges', {
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
const publish = (charge: ChargeRow) => {
    const state = publishForm(charge);

    if (!activeBranchId) {
        state.error = 'Select an active branch before publishing a price.';

        return;
    }

    const amountSen = Math.round(Number(state.amount) * 100);

    if (!state.priceBook || !Number.isFinite(amountSen) || amountSen < 0) {
        state.error = 'Select a Price Book and enter a valid amount.';

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
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Pricing"
            description="Manage Charge Definitions, Price Books and published prices."
        />

        <section class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-semibold">New Price Book</h2>
                <form class="mt-3 grid gap-3" @submit.prevent="submitBook">
                    <OperationalSelect
                        v-model="bookForm.branch_id"
                        label="Scope"
                        :options="[
                            { value: '', label: 'Organisation-wide' },
                            ...branches.map((b) => ({
                                value: String(b.id),
                                label: b.name,
                            })),
                        ]"
                    />
                    <label class="grid gap-1 text-xs">
                        Name
                        <Input
                            v-model="bookForm.name"
                            autocomplete="off"
                            maxlength="150"
                        />
                        <InputError :message="bookForm.errors.name" />
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="bookForm.processing"
                        >Create Price Book</Button
                    >
                </form>
            </div>

            <div class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-semibold">New Charge Definition</h2>
                <form class="mt-3 grid gap-3" @submit.prevent="submitCharge">
                    <OperationalSelect
                        v-model="chargeForm.type"
                        label="Type"
                        :options="chargeTypeOptions"
                    />
                    <OperationalSelect
                        v-if="chargeForm.type === 'medicine'"
                        v-model="chargeForm.medicine_public_id"
                        label="Medicine"
                        :options="sourceOptions()"
                    />
                    <OperationalSelect
                        v-else-if="chargeForm.type === 'service'"
                        v-model="chargeForm.service_public_id"
                        label="Clinical Service"
                        :options="sourceOptions()"
                    />
                    <label class="grid gap-1 text-xs">
                        Code
                        <Input
                            v-model="chargeForm.code"
                            autocomplete="off"
                            maxlength="64"
                        />
                        <InputError :message="chargeForm.errors.code" />
                    </label>
                    <label class="grid gap-1 text-xs">
                        Display name
                        <Input
                            v-model="chargeForm.display_name"
                            autocomplete="off"
                            maxlength="500"
                        />
                        <InputError :message="chargeForm.errors.display_name" />
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="chargeForm.processing"
                        >Create Charge Definition</Button
                    >
                </form>
            </div>
        </section>

        <OperationalTable
            label="Charge Definitions"
            :columns="5"
            :empty="charges.length === 0"
            empty-message="No Charge Definitions yet."
        >
            <template #head>
                <tr>
                    <th>Charge</th>
                    <th>Type</th>
                    <th>Current price (RM)</th>
                    <th v-if="canPublish">Publish price</th>
                    <th class="text-right">Status</th>
                </tr>
            </template>
            <template #body>
                <tr v-for="charge in charges" :key="charge.publicId">
                    <td>
                        <div class="font-medium">{{ charge.displayName }}</div>
                        <div class="text-xs text-muted-foreground">
                            {{ charge.code }}
                        </div>
                    </td>
                    <td class="capitalize">{{ charge.type }}</td>
                    <td class="tabular-nums">
                        {{
                            charge.currentPrice
                                ? (charge.currentPrice.amountSen / 100).toFixed(
                                      2,
                                  )
                                : '—'
                        }}
                    </td>
                    <td v-if="canPublish">
                        <div class="flex items-center gap-2">
                            <OperationalSelect
                                v-model="publishForm(charge).priceBook"
                                label="Price Book"
                                :options="bookOptions()"
                            />
                            <Input
                                v-model="publishForm(charge).amount"
                                class="w-24"
                                inputmode="decimal"
                                placeholder="0.00"
                            />
                            <Button
                                size="sm"
                                :disabled="publishForm(charge).busy"
                                @click="publish(charge)"
                            >
                                Publish
                            </Button>
                        </div>
                        <p
                            v-if="publishForm(charge).error"
                            class="mt-1 text-xs text-destructive"
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
                                :tone="charge.isActive ? 'success' : 'neutral'"
                            />
                            <Button
                                size="sm"
                                :variant="
                                    charge.isActive ? 'ghost' : 'secondary'
                                "
                                @click="toggleCharge(charge)"
                            >
                                {{
                                    charge.isActive ? 'Deactivate' : 'Activate'
                                }}
                            </Button>
                        </div>
                    </td>
                </tr>
            </template>
        </OperationalTable>
    </main>
</template>
