<script setup lang="ts">
import { DateField } from '@/components/ui/date-field';
import { OperationalSelect } from '@/components/ui/select';

/**
 * Version 2 filter fields of the Panel Claims list. The same fields, labels and `draft` keys as the Classic filter form,
 * for the "More filters" panel (the tab's own fields) and, with `includeMain`, for the phone Filters sheet (Date range,
 * Panel and a custom range as well). Changing a select or a custom date asks the page to run the search, as Classic
 * does; the text fields wait for Apply or Enter.
 */
type Option = { value: string; label: string };
type Draft = {
    panel: string;
    claimNo: string;
    amountMin: string;
    amountMax: string;
    invoiceNo: string;
    name: string;
    doctor: string;
    visitType: string;
    visitFrom: string;
    visitTo: string;
    dueFrom: string;
    dueTo: string;
    diagnosis: string;
    treatment: string;
    from: string;
    to: string;
};

const props = defineProps<{
    draft: Draft;
    invoiceMode: boolean;
    clinicalFilters: boolean;
    range: string;
    rangeOptions: Option[];
    panelOptions: Option[];
    visitTypeOptions: Option[];
    includeMain?: boolean;
}>();

const emit = defineEmits<{
    go: [overrides: Record<string, string | number | null>];
    'update:range': [value: string];
}>();

// The page owns the draft filters (a reactive object); these fields edit it in place, as the Classic form does.
const d = props.draft;
const uid = Math.random().toString(36).slice(2, 7);
const field =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none selection:bg-primary/25 hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const label = 'grid min-w-0 gap-1 text-sm font-medium';
const reset = { claims_page: null, allocations_page: null };
</script>

<template>
    <template v-if="includeMain">
        <div :class="label">
            <span>Date range</span>
            <OperationalSelect
                :model-value="range"
                :options="rangeOptions"
                label="Date range"
                @update:model-value="
                    (value) => emit('update:range', String(value))
                "
            />
        </div>
        <template v-if="range === 'custom'">
            <label :class="label" :for="`claims-from-${uid}`"
                >From
                <DateField
                    :id="`claims-from-${uid}`"
                    v-model="d.from"
                    :echo="false"
                    @change="emit('go', { claims_page: null })"
                />
            </label>
            <label :class="label" :for="`claims-to-${uid}`"
                >To
                <DateField
                    :id="`claims-to-${uid}`"
                    v-model="d.to"
                    :echo="false"
                    @change="emit('go', { claims_page: null })"
                />
            </label>
        </template>
        <div :class="label">
            <span>Panel</span>
            <OperationalSelect
                :model-value="d.panel"
                :options="panelOptions"
                label="Panel"
                @update:model-value="
                    (value) => {
                        d.panel = String(value);
                        emit('go', reset);
                    }
                "
            />
        </div>
    </template>
    <template v-if="invoiceMode">
        <label :class="label"
            >Invoice number
            <input v-model="d.invoiceNo" :class="field" />
        </label>
        <label :class="label"
            >Doctor
            <input v-model="d.doctor" :class="field" />
        </label>
        <div :class="label">
            <span>Visit type</span>
            <OperationalSelect
                :model-value="d.visitType"
                :options="visitTypeOptions"
                label="Visit type"
                @update:model-value="
                    (value) => {
                        d.visitType = String(value);
                        emit('go', reset);
                    }
                "
            />
        </div>
        <label :class="label"
            >Visit date from
            <DateField
                v-model="d.visitFrom"
                aria-label="Visit date from"
                :echo="false"
            />
        </label>
        <label :class="label"
            >Visit date to
            <DateField
                v-model="d.visitTo"
                aria-label="Visit date to"
                :echo="false"
            />
        </label>
        <label :class="label"
            >Due date from
            <DateField
                v-model="d.dueFrom"
                aria-label="Due date from"
                :echo="false"
            />
        </label>
        <label :class="label"
            >Due date to
            <DateField
                v-model="d.dueTo"
                aria-label="Due date to"
                :echo="false"
            />
        </label>
        <template v-if="clinicalFilters">
            <label :class="label"
                >Diagnosis
                <input v-model="d.diagnosis" :class="field" />
            </label>
            <label :class="label"
                >Treatment
                <input v-model="d.treatment" :class="field" />
            </label>
        </template>
    </template>
    <template v-else>
        <div :class="label" role="group" aria-label="Amount (RM)">
            <span>Amount (RM)</span>
            <span class="grid grid-cols-2 gap-2">
                <input
                    v-model="d.amountMin"
                    inputmode="decimal"
                    placeholder="Min"
                    aria-label="Minimum amount"
                    :class="field"
                />
                <input
                    v-model="d.amountMax"
                    inputmode="decimal"
                    placeholder="Max"
                    aria-label="Maximum amount"
                    :class="field"
                />
            </span>
        </div>
        <label :class="label"
            >Claim number
            <input v-model="d.claimNo" inputmode="numeric" :class="field" />
        </label>
    </template>
</template>
