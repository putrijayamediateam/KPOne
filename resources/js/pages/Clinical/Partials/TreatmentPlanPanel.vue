<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    LoaderCircle,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { OperationalSelect } from '@/components/ui/select';
import { treatmentSaveButtonVariant } from '@/lib/r1c2-presentation';
import type {
    ClinicalAllergySafety,
    TreatmentPlanPage,
} from '@/types/clinical';

const props = defineProps<{
    visitNumber: string;
    branchId: number;
    plan: TreatmentPlanPage;
    visitVersion: number;
    queueVersion: number;
    encounterVersion: number;
    allergies: ClinicalAllergySafety | null;
}>();
const sendOpen = ref(false);
const sendForm = useForm({
    expected_branch_id: props.branchId,
    lock_version: props.plan.lockVersion,
    visit_lock_version: props.visitVersion,
    queue_lock_version: props.queueVersion,
    encounter_lock_version: props.encounterVersion,
    service_deliveries: props.plan.services.map((service) => ({
        order_public_id: service.publicId,
        disposition: '',
        quantity_performed: '',
    })),
});
const sendToDispensary = () => {
    sendForm.lock_version = props.plan.lockVersion;
    sendForm.visit_lock_version = props.visitVersion;
    sendForm.queue_lock_version = props.queueVersion;
    sendForm.encounter_lock_version = props.encounterVersion;
    sendForm.service_deliveries = sendForm.service_deliveries.map((row) => ({
        ...row,
        quantity_performed:
            row.disposition === 'not_performed' ? '0' : row.quantity_performed,
    }));
    sendForm.post(
        `/visits/${props.visitNumber}/encounter/complete-consultation`,
        { preserveScroll: true, onSuccess: () => (sendOpen.value = false) },
    );
};
watch(
    () => props.plan.services,
    (services) => {
        sendForm.service_deliveries = services.map((service) => ({
            order_public_id: service.publicId,
            disposition: '',
            quantity_performed: '',
        }));
    },
);
const reopenForm = useForm({
    expected_branch_id: props.branchId,
    visit_lock_version: props.visitVersion,
    checkout_lock_version: props.plan.checkout?.lockVersion ?? 0,
});
const reopenCheckout = () => {
    reopenForm.visit_lock_version = props.visitVersion;
    reopenForm.checkout_lock_version = props.plan.checkout?.lockVersion ?? 0;
    reopenForm.post(`/visits/${props.visitNumber}/encounter/reopen-checkout`, {
        preserveScroll: true,
    });
};

type MedicineSearch = {
    publicId: string;
    code: string;
    displayName: string;
    strength: string | null;
    dosageForm: string | null;
    unit: string;
};
type ServiceSearch = {
    publicId: string;
    code: string;
    displayName: string;
    unit: string;
};

const customChoice = '__custom';
const dosageUnits = ['tablet', 'capsule', 'mL', 'drop', 'puff', 'sachet'];
const frequencyPresets = [
    'Once daily',
    'Twice daily',
    'Three times daily',
    'Four times daily',
];
const durationUnits = ['day', 'week', 'month'];
const routePresets = ['Oral', 'Topical', 'Inhaled'];
const selectOptions = (values: string[], emptyLabel: string, custom = true) => [
    { value: '', label: emptyLabel },
    ...values.map((value) => ({ value, label: value })),
    ...(custom ? [{ value: customChoice, label: 'Custom / Other' }] : []),
];
const dosageUnitOptions = selectOptions(dosageUnits, 'Unit');
const frequencyOptions = selectOptions(frequencyPresets, 'Select frequency');
const durationUnitOptions = [
    { value: '', label: 'Unit' },
    ...durationUnits.map((value) => ({ value, label: `${value}(s)` })),
    { value: customChoice, label: 'Custom / Other' },
];
const routeOptions = selectOptions(routePresets, 'Select route');
const dispositionOptions = [
    { value: '', label: 'Confirm disposition' },
    { value: 'performed', label: 'Performed' },
    { value: 'not_performed', label: 'Not performed' },
];

type AmountUnitComposer = {
    mode: 'structured' | 'custom';
    amount: string | number | null | undefined;
    unit: string;
    custom: string;
};

