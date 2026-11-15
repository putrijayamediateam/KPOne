<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2SearchCombobox from '@/components/v2/V2SearchCombobox.vue';
import type { ComboOption } from '@/components/v2/V2SearchCombobox.vue';
import { inventoryPickerUrl } from '@/lib/inventory-picker';
import type { InventoryPickerRow } from '@/lib/inventory-picker';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { requestJson } from '@/lib/json-client';
import { formatDate } from '@/lib/presentation';

/**
 * Inventory V2 only: a supplier, location or SKU picker on `V2SearchCombobox`, over the same server search as the
 * Classic `InventorySearchSelect` (`GET /inventory/picker`, context `operations`, at most 25 matches). The chosen
 * value shows as text with a Clear button, never as a filled box (Daniel, page 2 plan). Batches (page 2b) are for one
 * SKU, earliest expiry first as the server sends them, shown at once as choice buttons (the list is short and the order
 * matters); a held batch is left out and counted.
 */
type Kind =
    'suppliers' | 'locations' | 'skus' | 'batches' | 'items' | 'medicines';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        kind: Kind;
        label: string;
        expectedBranchId: number;
        /** The current choice's words when the page already knows them (for example an existing draft). */
        selectedLabel?: string;
        error?: string;
        required?: boolean;
        /** Batches only: the SKU whose batches to list. */
        sku?: string;
        /** Batches only: the location whose on-hand quantity each batch shows ("5 units here"). */
        location?: string;
        /** Batches only: the stock unit for that quantity. */
        unit?: string;
        /**
         * The server search's scope, as Classic uses it: `operations` (orders, requests, reorder levels) or `references`
         * (Stock setup, page 2d). The server decides what each returns.
         */
        context?: 'operations' | 'references';
    }>(),
    {
        context: 'operations',
        selectedLabel: '',
        error: '',
        required: false,
        sku: '',
        location: '',
        unit: '',
    },
);
const emit = defineEmits<{
    'update:modelValue': [value: string];
    /** The chosen value's words, for a page that says it in a sentence, and a SKU's stock unit. */
    picked: [label: string, unit: string];
}>();

const query = ref('');
const rows = ref<InventoryPickerRow[]>([]);
const loading = ref(false);
const failed = ref(false);
const chosen = ref(props.selectedLabel);
let sequence = 0;
let timer: ReturnType<typeof setTimeout> | null = null;

const LOCATION_TYPES: Record<string, string> = {
    medical_stock: 'Medical stock',
    branch_store: 'Branch store',
    dispensary: 'Dispensary',
    dispensary_returns: 'Patient returns (not for dispensing)',
};

/** Item name first, then the code and unit; locations say their type and where they belong. */
const toOption = (row: InventoryPickerRow): ComboOption => {
    const id = String(row.publicId ?? row.id);

    if (props.kind === 'skus') {
        // `operations` rows carry `name` and `code`; `references` rows carry `itemName` and `skuCode` (page 2d).
        return row.skuCode
            ? {
                  id,
                  label: String(row.itemName ?? row.skuCode),
                  detail: [row.itemName ? row.skuCode : null, row.stockUnit]
                      .filter(Boolean)
                      .join(' · '),
              }
            : {
                  id,
                  label: String(row.name ?? row.code),
                  detail: [row.code, row.stockUnit].filter(Boolean).join(' · '),
              };
    }

    if (props.kind === 'items') {
        return {
            id,
            label: String(row.genericName),
            detail: String(row.code ?? ''),
        };
    }

    if (props.kind === 'medicines') {
        return {
            id,
            label: String(row.displayName),
            detail: String(row.code ?? ''),
        };
    }

    if (props.kind === 'locations') {
        const type = LOCATION_TYPES[String(row.type)] ?? String(row.type ?? '');

        return {
            id,
            label: String(row.name),
            detail:
                row.branchId === undefined
                    ? type
                    : `${type} · ${row.branchId == null ? 'Organisation store' : 'This branch'}`,
        };
    }

    if (props.kind === 'batches') {
        return {
            id,
            label: String(row.batchNumber),
            detail: [
                row.expiryDate
                    ? `Expires ${formatDate(String(row.expiryDate))}`
                    : '',
                row.onHand != null
                    ? `${quantityWithUnit(String(row.onHand), props.unit)} here`
                    : '',
            ]
                .filter(Boolean)
                .join(' · '),
        };
    }

    return {
        id,
        label: String(row.name),
        detail: String(row.code ?? ''),
    };
};
/** Held batches cannot be dispatched (the server refuses them), so they are not offered; this says how many. */
const heldCount = computed(
    () => rows.value.filter((row) => row.held === true).length,
);

const options = computed(() =>
    rows.value
        .filter(
            (row) =>
                !(props.kind === 'suppliers' && row.isActive === false) &&
                row.held !== true,
        )
        .map(toOption),
);

