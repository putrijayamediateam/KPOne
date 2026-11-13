<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import V2SearchCombobox from '@/components/v2/V2SearchCombobox.vue';
import type { ComboOption } from '@/components/v2/V2SearchCombobox.vue';
import { inventoryPickerUrl } from '@/lib/inventory-picker';
import type { InventoryPickerRow } from '@/lib/inventory-picker';
import { requestJson } from '@/lib/json-client';
import { formatDate } from '@/lib/presentation';

/**
 * Inventory V2 only: a supplier, location or SKU picker on `V2SearchCombobox`, over the same server search as the
 * Classic `InventorySearchSelect` (`GET /inventory/picker`, context `operations`, at most 25 matches). The chosen
 * value shows as text with a Clear button, never as a filled box (Daniel, page 2 plan). Batches (page 2b) are for one
 * SKU, earliest expiry first as the server sends them, and list without typing; a held batch is left out and counted.
 */
type Kind = 'suppliers' | 'locations' | 'skus' | 'batches';

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
    }>(),
    { selectedLabel: '', error: '', required: false, sku: '' },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

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
        return {
            id,
            label: String(row.name ?? row.code),
            detail: [row.code, row.stockUnit].filter(Boolean).join(' · '),
        };
    }

    if (props.kind === 'locations') {
        const type = LOCATION_TYPES[String(row.type)] ?? String(row.type ?? '');

        return {
            id,
            label: String(row.name),
            detail: `${type} · ${row.branchId == null ? 'Organisation store' : 'This branch'}`,
        };
    }

    if (props.kind === 'batches') {
        return {
            id,
            label: String(row.batchNumber),
            detail: row.expiryDate
                ? `Expires ${formatDate(String(row.expiryDate))}`
                : '',
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
                context: 'operations',
                expectedBranchId: props.expectedBranchId,
                query: query.value,
                sku: props.kind === 'batches' ? props.sku : undefined,
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
            v-if="modelValue && chosen"
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
                    kind === 'batches'
                        ? 'Earliest expiry first. Up to 25 batches. Type to narrow.'
                        : 'Up to 25 matches. Type to narrow.'
                }}
                <template v-if="kind === 'batches' && heldCount > 0">
                    {{ heldCount }} held
                    {{ heldCount === 1 ? 'batch is' : 'batches are' }} not
                    shown.</template
                >
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