type MedicineFormRow = {
    public_id: string | null;
    catalogue_public_id: string | null;
    quantity_ordered: string;
    dosage: string;
    frequency: string;
    duration: string;
    route: string;
    administration_instruction: string;
    indication: string;
    precaution: string;
    display_name: string;
    code: string;
    unit: string;
    strength: string | null;
    dosage_composer: AmountUnitComposer;
    frequency_choice: string;
    frequency_custom: string;
    duration_composer: AmountUnitComposer;
    route_choice: string;
    route_custom: string;
};

type ServiceFormRow = {
    public_id: string | null;
    catalogue_public_id: string | null;
    quantity_ordered: string;
    clinical_instruction: string;
    display_name: string;
    code: string;
    unit: string;
};

const canonicalUnit = (value: string, units: string[]) =>
    units.find((unit) => unit.toLowerCase() === value.toLowerCase()) ?? null;

const parseAmountUnit = (
    value: string,
    units: string[],
    plural = false,
): AmountUnitComposer => {
    const trimmed = value.trim();
    const match = trimmed.match(/^(\d+(?:\.\d+)?)\s+(.+)$/);

    if (match) {
        const rawUnit = plural ? match[2].replace(/s$/i, '') : match[2];
        const unit = canonicalUnit(rawUnit, units);

        if (unit) {
            return {
                mode: 'structured',
                amount: match[1],
                unit,
                custom: '',
            };
        }
    }

    return trimmed
        ? { mode: 'custom', amount: '', unit: '', custom: trimmed }
        : { mode: 'structured', amount: '', unit: '', custom: '' };
};

// Vue number inputs emit numbers; clearing them may emit an empty/null value.
// Preserve text rather than parsing a numeric prefix or substituting a default.
const normalizeAmount = (
    value: AmountUnitComposer['amount'],
): string | null => {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? String(value) : null;
    }

    return value.trim();
};
const composeAmountUnit = (composer: AmountUnitComposer, plural = false) => {
    if (composer.mode === 'custom') {
        return composer.custom.trim();
    }

    const amount = normalizeAmount(composer.amount);

    if (!amount || !composer.unit) {
        return '';
    }

    const suffix =
        plural && Number.parseFloat(amount) !== 1
            ? `${composer.unit}s`
            : composer.unit;

    return `${amount} ${suffix}`;
};

const selectValue = (value: string, presets: string[]) => {
    if (!value) {
        return { choice: '', custom: '' };
    }

    return presets.includes(value)
        ? { choice: value, custom: '' }
        : { choice: customChoice, custom: value };
};

const medicineRow = (
    item: TreatmentPlanPage['medicines'][number],
): MedicineFormRow => {
    const frequency = selectValue(item.frequency, frequencyPresets);
    const route = selectValue(item.route ?? '', routePresets);

    return {
        public_id: item.publicId,
        catalogue_public_id: null,
        quantity_ordered: item.quantityOrdered,
        dosage: item.dosage,
        frequency: item.frequency,
        duration: item.duration ?? '',
        route: item.route ?? '',
        administration_instruction: item.administrationInstruction ?? '',
        indication: item.indication ?? '',
        precaution: item.precaution ?? '',
        display_name: item.displayName,
        code: item.code,
        unit: item.unit,
        strength: item.strength,
        dosage_composer: parseAmountUnit(item.dosage, dosageUnits),
        frequency_choice: frequency.choice,
        frequency_custom: frequency.custom,
        duration_composer: parseAmountUnit(
            item.duration ?? '',
            durationUnits,
            true,
        ),
        route_choice: route.choice,
        route_custom: route.custom,
    };
};

const serviceRow = (
    item: TreatmentPlanPage['services'][number],
): ServiceFormRow => ({
    public_id: item.publicId,
    catalogue_public_id: null,
    quantity_ordered: item.quantityOrdered,
    clinical_instruction: item.clinicalInstruction ?? '',
    display_name: item.displayName,
    code: item.code,
    unit: item.unit,
});

const form = useForm({
    expected_branch_id: props.branchId,
    lock_version: props.plan.lockVersion,
    medicines: props.plan.medicines.map(medicineRow),
    services: props.plan.services.map(serviceRow),
});