const search = async () => {
    const request = ++sequence;
    loading.value = true;
    failed.value = false;

    try {
        const response = await requestJson<{ data: InventoryPickerRow[] }>(
            inventoryPickerUrl({
                kind: props.kind,
                context: props.context,
                expectedBranchId: props.expectedBranchId,
                query: query.value,
                sku: props.kind === 'batches' ? props.sku : undefined,
                location:
                    props.kind === 'batches' && props.location
                        ? props.location
                        : undefined,
            }),
        );

        if (request === sequence) {
            rows.value = response.data;
        }
    } catch {
        if (request === sequence) {
            rows.value = [];
            failed.value = true;
        }
    } finally {
        if (request === sequence) {
            loading.value = false;
        }
    }
};

watch(query, (value) => {
    if (timer) {
        clearTimeout(timer);
    }

    if (value.trim() === '' && props.kind !== 'batches') {
        rows.value = [];

        return;
    }

    timer = setTimeout(() => void search(), 250);
});

// Batches list as soon as the SKU is known, without typing (as the Classic batch select does).
watch(
    () => props.sku,
    (sku) => {
        if (props.kind === 'batches') {
            rows.value = [];

            if (sku) {
                void search();
            }
        }
    },
    { immediate: true },
);

const pick = (option: ComboOption) => {
    chosen.value = option.detail
        ? `${option.label} · ${option.detail}`
        : option.label;
    query.value = '';
    emit('update:modelValue', option.id);
    const row = rows.value.find(
        (candidate) => String(candidate.publicId ?? candidate.id) === option.id,
    );
    emit('picked', option.label, String(row?.stockUnit ?? ''));
};

const clear = () => {
    chosen.value = '';
    emit('update:modelValue', '');
};

watch(
    () => props.modelValue,
    (value) => {
        if (value === '') {
            chosen.value = '';
        }
    },
);
watch(
    () => props.selectedLabel,
    (value) => {
        if (value) {
            chosen.value = value;
        }
    },
);

const errorId = `inv-picker-${Math.random().toString(36).slice(2, 8)}-error`;
</script>

<template>
    <div class="grid min-w-0 gap-1.5">
        <div
            v-if="modelValue && chosen && kind !== 'batches'"
            class="grid gap-1.5"
            role="group"
            :aria-label="label"
        >
            <span class="text-sm font-medium"
                >{{ label
                }}<span
                    v-if="required"
                    class="text-destructive"
                    aria-hidden="true"
                >
                    *</span
                ></span
            >
            <div
                class="flex min-h-11 min-w-0 items-center justify-between gap-2 rounded-[10px] border bg-muted/40 px-3 lg:min-h-9"
            >
                <span class="min-w-0 text-[15px] break-words" aria-live="polite"
                    >{{ chosen }}<span class="sr-only"> chosen</span></span
                >
                <button
                    type="button"
                    class="inline-flex min-h-11 shrink-0 items-center rounded-[10px] px-2 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                    :aria-label="`Clear ${label}`"
                    @click="clear"
                >
                    Clear
                </button>
            </div>
        </div>
        <template v-else-if="kind === 'batches'">
            <V2OptionGroup
                v-if="options.length"
                :model-value="modelValue"
                :label="label"
                :required="required"
                :options="
                    options.map((option) => ({
                        value: option.id,
                        label: option.detail
                            ? `${option.label} · ${option.detail}`
                            : option.label,
                    }))
                "
                @update:model-value="
                    (value) => {
                        const option = options.find(
                            (entry) => entry.id === value,
                        );
                        if (option) pick(option);
                    }
                "
            />
            <p v-else class="text-sm" role="status">
                <span class="font-medium">{{ label }}</span
                ><br />
                <span class="text-muted-foreground">{{
                    loading
                        ? 'Loading batches…'
                        : failed
                          ? 'Batches could not be loaded. Try again.'
                          : 'No batch of this item can be sent from here.'
                }}</span>
            </p>
            <p class="text-xs text-muted-foreground">
                Earliest expiry first.
                <template v-if="heldCount > 0">
                    {{ heldCount }} held
                    {{ heldCount === 1 ? 'batch is' : 'batches are' }} not
                    shown.</template
                >
            </p>
        </template>
        <template v-else>
            <!-- The combobox names itself for screen readers; this is the visible label (Daniel: no placeholder-only field). -->
            <span class="text-sm font-medium" aria-hidden="true"
                >{{ label
                }}<span v-if="required" class="text-destructive"> *</span></span
            >
            <V2SearchCombobox
                v-model="query"
                :label="required ? `${label} (required)` : label"
                :options="options"
                :loading="loading"
                :empty-text="
                    failed
                        ? 'Choices could not be loaded. Try again.'
                        : 'No match.'
                "
                @select="pick"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    kind === 'skus'
                        ? 'Search by name or SKU code. Up to 25 matches.'
                        : 'Up to 25 matches. Type to narrow.'
                }}
            </p>
        </template>
        <p
            v-if="error"
            :id="errorId"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ error }}
        </p>
    </div>
</template>
