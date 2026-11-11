<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';

type Sku = {
    publicId: string;
    skuCode: string;
    barcode: string | null;
    packSize: string;
    purchaseUnit: string;
    stockUnit: string;
    dispensingUnit: string;
    unitConversion: string;
    storageType: string;
    minimumTemperature: string | null;
    maximumTemperature: string | null;
    coldChainRequired: boolean;
    doNotFreeze: boolean;
    protectFromLight: boolean;
    batchTrackingRequired: boolean;
    expiryTrackingRequired: boolean;
    active: boolean;
    lockVersion: number;
    unitsLocked: boolean;
    item: {
        publicId: string;
        code: string;
        genericName: string;
        brandName: string | null;
        active: boolean;
        lockVersion: number;
    };
};

const props = defineProps<{
    skus: {
        branchName: string;
        canManage: boolean;
        filters: { q: string; status: 'active' | 'inactive' | 'all' };
        rows: Sku[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();

const q = ref(props.skus.filters.q);
const status = ref(props.skus.filters.status);
const visit = (page = 1) =>
    router.get(
        '/inventory/skus',
        { q: q.value || undefined, status: status.value, page },
        { preserveScroll: true },
    );

const storageTypes = [
    { value: 'ambient', label: 'Room temperature' },
    { value: 'refrigerated', label: 'Refrigerated' },
    { value: 'frozen', label: 'Frozen' },
];
// A value saved before this screen keeps showing, so editing never changes it silently.
const storageOptions = (current: string) =>
    storageTypes.some((type) => type.value === current)
        ? storageTypes
        : [...storageTypes, { value: current, label: current }];
const storageLabel = (value: string) =>
    storageTypes.find((type) => type.value === value)?.label ?? value;

const editing = ref<string | null>(null);
const form = useForm({
    generic_name: '',
    brand_name: '',
    sku_code: '',
    barcode: '',
    pack_size: '',
    purchase_unit: '',
    stock_unit: '',
    dispensing_unit: '',
    unit_conversion: '',
    storage_type: 'ambient',
    minimum_temperature: '',
    maximum_temperature: '',
    cold_chain_required: false,
    do_not_freeze: false,
    protect_from_light: false,
    batch_tracking_required: true,
    expiry_tracking_required: true,
    sku_active: true,
    item_active: true,
    sku_lock_version: 1,
    item_lock_version: 1,
});
const startEdit = (sku: Sku) => {
    editing.value = sku.publicId;
    form.clearErrors();
    Object.assign(form, {
        generic_name: sku.item.genericName,
        brand_name: sku.item.brandName ?? '',
        sku_code: sku.skuCode,
        barcode: sku.barcode ?? '',
        pack_size: sku.packSize,
        purchase_unit: sku.purchaseUnit,
        stock_unit: sku.stockUnit,
        dispensing_unit: sku.dispensingUnit,
        unit_conversion: sku.unitConversion,
        storage_type: sku.storageType,
        minimum_temperature: sku.minimumTemperature ?? '',
        maximum_temperature: sku.maximumTemperature ?? '',
        cold_chain_required: sku.coldChainRequired,
        do_not_freeze: sku.doNotFreeze,
        protect_from_light: sku.protectFromLight,
        batch_tracking_required: sku.batchTrackingRequired,
        expiry_tracking_required: sku.expiryTrackingRequired,
        sku_active: sku.active,
        item_active: sku.item.active,
        sku_lock_version: sku.lockVersion,
        item_lock_version: sku.item.lockVersion,
    });
};
const save = (sku: Sku) => {
    form.transform((data) => {
        const sent: Record<string, unknown> = {
            ...data,
            brand_name: data.brand_name.trim() === '' ? null : data.brand_name,
            barcode: data.barcode.trim() === '' ? null : data.barcode,
            minimum_temperature:
                data.minimum_temperature === ''
                    ? null
                    : data.minimum_temperature,
            maximum_temperature:
                data.maximum_temperature === ''
                    ? null
                    : data.maximum_temperature,
        };

        // Locked unit fields are never sent, so the server never sees a change to them.
        if (sku.unitsLocked) {
            for (const field of [
                'sku_code',
                'pack_size',
                'purchase_unit',
                'stock_unit',
                'dispensing_unit',
                'unit_conversion',
                'batch_tracking_required',
                'expiry_tracking_required',
            ]) {
                delete sent[field];
            }
        }

        return sent;
    }).put(`/inventory/skus/${sku.publicId}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
};
const errorFor = (field: string) =>
    (form.errors as Record<string, string | undefined>)[field];
</script>

<template>
    <Head title="SKUs" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="SKUs"
            :description="`${skus.branchName} · Names, barcode, storage and active status for each stock item.`"
        />

        <form
            class="flex flex-wrap items-end gap-2"
            role="search"
            @submit.prevent="visit()"
        >
            <label
                class="grid min-w-0 basis-full gap-1 text-sm sm:flex-1 sm:basis-auto"
                >Search SKU code, name or barcode
                <Input v-model="q" maxlength="100" />
            </label>
            <label class="grid gap-1 text-sm"
                >Show
                <select
                    v-model="status"
                    class="h-11 rounded-md border bg-background px-3"
                >
                    <option value="active">Active SKUs</option>
                    <option value="inactive">Inactive SKUs</option>
                    <option value="all">All SKUs</option>
                </select>
            </label>
            <Button type="submit" variant="outline" class="h-11 lg:h-9"
                >Search</Button
            >
        </form>

        <p v-if="skus.rows.length === 0" class="text-sm text-muted-foreground">
            No SKUs match.
        </p>
        <ul v-else class="space-y-3" aria-label="SKUs">
            <li
                v-for="sku in skus.rows"
                :key="sku.publicId"
                class="space-y-3 rounded-lg border bg-card p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ sku.skuCode }} · {{ sku.item.genericName }}
                            <span v-if="sku.item.brandName">
                                ({{ sku.item.brandName }})</span
                            >
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Stock unit: {{ sku.stockUnit }}
                            <span v-if="sku.barcode">
                                · Barcode {{ sku.barcode }}</span
                            >
                            <span
                                v-if="!sku.active"
                                class="ml-1 rounded border px-1.5 py-0.5 text-xs"
                                >Inactive</span
                            >
                        </p>
                    </div>
                    <Button
                        v-if="skus.canManage && editing !== sku.publicId"
                        variant="outline"
                        class="h-11 lg:h-9"
                        :aria-label="`Edit ${sku.skuCode}`"
                        @click="startEdit(sku)"
                        >Edit</Button
                    >
                </div>

                <dl
                    v-if="editing !== sku.publicId"
                    class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <dt class="text-muted-foreground">Storage</dt>
                        <dd>{{ storageLabel(sku.storageType) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Temperature</dt>
                        <dd>
                            {{
                                sku.minimumTemperature === null &&
                                sku.maximumTemperature === null
                                    ? 'Not set'
                                    : `${sku.minimumTemperature ?? '–'} to ${sku.maximumTemperature ?? '–'} °C`
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Handling</dt>
                        <dd>
                            {{
                                [
                                    sku.coldChainRequired && 'Cold chain',
                                    sku.doNotFreeze && 'Do not freeze',
                                    sku.protectFromLight &&
                                        'Protect from light',
                                ]
                                    .filter(Boolean)
                                    .join(', ') || 'None'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Dispensing unit</dt>
                        <dd>{{ sku.dispensingUnit }}</dd>
                    </div>
                </dl>

                <form v-else class="space-y-4" @submit.prevent="save(sku)">
                    <fieldset class="grid gap-3 sm:grid-cols-2">
                        <legend class="mb-2 text-sm font-semibold">Item</legend>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Generic name (required)
                            <Input
                                v-model="form.generic_name"
                                maxlength="300"
                                required
                            />
                            <InputError :message="errorFor('generic_name')" />
                        </label>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Brand name (optional)
                            <Input v-model="form.brand_name" maxlength="300" />
                            <InputError :message="errorFor('brand_name')" />
                        </label>
                    </fieldset>

                    <fieldset class="grid gap-3 sm:grid-cols-2">
                        <legend class="mb-2 text-sm font-semibold">
                            Barcode and storage
                        </legend>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Barcode (optional)
                            <Input v-model="form.barcode" maxlength="100" />
                            <InputError :message="errorFor('barcode')" />
                        </label>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Storage
                            <select
                                v-model="form.storage_type"
                                class="h-11 rounded-md border bg-background px-3"
                            >
                                <option
                                    v-for="type in storageOptions(
                                        sku.storageType,
                                    )"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }}
                                </option>
                            </select>
                            <InputError :message="errorFor('storage_type')" />
                        </label>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Minimum temperature °C (optional)
                            <Input
                                v-model="form.minimum_temperature"
                                inputmode="decimal"
                            />
                            <InputError
                                :message="errorFor('minimum_temperature')"
                            />
                        </label>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Maximum temperature °C (optional)
                            <Input
                                v-model="form.maximum_temperature"
                                inputmode="decimal"
                            />
                            <InputError
                                :message="errorFor('maximum_temperature')"
                            />
                        </label>
                        <div
                            class="flex flex-wrap items-center gap-x-6 sm:col-span-2"
                        >
                            <label
                                class="flex min-h-11 items-center gap-2 text-sm"
                                ><input
                                    v-model="form.cold_chain_required"
                                    type="checkbox"
                                    class="size-5"
                                />Cold chain</label
                            >
                            <label
                                class="flex min-h-11 items-center gap-2 text-sm"
                                ><input
                                    v-model="form.do_not_freeze"
                                    type="checkbox"
                                    class="size-5"
                                />Do not freeze</label
                            >
                            <label
                                class="flex min-h-11 items-center gap-2 text-sm"
                                ><input
                                    v-model="form.protect_from_light"
                                    type="checkbox"
                                    class="size-5"
                                />Protect from light</label
                            >
                        </div>
                    </fieldset>

                    <fieldset class="grid gap-3 sm:grid-cols-2">
                        <legend class="mb-1 text-sm font-semibold">
                            Code and units
                        </legend>
                        <p
                            v-if="sku.unitsLocked"
                            class="text-sm text-muted-foreground sm:col-span-2"
                            data-testid="units-locked"
                        >
                            These cannot change because this SKU already has
                            batches, stock, a medicine link or dispensing
                            records.
                        </p>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >SKU code (required)
                            <Input
                                v-model="form.sku_code"
                                maxlength="64"
                                :disabled="sku.unitsLocked"
                                required
                            />
                            <InputError :message="errorFor('sku_code')" />
                        </label>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Stock unit (required)
                            <Input
                                v-model="form.stock_unit"
                                maxlength="100"
                                :disabled="sku.unitsLocked"
                                required
                            />
                            <InputError :message="errorFor('stock_unit')" />
                        </label>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Dispensing unit (required)
                            <Input
                                v-model="form.dispensing_unit"
                                maxlength="100"
                                :disabled="sku.unitsLocked"
                                required
                            />
                            <InputError
                                :message="errorFor('dispensing_unit')"
                            />
                        </label>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Unit conversion (required)
                            <Input
                                v-model="form.unit_conversion"
                                inputmode="decimal"
                                :disabled="sku.unitsLocked"
                                required
                            />
                            <InputError
                                :message="errorFor('unit_conversion')"
                            />
                        </label>
                        <div
                            class="flex flex-wrap items-center gap-x-6 sm:col-span-2"
                        >
                            <label
                                class="flex min-h-11 items-center gap-2 text-sm"
                                ><input
                                    v-model="form.batch_tracking_required"
                                    type="checkbox"
                                    class="size-5"
                                    :disabled="sku.unitsLocked"
                                />Batch tracking</label
                            >
                            <label
                                class="flex min-h-11 items-center gap-2 text-sm"
                                ><input
                                    v-model="form.expiry_tracking_required"
                                    type="checkbox"
                                    class="size-5"
                                    :disabled="sku.unitsLocked"
                                />Expiry tracking</label
                            >
                        </div>
                    </fieldset>

                    <fieldset class="grid gap-3 sm:grid-cols-2">
                        <legend class="mb-1 text-sm font-semibold">
                            Legacy pack fields
                        </legend>
                        <p class="text-sm text-muted-foreground sm:col-span-2">
                            Kept from before verified stock units. Supplier pack
                            sizes and prices belong on the Supplier price list.
                        </p>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Pack size
                            <Input
                                v-model="form.pack_size"
                                inputmode="decimal"
                                :disabled="sku.unitsLocked"
                            />
                            <InputError :message="errorFor('pack_size')" />
                        </label>
                        <label class="grid min-w-0 gap-1 text-sm"
                            >Purchase unit
                            <Input
                                v-model="form.purchase_unit"
                                maxlength="100"
                                :disabled="sku.unitsLocked"
                            />
                            <InputError :message="errorFor('purchase_unit')" />
                        </label>
                    </fieldset>

                    <fieldset class="flex flex-wrap items-center gap-x-6">
                        <legend class="mb-1 text-sm font-semibold">
                            Status
                        </legend>
                        <label class="flex min-h-11 items-center gap-2 text-sm"
                            ><input
                                v-model="form.sku_active"
                                type="checkbox"
                                class="size-5"
                            />SKU active</label
                        >
                        <label class="flex min-h-11 items-center gap-2 text-sm"
                            ><input
                                v-model="form.item_active"
                                type="checkbox"
                                class="size-5"
                            />Item active</label
                        >
                    </fieldset>
                    <InputError
                        :message="
                            errorFor('expected_lock_version') ||
                            errorFor('sku') ||
                            errorFor('item') ||
                            errorFor('inventory_item')
                        "
                    />

                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            class="h-11 lg:h-9"
                            :disabled="form.processing"
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
            v-if="skus.total > 0"
            :current-page="skus.page"
            :last-page="skus.lastPage"
            :total="skus.total"
            @change="(page) => visit(page)"
        />
    </main>
</template>
