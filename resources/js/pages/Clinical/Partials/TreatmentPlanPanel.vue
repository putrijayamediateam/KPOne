<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type {
    ClinicalAllergySafety,
    TreatmentPlanPage,
} from '@/types/clinical';

const props = defineProps<{
    visitNumber: string;
    branchId: number;
    plan: TreatmentPlanPage;
    allergies: ClinicalAllergySafety | null;
}>();

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

const form = useForm({
    expected_branch_id: props.branchId,
    lock_version: props.plan.lockVersion,
    medicines: props.plan.medicines.map((item) => ({
        public_id: item.publicId as string | null,
        catalogue_public_id: null as string | null,
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
    })),
    services: props.plan.services.map((item) => ({
        public_id: item.publicId as string | null,
        catalogue_public_id: null as string | null,
        quantity_ordered: item.quantityOrdered,
        clinical_instruction: item.clinicalInstruction ?? '',
        display_name: item.displayName,
        code: item.code,
        unit: item.unit,
    })),
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
    });
    medicineResults.value = [];
    medicineQuery.value = '';
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
                form.medicines = props.plan.medicines.map((item) => ({
                    public_id: item.publicId as string | null,
                    catalogue_public_id: null,
                    quantity_ordered: item.quantityOrdered,
                    dosage: item.dosage,
                    frequency: item.frequency,
                    duration: item.duration ?? '',
                    route: item.route ?? '',
                    administration_instruction:
                        item.administrationInstruction ?? '',
                    indication: item.indication ?? '',
                    precaution: item.precaution ?? '',
                    display_name: item.displayName,
                    code: item.code,
                    unit: item.unit,
                    strength: item.strength,
                }));
                form.services = props.plan.services.map((item) => ({
                    public_id: item.publicId as string | null,
                    catalogue_public_id: null,
                    quantity_ordered: item.quantityOrdered,
                    clinical_instruction: item.clinicalInstruction ?? '',
                    display_name: item.displayName,
                    code: item.code,
                    unit: item.unit,
                }));
                form.defaults();
            },
        },
    );
};
</script>

<template>
    <section class="space-y-4 rounded-lg border bg-background p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">Treatment Plan</h2>
                <p class="text-xs text-muted-foreground">
                    Clinical orders only. Dispensing, stock and billing are
                    handled later.
                </p>
            </div>
            <span class="rounded border px-2 py-1 text-xs">In progress</span>
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

        <div class="space-y-3">
            <div class="flex flex-wrap items-end gap-2">
                <label class="min-w-64 flex-1 text-sm">
                    Find medicine
                    <input
                        v-model="medicineQuery"
                        class="mt-1 h-9 w-full rounded-md border px-3"
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
                class="space-y-2 rounded-md border p-3"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium">
                        {{ item.display_name }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ item.code }}</span
                        >
                    </div>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Withdraw medicine order"
                        @click="form.medicines.splice(index, 1)"
                        ><Trash2 class="size-4"
                    /></Button>
                </div>
                <div class="grid gap-2 md:grid-cols-3">
                    <label class="text-xs"
                        >Quantity ({{ item.unit }})<input
                            v-model="item.quantity_ordered"
                            type="number"
                            min="0.001"
                            step="0.001"
                            class="mt-1 h-9 w-full rounded-md border px-3 text-sm"
                    /></label>
                    <label class="text-xs"
                        >Dosage<input
                            v-model="item.dosage"
                            maxlength="255"
                            class="mt-1 h-9 w-full rounded-md border px-3 text-sm"
                    /></label>
                    <label class="text-xs"
                        >Frequency<input
                            v-model="item.frequency"
                            maxlength="255"
                            class="mt-1 h-9 w-full rounded-md border px-3 text-sm"
                    /></label>
                    <label class="text-xs"
                        >Duration<input
                            v-model="item.duration"
                            maxlength="255"
                            class="mt-1 h-9 w-full rounded-md border px-3 text-sm"
                    /></label>
                    <label class="text-xs"
                        >Route<input
                            v-model="item.route"
                            maxlength="255"
                            class="mt-1 h-9 w-full rounded-md border px-3 text-sm"
                    /></label>
                    <label class="text-xs"
                        >Instruction<input
                            v-model="item.administration_instruction"
                            maxlength="2000"
                            class="mt-1 h-9 w-full rounded-md border px-3 text-sm"
                    /></label>
                </div>
                <details class="text-xs text-muted-foreground">
                    <summary class="cursor-pointer">
                        Additional clinical details
                    </summary>
                    <div class="mt-2 grid gap-2 md:grid-cols-2">
                        <label
                            >Indication<textarea
                                v-model="item.indication"
                                maxlength="2000"
                                rows="2"
                                class="mt-1 w-full rounded-md border bg-background p-2 text-sm text-foreground"
                            /></label
                        ><label
                            >Precaution / additional instruction<textarea
                                v-model="item.precaution"
                                maxlength="2000"
                                rows="2"
                                class="mt-1 w-full rounded-md border bg-background p-2 text-sm text-foreground"
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

        <div class="space-y-3 border-t pt-4">
            <div class="flex flex-wrap items-end gap-2">
                <label class="min-w-64 flex-1 text-sm"
                    >Find service / procedure<input
                        v-model="serviceQuery"
                        class="mt-1 h-9 w-full rounded-md border px-3"
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
                class="grid gap-2 rounded-md border p-3 md:grid-cols-[1fr_140px_2fr_auto] md:items-end"
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
                        class="mt-1 h-9 w-full rounded-md border px-3 text-sm"
                /></label>
                <label class="text-xs"
                    >Clinical instruction<input
                        v-model="item.clinical_instruction"
                        maxlength="2000"
                        class="mt-1 h-9 w-full rounded-md border px-3 text-sm"
                /></label>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Withdraw service order"
                    @click="form.services.splice(index, 1)"
                    ><Trash2 class="size-4"
                /></Button>
            </div>
            <p
                v-if="!form.services.length"
                class="text-sm text-muted-foreground"
            >
                No service or procedure ordered.
            </p>
        </div>

        <InputError :message="formError" />
        <div class="flex justify-end">
            <Button
                type="button"
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
    </section>
</template>