const medicineQuery = ref('');
const serviceQuery = ref('');
const medicineResults = ref<MedicineSearch[]>([]);
const serviceResults = ref<ServiceSearch[]>([]);
const searchingMedicine = ref(false);
const searchingService = ref(false);
const searchError = ref('');
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const allergyReady = computed(
    () =>
        props.allergies !== null &&
        props.allergies.status !== 'unknown' &&
        props.allergies.encounterReview?.isCurrent === true,
);
const formError = computed(() => {
    const errors = form.errors as Record<string, string>;

    return (
        searchError.value ||
        errors.lock_version ||
        errors.allergy_review ||
        errors.medicines ||
        errors.services ||
        Object.values(errors)[0] ||
        ''
    );
});

const search = async (kind: 'medicines' | 'services') => {
    const query =
        kind === 'medicines' ? medicineQuery.value : serviceQuery.value;

    if (query.trim().length < 2) {
        return;
    }

    if (kind === 'medicines') {
        searchingMedicine.value = true;
    } else {
        searchingService.value = true;
    }

    searchError.value = '';

    try {
        const response = await fetch(
            `/visits/${encodeURIComponent(props.visitNumber)}/encounter/treatment-plan/catalogue/${kind}/search`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    expected_branch_id: props.branchId,
                    query,
                }),
            },
        );
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message);
        }

        if (kind === 'medicines') {
            medicineResults.value = payload.data;
        } else {
            serviceResults.value = payload.data;
        }
    } catch {
        searchError.value = 'Catalogue search could not be completed.';
    } finally {
        searchingMedicine.value = false;
        searchingService.value = false;
    }
};

const addMedicine = (item: MedicineSearch) => {
    if (
        form.medicines.some((row) => row.catalogue_public_id === item.publicId)
    ) {
        return;
    }

    const suggestedUnit = canonicalUnit(item.unit, dosageUnits) ?? '';
    form.medicines.push({
        public_id: null,
        catalogue_public_id: item.publicId,
        quantity_ordered: '1',
        dosage: '',
        frequency: '',
        duration: '',
        route: '',
        administration_instruction: '',
        indication: '',
        precaution: '',
        display_name: item.displayName,
        code: item.code,
        unit: item.unit,
        strength: item.strength,
        dosage_composer: {
            mode: suggestedUnit ? 'structured' : 'custom',
            amount: '',
            unit: suggestedUnit,
            custom: '',
        },
        frequency_choice: '',
        frequency_custom: '',
        duration_composer: {
            mode: 'structured',
            amount: '',
            unit: '',
            custom: '',
        },
        route_choice: '',
        route_custom: '',
    });
    medicineResults.value = [];
    medicineQuery.value = '';
};
const syncMedicine = (item: MedicineFormRow) => {
    item.dosage = composeAmountUnit(item.dosage_composer);
    item.frequency =
        item.frequency_choice === customChoice
            ? item.frequency_custom.trim()
            : item.frequency_choice;
    item.duration = composeAmountUnit(item.duration_composer, true);
    item.route =
        item.route_choice === customChoice
            ? item.route_custom.trim()
            : item.route_choice;
};
const selectDosageUnit = (item: MedicineFormRow) => {
    if (item.dosage_composer.unit === customChoice) {
        item.dosage_composer.mode = 'custom';
        item.dosage_composer.unit = '';
        item.dosage_composer.custom = item.dosage;
    }

    syncMedicine(item);
};
const selectDurationUnit = (item: MedicineFormRow) => {
    if (item.duration_composer.unit === customChoice) {
        item.duration_composer.mode = 'custom';
        item.duration_composer.unit = '';
        item.duration_composer.custom = item.duration;
    }

    syncMedicine(item);
};
const useStructuredDosage = (item: MedicineFormRow) => {
    item.dosage_composer = {
        mode: 'structured',
        amount: '',
        unit: canonicalUnit(item.unit, dosageUnits) ?? '',
        custom: '',
    };
    syncMedicine(item);
};
const useStructuredDuration = (item: MedicineFormRow) => {
    item.duration_composer = {
        mode: 'structured',
        amount: '',
        unit: '',
        custom: '',
    };
    syncMedicine(item);
};
const moveRow = <T,>(rows: T[], index: number, direction: -1 | 1) => {
    const target = index + direction;

    if (target < 0 || target >= rows.length) {
        return;
    }

    const [row] = rows.splice(index, 1);
    rows.splice(target, 0, row);
};
const addService = (item: ServiceSearch) => {
    if (
        form.services.some((row) => row.catalogue_public_id === item.publicId)
    ) {
        return;
    }

    form.services.push({
        public_id: null,
        catalogue_public_id: item.publicId,
        quantity_ordered: '1',
        clinical_instruction: '',
        display_name: item.displayName,
        code: item.code,
        unit: item.unit,
    });
    serviceResults.value = [];
    serviceQuery.value = '';
};
const save = () => {
    form.clearErrors();

    for (const row of form.medicines) {
        for (const composer of [row.dosage_composer, row.duration_composer]) {
            if (
                composer.mode === 'structured' &&
                normalizeAmount(composer.amount) === null
            ) {
                form.setError(
                    'medicines',
                    'Enter a finite dosage or duration amount, or use Custom text.',
                );

                return;
            }
        }
    }

    form.medicines.forEach(syncMedicine);

    form.transform((data) => ({
        ...data,
        medicines: data.medicines.map((row) => ({
            public_id: row.public_id,
            catalogue_public_id: row.catalogue_public_id,
            quantity_ordered: row.quantity_ordered,
            dosage: row.dosage,
            frequency: row.frequency,
            duration: row.duration,
            route: row.route,
            administration_instruction: row.administration_instruction,
            indication: row.indication,
            precaution: row.precaution,
        })),
        services: data.services.map((row) => ({
            public_id: row.public_id,
            catalogue_public_id: row.catalogue_public_id,
            quantity_ordered: row.quantity_ordered,
            clinical_instruction: row.clinical_instruction,
        })),
    })).put(
        `/visits/${encodeURIComponent(props.visitNumber)}/encounter/treatment-plan`,
        {
            preserveScroll: true,
            onSuccess: () => {
                form.lock_version = props.plan.lockVersion;
                form.medicines = props.plan.medicines.map(medicineRow);
                form.services = props.plan.services.map(serviceRow);
                form.defaults();
            },
        },
    );
};
</script>

