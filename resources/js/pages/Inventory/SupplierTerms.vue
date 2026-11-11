<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import InventorySearchSelect from '@/components/inventory/InventorySearchSelect.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { formatRinggit, packLabel, ringgitToSen } from '@/lib/supplier-terms';

type Row = {
    publicId: string;
    supplier: string;
    skuCode: string;
    itemName: string;
    stockUnit: string;
    packDescription: string;
    packQuantity: string;
    packPriceSen?: number | null;
    leadTimeDays: number;
    minimumOrderPacks: number | null;
    preferred: boolean;
    active: boolean;
    lockVersion: number;
};

const props = defineProps<{
    terms: {
        branchName: string;
        expectedBranchId: number;
        canManage: boolean;
        canSeeCost: boolean;
        filters: { q: string; status: 'active' | 'inactive' };
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();

const q = ref(props.terms.filters.q);
const status = ref(props.terms.filters.status);
const visit = (page = 1) =>
    router.get(
        '/inventory/supplier-terms',
        { q: q.value || undefined, status: status.value, page },
        { preserveScroll: true },
    );

const blank = () => ({
    expected_branch_id: props.terms.expectedBranchId,
    idempotency_key: crypto.randomUUID(),
    supplier_public_id: '' as string | number,
    sku_public_id: '' as string | number,
    pack_description: '',
    pack_quantity: '',
    pack_price_rm: '',
    lead_time_days: '',
    minimum_order_packs: '',
    is_preferred: false,
});
const create = useForm(blank());
const priceError = ref('');

// RM to sen with whole-number arithmetic only (STK-10B): a pack price is kept exactly as quoted.
const payload = (
    data: ReturnType<typeof blank> & { pack_price_rm: string },
) => {
    const sen = ringgitToSen(data.pack_price_rm);
    const rest: Record<string, unknown> = { ...data };
    delete rest.pack_price_rm;

    return {
        ...rest,
        lead_time_days:
            data.lead_time_days === '' ? null : Number(data.lead_time_days),
        minimum_order_packs:
            data.minimum_order_packs === ''
                ? null
                : Number(data.minimum_order_packs),
        ...(props.terms.canSeeCost ? { pack_price_sen: sen } : {}),
    };
};
const priceIsValid = (value: string) => ringgitToSen(value) !== undefined;

const submitCreate = () => {
    priceError.value = '';

    if (props.terms.canSeeCost && !priceIsValid(create.pack_price_rm)) {
        priceError.value =
            'Enter the pack price as RM with up to 2 decimal places, for example 12.34, or leave it blank.';

        return;
    }

    create
        .transform((data) => payload(data))
        .post('/inventory/supplier-terms', {
            preserveScroll: true,
            onSuccess: () => {
                create.defaults(blank());
                create.reset();
            },
            onFinish: () => {
                create.idempotency_key = crypto.randomUUID();
            },
        });
};

const editing = ref<string | null>(null);
const edit = useForm({
    ...blank(),
    lock_version: 1,
    is_active: true,
});
const editPriceError = ref('');
const startEdit = (row: Row) => {
    editing.value = row.publicId;
    editPriceError.value = '';
    edit.clearErrors();
    Object.assign(edit, {
        expected_branch_id: props.terms.expectedBranchId,
        idempotency_key: crypto.randomUUID(),
        pack_description: row.packDescription,
        pack_quantity: row.packQuantity,
        pack_price_rm: formatRinggit(row.packPriceSen ?? null),
        lead_time_days: String(row.leadTimeDays),
        minimum_order_packs:
            row.minimumOrderPacks === null ? '' : String(row.minimumOrderPacks),
        is_preferred: row.preferred,
        is_active: row.active,
        lock_version: row.lockVersion,
    });
};
const submitEdit = (row: Row) => {
    editPriceError.value = '';

    if (props.terms.canSeeCost && !priceIsValid(edit.pack_price_rm)) {
        editPriceError.value =
            'Enter the pack price as RM with up to 2 decimal places, for example 12.34, or leave it blank.';

        return;
    }

    edit.transform((data) => {
        const rest: Record<string, unknown> = payload(data);
        delete rest.supplier_public_id;
        delete rest.sku_public_id;

        return rest;
    }).put(`/inventory/supplier-terms/${row.publicId}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
        onFinish: () => {
            edit.idempotency_key = crypto.randomUUID();
        },
    });
};
</script>

<template>
    <Head title="Supplier price list" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Supplier price list"
            :description="`${terms.branchName} · Pack sizes, prices and lead times for each supplier and SKU.`"
        />
        <p
            v-if="!terms.canSeeCost"
            class="text-sm text-muted-foreground"
            data-testid="price-hidden-note"
        >
            Prices are shown only to a Director or Finance Officer.
        </p>

        <section
            v-if="terms.canManage"
            aria-labelledby="add-terms-heading"
            class="space-y-3 rounded-lg border bg-card p-4"
        >
            <h2 id="add-terms-heading" class="text-lg font-semibold">
                Add a price list row
            </h2>
            <form
                class="grid gap-3 sm:grid-cols-2"
                @submit.prevent="submitCreate"
            >
                <div
                    class="grid min-w-0 gap-1 [&_button]:h-11! lg:[&_button]:h-9!"
                >
                    <p class="text-sm">Supplier (required)</p>
                    <InventorySearchSelect
                        v-model="create.supplier_public_id"
                        :options="[]"
                        label="Supplier"
                        kind="suppliers"
                        :expected-branch-id="terms.expectedBranchId"
                    />
                    <InputError :message="create.errors.supplier_public_id" />
                </div>
                <div
                    class="grid min-w-0 gap-1 [&_button]:h-11! lg:[&_button]:h-9!"
                >
                    <p class="text-sm">SKU (required)</p>
                    <InventorySearchSelect
                        v-model="create.sku_public_id"
                        :options="[]"
                        label="SKU"
                        kind="skus"
                        context="operations"
                        :expected-branch-id="terms.expectedBranchId"
                    />
                    <InputError :message="create.errors.sku_public_id" />
                </div>
                <label class="grid min-w-0 gap-1 text-sm"
                    >Pack (required)
                    <Input
                        v-model="create.pack_description"
                        maxlength="60"
                        placeholder="Box of 100"
                        required
                    />
                    <InputError :message="create.errors.pack_description" />
                </label>
                <label class="grid min-w-0 gap-1 text-sm"
                    >Stock units in one pack (required)
                    <Input
                        v-model="create.pack_quantity"
                        inputmode="decimal"
                        required
                    />
                    <InputError :message="create.errors.pack_quantity" />
                </label>
                <label
                    v-if="terms.canSeeCost"
                    class="grid min-w-0 gap-1 text-sm"
                    >Price for one pack (RM, optional)
                    <Input
                        v-model="create.pack_price_rm"
                        inputmode="decimal"
                        placeholder="0.00"
                    />
                    <InputError
                        :message="
                            priceError ||
                            (create.errors as Record<string, string>)
                                .pack_price_sen
                        "
                    />
                </label>
                <label class="grid min-w-0 gap-1 text-sm"
                    >Lead time in days (required)
                    <Input
                        v-model="create.lead_time_days"
                        inputmode="numeric"
                        required
                    />
                    <InputError :message="create.errors.lead_time_days" />
                </label>
                <label class="grid min-w-0 gap-1 text-sm"
                    >Minimum order in packs (optional)
                    <Input
                        v-model="create.minimum_order_packs"
                        inputmode="numeric"
                    />
                    <InputError :message="create.errors.minimum_order_packs" />
                </label>
                <label
                    class="flex min-h-11 items-center gap-2 text-sm sm:col-span-2"
                    ><input
                        v-model="create.is_preferred"
                        type="checkbox"
                        class="size-5"
                    />Preferred supplier for this SKU</label
                >
                <div class="sm:col-span-2">
                    <Button
                        type="submit"
                        class="h-11 lg:h-9"
                        :disabled="create.processing"
                        >Add row</Button
                    >
                </div>
            </form>
        </section>

        <form
            class="flex flex-wrap items-end gap-2"
            role="search"
            @submit.prevent="visit()"
        >
            <label
                class="grid min-w-0 basis-full gap-1 text-sm sm:flex-1 sm:basis-auto"
                >Search SKU, item or supplier
                <Input v-model="q" maxlength="100" />
            </label>
            <label class="grid gap-1 text-sm"
                >Show
                <select
                    v-model="status"
                    class="h-11 rounded-md border bg-background px-3"
                >
                    <option value="active">Active rows</option>
                    <option value="inactive">Inactive rows</option>
                </select>
            </label>
            <Button type="submit" variant="outline" class="h-11 lg:h-9"
                >Search</Button
            >
        </form>

        <p v-if="terms.rows.length === 0" class="text-sm text-muted-foreground">
            No price list rows yet.
        </p>
        <ul v-else class="space-y-3" aria-label="Price list rows">
            <li
                v-for="row in terms.rows"
                :key="row.publicId"
                class="space-y-2 rounded-lg border bg-card p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ row.skuCode }} · {{ row.itemName }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ row.supplier }}
                            <span
                                v-if="row.preferred"
                                class="ml-1 rounded border px-1.5 py-0.5 text-xs"
                                >Preferred</span
                            >
                            <span
                                v-if="!row.active"
                                class="ml-1 rounded border px-1.5 py-0.5 text-xs"
                                >Inactive</span
                            >
                        </p>
                    </div>
                    <Button
                        v-if="terms.canManage && editing !== row.publicId"
                        variant="outline"
                        class="h-11 lg:h-9"
                        :aria-label="`Edit ${row.skuCode} from ${row.supplier}`"
                        @click="startEdit(row)"
                        >Edit</Button
                    >
                </div>
                <dl
                    v-if="editing !== row.publicId"
                    class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <dt class="text-muted-foreground">Pack</dt>
                        <dd>
                            {{
                                packLabel(
                                    row.packDescription,
                                    row.packQuantity,
                                    row.stockUnit,
                                )
                            }}
                        </dd>
                    </div>
                    <div v-if="terms.canSeeCost">
                        <dt class="text-muted-foreground">
                            Price for one pack
                        </dt>
                        <dd class="tabular-nums">
                            {{
                                row.packPriceSen === null ||
                                row.packPriceSen === undefined
                                    ? 'Not recorded'
                                    : `RM ${formatRinggit(row.packPriceSen)}`
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Lead time</dt>
                        <dd>
                            {{ row.leadTimeDays }}
                            {{ row.leadTimeDays === 1 ? 'day' : 'days' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Minimum order</dt>
                        <dd>
                            {{
                                row.minimumOrderPacks === null
                                    ? 'None'
                                    : `${row.minimumOrderPacks} ${row.minimumOrderPacks === 1 ? 'pack' : 'packs'}`
                            }}
                        </dd>
                    </div>
                </dl>
                <form
                    v-else
                    class="grid gap-3 sm:grid-cols-2"
                    @submit.prevent="submitEdit(row)"
                >
                    <label class="grid min-w-0 gap-1 text-sm"
                        >Pack (required)
                        <Input
                            v-model="edit.pack_description"
                            maxlength="60"
                            required
                        />
                        <InputError :message="edit.errors.pack_description" />
                    </label>
                    <label class="grid min-w-0 gap-1 text-sm"
                        >Stock units in one pack (required)
                        <Input
                            v-model="edit.pack_quantity"
                            inputmode="decimal"
                            required
                        />
                        <InputError :message="edit.errors.pack_quantity" />
                    </label>
                    <label
                        v-if="terms.canSeeCost"
                        class="grid min-w-0 gap-1 text-sm"
                        >Price for one pack (RM, optional)
                        <Input
                            v-model="edit.pack_price_rm"
                            inputmode="decimal"
                            placeholder="0.00"
                        />
                        <InputError
                            :message="
                                editPriceError ||
                                (edit.errors as Record<string, string>)
                                    .pack_price_sen
                            "
                        />
                    </label>
                    <label class="grid min-w-0 gap-1 text-sm"
                        >Lead time in days (required)
                        <Input
                            v-model="edit.lead_time_days"
                            inputmode="numeric"
                            required
                        />
                        <InputError :message="edit.errors.lead_time_days" />
                    </label>
                    <label class="grid min-w-0 gap-1 text-sm"
                        >Minimum order in packs (optional)
                        <Input
                            v-model="edit.minimum_order_packs"
                            inputmode="numeric"
                        />
                        <InputError
                            :message="edit.errors.minimum_order_packs"
                        />
                    </label>
                    <div
                        class="flex flex-wrap items-center gap-x-6 sm:col-span-2"
                    >
                        <label class="flex min-h-11 items-center gap-2 text-sm"
                            ><input
                                v-model="edit.is_preferred"
                                type="checkbox"
                                class="size-5"
                            />Preferred supplier for this SKU</label
                        >
                        <label class="flex min-h-11 items-center gap-2 text-sm"
                            ><input
                                v-model="edit.is_active"
                                type="checkbox"
                                @change="
                                    edit.is_preferred =
                                        edit.is_active && edit.is_preferred
                                "
                                class="size-5"
                            />Active</label
                        >
                        <InputError
                            :message="
                                edit.errors.is_preferred ||
                                edit.errors.lock_version
                            "
                        />
                    </div>
                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <Button
                            type="submit"
                            class="h-11 lg:h-9"
                            :disabled="edit.processing"
                            >Save changes</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="h-11 lg:h-9"
                            @click="editing = null"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </li>
        </ul>
        <CompactPagination
            v-if="terms.total > 0"
            class="[&_button]:h-11! lg:[&_button]:h-8!"
            :current-page="terms.page"
            :last-page="terms.lastPage"
            :total="terms.total"
            @change="(page) => visit(page)"
        />
    </main>
</template>