<template>
    <section class="space-y-3 rounded-lg border bg-background p-3.5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">Treatment Plan</h2>
                <p class="text-xs text-muted-foreground">
                    Clinical orders only. Dispensary fulfilment follows Complete
                    Consultation. Billing is outside this workspace.
                </p>
            </div>
            <span class="rounded border px-2 py-1 text-xs">{{
                plan.status === 'ready_for_dispensing'
                    ? 'Sent to Dispensary'
                    : 'In progress'
            }}</span>
        </div>

        <div
            v-if="!allergyReady"
            role="status"
            class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950"
        >
            New or changed medicine instructions require a current Allergy
            Profile review for this consultation. Reordering or withdrawing an
            existing medicine remains available.
            <a href="#clinical-safety" class="font-medium underline"
                >Review allergies</a
            >
        </div>

        <div class="space-y-2.5">
            <div class="flex flex-wrap items-end gap-2">
                <label class="min-w-64 flex-1 text-sm">
                    Find medicine
                    <input
                        v-model="medicineQuery"
                        class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                        autocomplete="off"
                        @keyup.enter.prevent="search('medicines')"
                    />
                </label>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="searchingMedicine || !allergyReady"
                    @click="search('medicines')"
                >
                    <LoaderCircle
                        v-if="searchingMedicine"
                        class="size-4 animate-spin"
                    /><Search v-else class="size-4" /> Search
                </Button>
            </div>
            <div
                v-if="medicineResults.length"
                class="divide-y rounded-md border"
            >
                <button
                    v-for="item in medicineResults"
                    :key="item.publicId"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-muted"
                    @click="addMedicine(item)"
                >
                    <span
                        ><strong>{{ item.displayName }}</strong
                        ><span class="ml-2 text-xs text-muted-foreground"
                            >{{ item.code }} ·
                            {{ item.strength ?? item.unit }}</span
                        ></span
                    ><Plus class="size-4" />
                </button>
            </div>
            <div
                v-for="(item, index) in form.medicines"
                :key="item.public_id ?? item.catalogue_public_id ?? index"
                class="space-y-2 rounded-md border border-border/80 bg-background p-2.5"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0 text-sm font-medium">
                        <span class="truncate">{{ item.display_name }}</span>
                        <span class="ml-1 font-normal text-muted-foreground"
                            >· {{ item.code
                            }}<template v-if="item.strength">
                                · {{ item.strength }}</template
                            ></span
                        >
                    </div>
                    <div class="flex shrink-0 items-center gap-0.5">
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :disabled="index === 0"
                            :aria-label="`Move ${item.display_name} up`"
                            @click="moveRow(form.medicines, index, -1)"
                            ><ArrowUp class="size-3.5"
                        /></Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :disabled="index === form.medicines.length - 1"
                            :aria-label="`Move ${item.display_name} down`"
                            @click="moveRow(form.medicines, index, 1)"
                            ><ArrowDown class="size-3.5"
                        /></Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-8 text-muted-foreground hover:text-destructive"
                            :aria-label="`Withdraw ${item.display_name}`"
                            @click="form.medicines.splice(index, 1)"
                            ><Trash2 class="size-3.5"
                        /></Button>
                    </div>
                </div>
                <div
                    class="grid gap-2 sm:grid-cols-2 lg:grid-cols-[120px_minmax(210px,1.2fr)_minmax(190px,1fr)_minmax(190px,1fr)]"
                >
                    <label class="text-xs"
                        >Quantity ({{ item.unit }})<input
                            v-model="item.quantity_ordered"
                            type="number"
                            min="0.001"
                            step="0.001"
                            class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                    /></label>
                    <fieldset class="text-xs">
                        <legend>Dosage</legend>
                        <div
                            v-if="item.dosage_composer.mode === 'structured'"
                            class="mt-1 grid grid-cols-[minmax(70px,1fr)_minmax(105px,1.3fr)] gap-1"
                        >
                            <input
                                v-model="item.dosage_composer.amount"
                                type="number"
                                step="any"
                                inputmode="decimal"
                                aria-label="Dosage amount"
                                class="h-9 min-w-0 rounded-md border border-input bg-card px-2 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                @input="syncMedicine(item)"
                            />
                            <OperationalSelect
                                v-model="item.dosage_composer.unit"
                                label="Dosage unit"
                                :options="dosageUnitOptions"
                                @update:model-value="selectDosageUnit(item)"
                            />
                        </div>
                        <div v-else class="mt-1 flex gap-1">
                            <input
                                v-model="item.dosage_composer.custom"
                                maxlength="255"
                                aria-label="Custom dosage"
                                class="h-9 min-w-0 flex-1 rounded-md border border-input bg-card px-2 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                @input="syncMedicine(item)"
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                class="h-9 px-2"
                                @click="useStructuredDosage(item)"
                                >Units</Button
                            >
                        </div>
                    </fieldset>
                    <label class="text-xs"
                        >Frequency<OperationalSelect
                            v-model="item.frequency_choice"
                            class="mt-1"
                            label="Frequency"
                            :options="frequencyOptions"
                            @update:model-value="syncMedicine(item)" />
                        <input
                            v-if="item.frequency_choice === customChoice"
                            v-model="item.frequency_custom"
                            maxlength="255"
                            aria-label="Custom frequency"
                            class="mt-1 h-9 w-full rounded-md border border-input bg-card px-2 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                            @input="syncMedicine(item)"
                    /></label>
                    <fieldset class="text-xs">
                        <legend>
                            Duration
                            <span class="text-muted-foreground"
                                >(optional)</span
                            >
                        </legend>
                        <div
                            v-if="item.duration_composer.mode === 'structured'"
                            class="mt-1 grid grid-cols-[minmax(70px,1fr)_minmax(105px,1.3fr)] gap-1"
                        >
                            <input
                                v-model="item.duration_composer.amount"
                                type="number"
                                step="any"
                                inputmode="decimal"
                                aria-label="Duration amount"
                                class="h-9 min-w-0 rounded-md border border-input bg-card px-2 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                @input="syncMedicine(item)"
                            />
                            <OperationalSelect
                                v-model="item.duration_composer.unit"
                                label="Duration unit"
                                :options="durationUnitOptions"
                                @update:model-value="selectDurationUnit(item)"
                            />
                        </div>
                        <div v-else class="mt-1 flex gap-1">
                            <input
                                v-model="item.duration_composer.custom"
                                maxlength="255"
                                aria-label="Custom duration"
                                class="h-9 min-w-0 flex-1 rounded-md border border-input bg-card px-2 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                @input="syncMedicine(item)"
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                class="h-9 px-2"
                                @click="useStructuredDuration(item)"
                                >Units</Button
                            >
                        </div>
                    </fieldset>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="text-xs"
                        >Route
                        <span class="text-muted-foreground">(optional)</span
                        ><OperationalSelect
                            v-model="item.route_choice"
                            class="mt-1"
                            label="Route"
                            :options="routeOptions"
                            @update:model-value="syncMedicine(item)" />
                        <input
                            v-if="item.route_choice === customChoice"
                            v-model="item.route_custom"
                            maxlength="255"
                            aria-label="Custom route"
                            class="mt-1 h-9 w-full rounded-md border border-input bg-card px-2 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                            @input="syncMedicine(item)"
                    /></label>
                    <label class="text-xs"
                        >Instruction
                        <span class="text-muted-foreground">(optional)</span
                        ><input
                            v-model="item.administration_instruction"
                            maxlength="2000"
                            placeholder="e.g. After meals"
                            class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                    /></label>
                </div>
                <details class="group text-xs text-muted-foreground">
                    <summary
                        class="flex w-fit cursor-pointer list-none items-center gap-1 rounded px-1 py-0.5 font-medium text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronDown
                            class="size-3.5 transition-transform group-open:rotate-180"
                        />
                        More details
                        <span
                            v-if="item.indication || item.precaution"
                            class="text-muted-foreground"
                            >· Added</span
                        >
                    </summary>
                    <div class="mt-2 grid gap-2 md:grid-cols-2">
                        <label
                            >Indication<textarea
                                v-model="item.indication"
                                maxlength="2000"
                                rows="2"
                                class="mt-1 w-full rounded-md border border-input bg-card p-2 text-sm text-foreground outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                            /></label
                        ><label
                            >Precaution / additional instruction<textarea
                                v-model="item.precaution"
                                maxlength="2000"
                                rows="2"
                                class="mt-1 w-full rounded-md border border-input bg-card p-2 text-sm text-foreground outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                            />
                        </label>
                    </div>
                </details>
            </div>
            <p
                v-if="!form.medicines.length"
                class="text-sm text-muted-foreground"
            >
                No medicine ordered.
            </p>
        </div>

        <div class="space-y-2.5 border-t pt-3">
            <div class="flex flex-wrap items-end gap-2">
                <label class="min-w-64 flex-1 text-sm"
                    >Find service / procedure<input
                        v-model="serviceQuery"
                        class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                        autocomplete="off"
                        @keyup.enter.prevent="search('services')"
                /></label>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="searchingService"
                    @click="search('services')"
                    ><LoaderCircle
                        v-if="searchingService"
                        class="size-4 animate-spin"
                    /><Search v-else class="size-4" /> Search</Button
                >
            </div>
            <div
                v-if="serviceResults.length"
                class="divide-y rounded-md border"
            >
                <button
                    v-for="item in serviceResults"
                    :key="item.publicId"
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted"
                    @click="addService(item)"
                >
                    <span
                        ><strong>{{ item.displayName }}</strong> ·
                        {{ item.code }}</span
                    ><Plus class="size-4" />
                </button>
            </div>
            <div
                v-for="(item, index) in form.services"
                :key="item.public_id ?? item.catalogue_public_id ?? index"
                class="grid gap-2 rounded-md border border-border/80 p-2.5 sm:grid-cols-[minmax(160px,1fr)_120px_minmax(200px,1.5fr)_auto] sm:items-end"
            >
                <div class="text-sm font-medium">
                    {{ item.display_name }}
                    <div class="text-xs font-normal text-muted-foreground">
                        {{ item.code }}
                    </div>
                </div>
                <label class="text-xs"
                    >Quantity ({{ item.unit }})<input
                        v-model="item.quantity_ordered"
                        type="number"
                        min="0.001"
                        step="0.001"
                        class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                /></label>
                <label class="text-xs"
                    >Clinical instruction<input
                        v-model="item.clinical_instruction"
                        maxlength="2000"
                        class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                /></label>
                <div class="flex items-center justify-end gap-0.5">
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        class="size-8"
                        :disabled="index === 0"
                        :aria-label="`Move ${item.display_name} up`"
                        @click="moveRow(form.services, index, -1)"
                        ><ArrowUp class="size-3.5"
                    /></Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        class="size-8"
                        :disabled="index === form.services.length - 1"
                        :aria-label="`Move ${item.display_name} down`"
                        @click="moveRow(form.services, index, 1)"
                        ><ArrowDown class="size-3.5"
                    /></Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        class="size-8 text-muted-foreground hover:text-destructive"
                        :aria-label="`Withdraw ${item.display_name}`"
                        @click="form.services.splice(index, 1)"
                        ><Trash2 class="size-3.5"
                    /></Button>
                </div>
            </div>
            <p
                v-if="!form.services.length"
                class="text-sm text-muted-foreground"
            >
                No service or procedure ordered.
            </p>
        </div>

        <InputError :message="formError" />
        <p
            v-if="plan.checkout?.route === 'billing'"
            class="text-sm text-muted-foreground"
        >
            Consultation closed — awaiting Billing.
        </p>
        <InputError :message="Object.values(reopenForm.errors)[0]" />
        <div class="flex justify-end gap-2">
            <Button
                v-if="plan.checkout?.canReopen"
                variant="outline"
                :disabled="reopenForm.processing"
                @click="reopenCheckout"
                >Reopen consultation</Button
            >
            <Button
                v-if="plan.canCompleteConsultation"
                type="button"
                @click="sendOpen = true"
                >Complete Consultation</Button
            >
            <Button
                type="button"
                :variant="
                    treatmentSaveButtonVariant(plan.canCompleteConsultation)
                "
                :disabled="form.processing || !plan.canSave"
                @click="save"
                ><LoaderCircle
                    v-if="form.processing"
                    class="size-4 animate-spin"
                />{{
                    form.processing ? 'Saving…' : 'Save Treatment Plan'
                }}</Button
            >
        </div>
        <Dialog v-model:open="sendOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Complete consultation?</DialogTitle>
                    <DialogDescription v-if="plan.medicines.length > 0"
                        >This will send the current Treatment Plan to Dispensary
                        for medicine preparation. You can continue the
                        consultation only if the case is returned from
                        Dispensary.</DialogDescription
                    >
                    <DialogDescription v-else
                        >This will complete the current consultation and send
                        the Patient to Billing. No physical Dispensary handoff
                        or stock movement is required.</DialogDescription
                    >
                </DialogHeader>
                <div
                    v-for="(service, index) in plan.services"
                    :key="service.publicId"
                    class="space-y-2 border-t py-2 text-sm"
                >
                    <p>
                        {{ service.displayName }} · Ordered
                        {{ service.quantityOrdered }} {{ service.unit }}
                    </p>
                    <label :for="`performed-${service.publicId}`"
                        >Service disposition</label
                    >
                    <OperationalSelect
                        v-model="sendForm.service_deliveries[index].disposition"
                        label="Service disposition"
                        :options="dispositionOptions"
                    />
                    <label
                        v-if="
                            sendForm.service_deliveries[index].disposition ===
                            'performed'
                        "
                        class="block"
                        >Performed quantity<input
                            v-model="
                                sendForm.service_deliveries[index]
                                    .quantity_performed
                            "
                            inputmode="decimal"
                            class="ml-2 h-9 w-24 rounded-md border border-input bg-card px-2 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                    /></label>
                </div>
                <InputError :message="Object.values(sendForm.errors)[0]" />
                <DialogFooter>
                    <DialogClose as-child
                        ><Button variant="outline">Cancel</Button></DialogClose
                    >
                    <Button
                        :disabled="sendForm.processing"
                        @click="sendToDispensary"
                        >Complete Consultation</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
