<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    LoaderCircle,
    Megaphone,
    Plus,
    Printer,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';
import { formatStatusLabel } from '@/lib/presentation';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dispensary', href: '/registration' }] },
});

type Availability = {
    locationPublicId: string;
    locationName: string;
    batchPublicId: string;
    batchNumber: string;
    expiryDate: string;
    quantity: string;
};
type Original = {
    quantity: string;
    dosage: string;
    frequency: string;
    duration: string | null;
    route: string | null;
    instruction: string | null;
    precaution: string | null;
};
type Item = {
    publicId: string;
    lockVersion: number;
    name: string;
    code: string;
    strength: string | null;
    dosageForm: string | null;
    unit: string;
    quantityOrdered: string;
    quantityDispensed: string | null;
    status: 'pending' | 'dispensed' | 'partial' | 'not_dispensed';
    reason: string | null;
    dosage: string;
    frequency: string;
    duration: string | null;
    route: string | null;
    instruction: string | null;
    precaution: string | null;
    source: 'doctor' | 'ca';
    changeState: 'unchanged' | 'edited' | 'added' | 'removed';
    original: Original;
    sku: { publicId: string; unit: string } | null;
    availability: Availability[];
    exception: {
        public_id: string;
        status: string;
        proposed_quantity_dispensed: string;
    } | null;
};
type ServiceLine = {
    publicId: string;
    lockVersion: number;
    name: string;
    code: string;
    unit: string;
    quantityOrdered: string;
    quantityPerformed: string;
    disposition: 'performed' | 'not_performed';
    instruction: string | null;
    source: 'doctor' | 'ca';
    changeState: 'unchanged' | 'edited' | 'added' | 'removed';
    original: { quantity: string | null; instruction: string | null };
};
type ServiceResult = {
    publicId: string;
    name: string;
    code: string;
    unit: string;
};
type Page = {
    publicId: string;
    status: 'pending' | 'dispensing';
    lockVersion: number;
    receivedAt: string;
    patient: { patientNumber: string; name: string };
    visit: { visitNumber: string };
    doctor: string | null;
    allergySafety: {
        allergies: Array<{
            allergen: string;
            reaction: string | null;
            severity: string | null;
        }>;
        status: string;
        profileVersion: number | null;
        isCurrent: boolean;
    };
    items: Item[];
    services: ServiceLine[];
    can: {
        start: boolean;
        update: boolean;
        complete: boolean;
        return: boolean;
    };
    tvCall?: {
        canCall: boolean;
        rooms: Array<{ id: number; name: string }>;
    };
};
type Medicine = {
    publicId: string;
    name: string;
    code: string;
    strength: string | null;
    unit: string;
    sku: { publicId: string; unit: string };
    availability: Availability[];
};
type LineForm = {
    quantity: string;
    dosage: string;
    frequency: string;
    duration: string;
    route: string;
    instruction: string;
    precaution: string;
    availabilityIndex: number;
};

const props = defineProps<{ dispensary: Page }>();
const busy = ref(false);
const actionError = ref<string | null>(null);
const page = usePage();
const branchId = () => page.props.branchContext?.active?.id ?? 0;

// A line the CA has not saved yet shows the doctor's quantity; a saved line shows the CA's.
const formFor = (item: Item): LineForm => ({
    quantity: item.quantityDispensed ?? item.quantityOrdered,
    dosage: item.dosage ?? '',
    frequency: item.frequency ?? '',
    duration: item.duration ?? '',
    route: item.route ?? '',
    instruction: item.instruction ?? '',
    precaution: item.precaution ?? '',
    availabilityIndex: 0,
});
const forms = reactive<Record<string, LineForm>>({});
const baseLock = reactive<Record<string, number>>({});
const isDirty = (item: Item): boolean => {
    const form = forms[item.publicId];
    const saved = formFor(item);

    return (
        !!form &&
        (
            [
                'quantity',
                'dosage',
                'frequency',
                'duration',
                'route',
                'instruction',
                'precaution',
            ] as const
        ).some((key) => form[key] !== saved[key])
    );
};
// Adopt the server's version of a line when it changes, but never overwrite what the CA is typing.
watch(
    () =>
        props.dispensary.items.map(
            (item) => `${item.publicId}:${item.lockVersion}`,
        ),
    () => {
        for (const item of props.dispensary.items) {
            if (!forms[item.publicId]) {
                forms[item.publicId] = formFor(item);
                baseLock[item.publicId] = item.lockVersion;
            } else if (
                baseLock[item.publicId] !== item.lockVersion &&
                !isDirty({ ...item, lockVersion: baseLock[item.publicId] })
            ) {
                forms[item.publicId] = formFor(item);
                baseLock[item.publicId] = item.lockVersion;
            }
        }
    },
    { immediate: true },
);

// Services: the CA confirms how much was performed; the doctor's confirmation stays on record.
const serviceFormFor = (line: ServiceLine) => ({
    quantity: line.quantityPerformed,
    instruction: line.instruction ?? '',
});
const serviceForms = reactive<
    Record<string, { quantity: string; instruction: string }>
>({});
const serviceBase = reactive<Record<string, number>>({});
const isServiceDirty = (line: ServiceLine): boolean => {
    const form = serviceForms[line.publicId];
    const saved = serviceFormFor(line);

    return (
        !!form &&
        (form.quantity !== saved.quantity ||
            form.instruction !== saved.instruction)
    );
};
watch(
    () =>
        props.dispensary.services.map(
            (line) => `${line.publicId}:${line.lockVersion}`,
        ),
    () => {
        for (const line of props.dispensary.services) {
            if (!serviceForms[line.publicId]) {
                serviceForms[line.publicId] = serviceFormFor(line);
                serviceBase[line.publicId] = line.lockVersion;
            } else if (
                serviceBase[line.publicId] !== line.lockVersion &&
                !isServiceDirty({
                    ...line,
                    lockVersion: serviceBase[line.publicId],
                })
            ) {
                serviceForms[line.publicId] = serviceFormFor(line);
                serviceBase[line.publicId] = line.lockVersion;
            }
        }
    },
    { immediate: true },
);
const visibleServices = computed(() =>
    props.dispensary.services.filter((line) => line.changeState !== 'removed'),
);
const removedServices = computed(() =>
    props.dispensary.services.filter((line) => line.changeState === 'removed'),
);
const unsavedServices = computed(() =>
    visibleServices.value.filter((line) => isServiceDirty(line)),
);
const saveService = (line: ServiceLine, done?: () => void) => {
    const form = serviceForms[line.publicId];
    busy.value = true;
    actionError.value = null;
    router.put(
        `/dispensary/${props.dispensary.publicId}/services/${line.publicId}/final`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            line_lock_version: line.lockVersion,
            quantity_performed: form.quantity,
            clinical_instruction: form.instruction || null,
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onSuccess: () => done?.(),
            onFinish: () => (busy.value = false),
        },
    );
};
const removeService = (line: ServiceLine) => {
    busy.value = true;
    actionError.value = null;
    router.post(
        `/dispensary/${props.dispensary.publicId}/services/${line.publicId}/remove`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            line_lock_version: line.lockVersion,
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onFinish: () => (busy.value = false),
        },
    );
};
// Putting a removed service back restores what the doctor confirmed, or the ordered quantity.
const restoreService = (line: ServiceLine) => {
    const original = Number(line.original.quantity ?? 0);
    busy.value = true;
    actionError.value = null;
    router.put(
        `/dispensary/${props.dispensary.publicId}/services/${line.publicId}/final`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            line_lock_version: line.lockVersion,
            quantity_performed:
                original > 0 ? line.original.quantity : line.quantityOrdered,
            clinical_instruction: line.original.instruction,
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onFinish: () => (busy.value = false),
        },
    );
};
const serviceTerm = ref('');
const serviceResults = ref<ServiceResult[]>([]);
const serviceSearching = ref(false);
const chosenService = ref<ServiceResult | null>(null);
const serviceAdd = reactive({ quantity: '1.000', instruction: '' });
const searchServices = async () => {
    if (serviceTerm.value.trim().length < 2) {
        serviceResults.value = [];

        return;
    }

    serviceSearching.value = true;

    try {
        const response = await fetch(
            `/dispensary/${props.dispensary.publicId}/services/search`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ query: serviceTerm.value.trim() }),
            },
        );
        serviceResults.value = response.ok
            ? ((await response.json()) as { data: ServiceResult[] }).data
            : [];
    } finally {
        serviceSearching.value = false;
    }
};
const addService = () => {
    const service = chosenService.value;

    if (!service) {
        return;
    }

    busy.value = true;
    actionError.value = null;
    router.post(
        `/dispensary/${props.dispensary.publicId}/services`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            service_public_id: service.publicId,
            quantity_performed: serviceAdd.quantity,
            clinical_instruction: serviceAdd.instruction || null,
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onSuccess: () => {
                chosenService.value = null;
                serviceResults.value = [];
                serviceTerm.value = '';
                Object.assign(serviceAdd, {
                    quantity: '1.000',
                    instruction: '',
                });
            },
            onFinish: () => (busy.value = false),
        },
    );
};
const serviceChangeLabel = (line: ServiceLine) =>
    ({
        edited: 'Edited by CA',
        added: 'Added by CA',
        removed: 'Removed',
        unchanged: '',
    })[line.changeState];

const visibleItems = computed(() =>
    props.dispensary.items.filter((item) => item.changeState !== 'removed'),
);
const removedItems = computed(() =>
    props.dispensary.items.filter((item) => item.changeState === 'removed'),
);
const unsaved = computed(() =>
    visibleItems.value.filter(
        (item) => item.status === 'pending' || isDirty(item),
    ),
);
const showActionError = (errors: Record<string, string>) => {
    actionError.value =
        Object.values(errors)[0] ??
        'The Dispensary action could not be completed. Review current details and retry.';
};
const caseAction = (suffix: string, extra: Record<string, unknown> = {}) => {
    busy.value = true;
    actionError.value = null;
    router.post(
        `/dispensary/${props.dispensary.publicId}/${suffix}`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            ...extra,
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onFinish: () => (busy.value = false),
        },
    );
};
// Calls the patient to the dispensary on the waiting-room TV; the case itself is unchanged.
const callRoomId = ref<string | number>(
    props.dispensary.tvCall?.rooms.length === 1
        ? props.dispensary.tvCall.rooms[0].id
        : '',
);
const callPatient = () => {
    busy.value = true;
    actionError.value = null;
    router.post(
        `/dispensary/${props.dispensary.publicId}/call`,
        {
            expected_branch_id: branchId(),
            branch_room_id: callRoomId.value === '' ? null : callRoomId.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: showActionError,
            onFinish: () => (busy.value = false),
        },
    );
};

const allocationFor = (
    availability: Availability | undefined,
    skuPublicId: string | undefined,
    quantity: string,
) =>
    availability && skuPublicId
        ? [
              {
                  location_public_id: availability.locationPublicId,
                  sku_public_id: skuPublicId,
                  batch_public_id: availability.batchPublicId,
                  quantity,
              },
          ]
        : [];
const linePayload = (form: LineForm) => ({
    quantity_dispensed: form.quantity,
    dosage: form.dosage,
    frequency: form.frequency,
    duration: form.duration || null,
    route: form.route || null,
    administration_instruction: form.instruction || null,
    precaution: form.precaution || null,
});
const saveItem = (item: Item, done?: () => void) => {
    const form = forms[item.publicId];
    busy.value = true;
    actionError.value = null;
    router.put(
        `/dispensary/${props.dispensary.publicId}/items/${item.publicId}/final`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            item_lock_version: item.lockVersion,
            ...linePayload(form),
            allocations: allocationFor(
                item.availability[form.availabilityIndex],
                item.sku?.publicId,
                form.quantity,
            ),
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onSuccess: () => done?.(),
            onFinish: () => (busy.value = false),
        },
    );
};
const saveAll = () => {
    const next = unsaved.value[0];
    const nextService = unsavedServices.value[0];

    if (next) {
        saveItem(next, saveAll);
    } else if (nextService) {
        saveService(nextService, saveAll);
    }
};
const removeItem = (item: Item) => {
    busy.value = true;
    actionError.value = null;
    router.post(
        `/dispensary/${props.dispensary.publicId}/items/${item.publicId}/remove`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            item_lock_version: item.lockVersion,
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onFinish: () => (busy.value = false),
        },
    );
};
// Putting a removed line back restores the doctor's (or the CA's first) version.
const restoreItem = (item: Item) => {
    const first = item.availability[0];
    const quantity = item.original.quantity;
    busy.value = true;
    actionError.value = null;
    router.put(
        `/dispensary/${props.dispensary.publicId}/items/${item.publicId}/final`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            item_lock_version: item.lockVersion,
            quantity_dispensed: quantity,
            dosage: item.original.dosage,
            frequency: item.original.frequency,
            duration: item.original.duration,
            route: item.original.route,
            administration_instruction: item.original.instruction,
            precaution: item.original.precaution,
            allocations: allocationFor(first, item.sku?.publicId, quantity),
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onFinish: () => (busy.value = false),
        },
    );
};

// Add a medicine the doctor did not order.
const searchTerm = ref('');
const results = ref<Medicine[]>([]);
const searching = ref(false);
const chosen = ref<Medicine | null>(null);
const addForm = reactive<LineForm>({
    quantity: '1.000',
    dosage: '',
    frequency: '',
    duration: '',
    route: '',
    instruction: '',
    precaution: '',
    availabilityIndex: 0,
});
const csrf = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
const searchMedicines = async () => {
    if (searchTerm.value.trim().length < 2) {
        results.value = [];

        return;
    }

    searching.value = true;

    try {
        const response = await fetch(
            `/dispensary/${props.dispensary.publicId}/medicines/search`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ query: searchTerm.value.trim() }),
            },
        );
        results.value = response.ok
            ? ((await response.json()) as { data: Medicine[] }).data
            : [];
    } finally {
        searching.value = false;
    }
};
const chooseMedicine = (medicine: Medicine) => {
    chosen.value = medicine;
    addForm.availabilityIndex = 0;
};
const addMedicine = () => {
    const medicine = chosen.value;

    if (!medicine) {
        return;
    }

    busy.value = true;
    actionError.value = null;
    router.post(
        `/dispensary/${props.dispensary.publicId}/items`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            medicine_public_id: medicine.publicId,
            ...linePayload(addForm),
            allocations: allocationFor(
                medicine.availability[addForm.availabilityIndex],
                medicine.sku.publicId,
                addForm.quantity,
            ),
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onSuccess: () => {
                chosen.value = null;
                results.value = [];
                searchTerm.value = '';
                Object.assign(addForm, {
                    quantity: '1.000',
                    dosage: '',
                    frequency: '',
                    duration: '',
                    route: '',
                    instruction: '',
                    precaution: '',
                });
            },
            onFinish: () => (busy.value = false),
        },
    );
};

// The CA's own verification, after the doctor's, before stock moves.
const confirming = ref(false);
const completeCase = () => {
    caseAction('complete');
};
const changeLabel = (item: Item) =>
    ({
        edited: 'Edited by CA',
        added: 'Added by CA',
        removed: 'Removed',
        unchanged: '',
    })[item.changeState];
const inputClass = 'mt-1 h-9 w-full rounded-md border px-2';
</script>

<template>
    <Head title="Dispensary" />
    <main class="mx-auto w-full max-w-[1500px] px-4 py-4 lg:px-6">
        <header
            class="mb-3 flex flex-wrap items-center justify-between gap-3 border-b pb-3"
        >
            <div>
                <p
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    Dispensary
                </p>
                <h1 class="text-lg font-semibold">
                    {{ dispensary.patient.name }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ dispensary.patient.patientNumber }} ·
                    {{ dispensary.visit.visitNumber }} ·
                    {{ dispensary.doctor ?? 'No doctor' }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="visibleItems.length"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <a
                        :href="`/dispensary/${dispensary.publicId}/labels`"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <Printer class="size-4" />Print all labels
                        <span class="sr-only"
                            >(opens print preview in a new tab)</span
                        >
                    </a>
                </Button>
                <OperationalSelect
                    v-if="
                        dispensary.tvCall?.canCall &&
                        dispensary.tvCall.rooms.length > 1
                    "
                    v-model="callRoomId"
                    label="Dispensary room"
                    placeholder="Choose room"
                    trigger-class="h-8 w-40"
                    :options="
                        dispensary.tvCall.rooms.map((room) => ({
                            value: room.id,
                            label: room.name,
                        }))
                    "
                />
                <Button
                    v-if="dispensary.tvCall?.canCall"
                    variant="outline"
                    size="sm"
                    :disabled="
                        busy ||
                        (dispensary.tvCall.rooms.length > 1 &&
                            callRoomId === '')
                    "
                    data-testid="dispensary-call"
                    @click="callPatient"
                    ><Megaphone class="size-4" />Panggil</Button
                >
                <Button
                    v-if="dispensary.can.start"
                    :disabled="busy"
                    @click="caseAction('start')"
                    >Start Dispensing</Button
                ><Button
                    v-if="dispensary.can.return"
                    variant="outline"
                    :disabled="busy"
                    @click="caseAction('return-to-doctor')"
                    ><RotateCcw class="size-4" />Return to Doctor</Button
                ><Button
                    v-if="dispensary.can.complete"
                    :disabled="
                        busy || unsaved.length > 0 || unsavedServices.length > 0
                    "
                    data-testid="dispensary-complete-open"
                    @click="confirming = true"
                    ><CheckCircle2 class="size-4" />Complete Dispensary</Button
                >
            </div>
        </header>

        <p
            v-if="actionError"
            role="alert"
            class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            {{ actionError }}
        </p>
        <div
            class="mb-3 rounded-lg border px-3 py-2 text-sm"
            :class="
                dispensary.allergySafety.isCurrent
                    ? 'border-emerald-200 bg-emerald-50/50'
                    : 'border-amber-300 bg-amber-50/70'
            "
            data-testid="dispensary-allergies"
        >
            <div class="flex items-center justify-between">
                <span
                    >Allergy safety:
                    <strong>{{
                        formatStatusLabel(dispensary.allergySafety.status)
                    }}</strong></span
                ><span>{{
                    dispensary.allergySafety.isCurrent
                        ? 'Current'
                        : 'Return to doctor required'
                }}</span>
            </div>
            <ul
                v-if="dispensary.allergySafety.allergies.length"
                class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs"
            >
                <li
                    v-for="(allergy, index) in dispensary.allergySafety
                        .allergies"
                    :key="index"
                >
                    <strong>{{ allergy.allergen }}</strong>
                    <span v-if="allergy.reaction">
                        — {{ allergy.reaction }}</span
                    >
                    <span v-if="allergy.severity">
                        ({{ allergy.severity }})</span
                    >
                </li>
            </ul>
        </div>

        <section
            v-if="confirming && dispensary.can.complete"
            class="mb-3 rounded-lg border border-pink-300 bg-pink-50/60 p-3 text-sm"
            data-testid="dispensary-verify"
        >
            <p class="font-medium">Verify before completing</p>
            <p class="mb-2 text-xs text-muted-foreground">
                The doctor has verified the order. Completing now records your
                verification of the final list below and takes the stock out.
            </p>
            <div class="mt-2 flex gap-2">
                <Button
                    size="sm"
                    :disabled="busy"
                    data-testid="dispensary-complete-confirm"
                    @click="completeCase"
                    ><CheckCircle2 class="size-4" />Confirm and complete</Button
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    @click="confirming = false"
                    >Cancel</Button
                >
            </div>
        </section>

        <div
            v-if="
                dispensary.can.update &&
                (unsaved.length || unsavedServices.length)
            "
            class="mb-3 flex items-center justify-between rounded-lg border border-amber-300 bg-amber-50/70 px-3 py-2 text-sm"
            data-testid="dispensary-unsaved"
        >
            <span
                >{{ unsaved.length + unsavedServices.length }} line{{
                    unsaved.length + unsavedServices.length === 1 ? '' : 's'
                }}
                not saved yet. Save each line to confirm it, then
                complete.</span
            >
            <Button size="sm" :disabled="busy" @click="saveAll"
                >Save all lines</Button
            >
        </div>

        <section class="divide-y rounded-lg border bg-background">
            <article
                v-for="item in visibleItems"
                :key="item.publicId"
                class="grid gap-3 p-3 lg:grid-cols-[minmax(220px,1fr)_minmax(0,3fr)]"
                :data-testid="`line-${item.publicId}`"
            >
                <div>
                    <h2 class="text-sm font-medium">{{ item.name }}</h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            [item.strength, item.dosageForm, item.code]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </p>
                    <p
                        v-if="changeLabel(item)"
                        class="mt-1 inline-block rounded-md bg-pink-100/70 px-2 py-0.5 text-[11px] text-pink-800"
                    >
                        {{ changeLabel(item) }}
                    </p>
                    <p
                        v-if="item.source === 'doctor'"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Doctor ordered
                        <strong class="text-foreground"
                            >{{ item.quantityOrdered }} {{ item.unit }}</strong
                        >
                    </p>
                    <p
                        v-if="
                            item.changeState === 'edited' &&
                            item.source === 'doctor'
                        "
                        class="mt-1 text-xs text-muted-foreground"
                        data-testid="original-order"
                    >
                        Original: {{ item.original.dosage }} ·
                        {{ item.original.frequency
                        }}<template v-if="item.original.duration">
                            · {{ item.original.duration }}</template
                        >
                    </p>
                    <p
                        v-if="item.exception"
                        class="mt-1 text-xs text-amber-700"
                    >
                        Earlier partial dispense: {{ item.exception.status }}
                    </p>
                    <Button
                        v-if="item.status === 'dispensed' && !isDirty(item)"
                        as-child
                        variant="ghost"
                        size="sm"
                        class="mt-1"
                    >
                        <a
                            :href="`/dispensary/${dispensary.publicId}/items/${item.publicId}/label`"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Printer class="size-3.5" />Print Label
                            <span class="sr-only"
                                >for {{ item.name }} (opens print preview in a
                                new tab)</span
                            >
                        </a>
                    </Button>
                </div>
                <div
                    v-if="dispensary.can.update && forms[item.publicId]"
                    class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
                >
                    <label
                        >Quantity ({{ item.unit }})<input
                            v-model="forms[item.publicId].quantity"
                            :class="inputClass"
                            inputmode="decimal"
                            data-field="quantity"
                    /></label>
                    <label
                        >Dosage<input
                            v-model="forms[item.publicId].dosage"
                            :class="inputClass"
                            maxlength="500"
                            data-field="dosage"
                    /></label>
                    <label
                        >Frequency<input
                            v-model="forms[item.publicId].frequency"
                            :class="inputClass"
                            maxlength="500"
                            data-field="frequency"
                    /></label>
                    <label
                        >Duration<input
                            v-model="forms[item.publicId].duration"
                            :class="inputClass"
                            maxlength="500"
                            data-field="duration"
                    /></label>
                    <label
                        >Route<input
                            v-model="forms[item.publicId].route"
                            :class="inputClass"
                            maxlength="500"
                            data-field="route"
                    /></label>
                    <label class="sm:col-span-2"
                        >Instruction<input
                            v-model="forms[item.publicId].instruction"
                            :class="inputClass"
                            maxlength="2000"
                            data-field="instruction"
                    /></label>
                    <label class="sm:col-span-2"
                        >Precaution<input
                            v-model="forms[item.publicId].precaution"
                            :class="inputClass"
                            maxlength="2000"
                            data-field="precaution"
                    /></label>
                    <label class="sm:col-span-2"
                        >Batch<select
                            v-model.number="
                                forms[item.publicId].availabilityIndex
                            "
                            :class="inputClass"
                        >
                            <option
                                v-for="(stock, index) in item.availability"
                                :key="stock.batchPublicId"
                                :value="index"
                            >
                                {{ stock.locationName }} ·
                                {{ stock.batchNumber }} · exp
                                {{ stock.expiryDate }} · {{ stock.quantity }}
                            </option>
                        </select></label
                    >
                    <p
                        v-if="!item.sku"
                        class="text-xs text-amber-700 sm:col-span-2"
                    >
                        No approved inventory SKU mapping.
                    </p>
                    <p
                        v-else-if="!item.availability.length"
                        class="text-xs text-amber-700 sm:col-span-2"
                    >
                        No eligible stock in this branch's Dispensary. Stock at
                        other locations requires transfer before fulfilment.
                    </p>
                    <div class="flex items-end gap-2 sm:col-span-4">
                        <Button
                            size="sm"
                            :disabled="
                                busy ||
                                (item.status !== 'pending' && !isDirty(item))
                            "
                            data-action="save-line"
                            @click="saveItem(item)"
                            ><LoaderCircle
                                v-if="busy"
                                class="size-4 animate-spin"
                            />Save line</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="busy"
                            data-action="remove-line"
                            @click="removeItem(item)"
                            ><Trash2 class="size-4" />Remove</Button
                        >
                    </div>
                </div>
                <dl
                    v-else
                    class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
                >
                    <p>
                        <span class="text-muted-foreground">Quantity</span
                        ><br />{{
                            item.quantityDispensed ?? item.quantityOrdered
                        }}
                        {{ item.unit }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Dosage</span
                        ><br />{{ item.dosage }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Frequency</span
                        ><br />{{ item.frequency }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Duration</span
                        ><br />{{ item.duration ?? 'Not recorded' }}
                    </p>
                </dl>
            </article>
            <p
                v-if="!visibleItems.length"
                class="p-3 text-sm text-muted-foreground"
            >
                Every medicine has been removed from the list. Add a medicine
                below, or return the case to the doctor.
            </p>
        </section>

        <section
            v-if="removedItems.length"
            class="mt-3 rounded-lg border border-dashed p-3 text-sm"
            data-testid="removed-lines"
        >
            <h2
                class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
            >
                Removed from the list
            </h2>
            <ul class="grid gap-1">
                <li
                    v-for="item in removedItems"
                    :key="item.publicId"
                    class="flex items-center justify-between gap-2"
                >
                    <span class="text-muted-foreground line-through">{{
                        item.name
                    }}</span>
                    <Button
                        v-if="dispensary.can.update"
                        size="sm"
                        variant="outline"
                        :disabled="busy || !item.availability.length"
                        data-action="restore-line"
                        @click="restoreItem(item)"
                        >Put back</Button
                    >
                </li>
            </ul>
        </section>

        <section
            v-if="dispensary.can.update"
            class="mt-3 rounded-lg border p-3 text-sm"
            data-testid="add-medicine"
        >
            <h2
                class="mb-2 text-xs font-semibold text-muted-foreground uppercase"
            >
                Add a medicine
            </h2>
            <div class="flex gap-2">
                <input
                    v-model="searchTerm"
                    class="h-9 w-full max-w-sm rounded-md border px-2"
                    placeholder="Search medicine name or code"
                    aria-label="Search medicine"
                    data-field="medicine-search"
                    @keydown.enter.prevent="searchMedicines"
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="searching || busy"
                    @click="searchMedicines"
                    ><Plus class="size-4" />Search</Button
                >
            </div>
            <ul
                v-if="!chosen && results.length"
                class="mt-2 divide-y rounded-md border"
            >
                <li
                    v-for="medicine in results"
                    :key="medicine.publicId"
                    class="flex items-center justify-between gap-2 px-2 py-1.5"
                >
                    <span
                        >{{ medicine.name }}
                        <span class="text-xs text-muted-foreground">{{
                            [medicine.strength, medicine.code]
                                .filter(Boolean)
                                .join(' · ')
                        }}</span></span
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!medicine.availability.length"
                        data-action="choose-medicine"
                        @click="chooseMedicine(medicine)"
                        >{{
                            medicine.availability.length ? 'Choose' : 'No stock'
                        }}</Button
                    >
                </li>
            </ul>
            <p
                v-else-if="
                    !chosen &&
                    searchTerm.trim().length >= 2 &&
                    !searching &&
                    !results.length
                "
                class="mt-2 text-xs text-muted-foreground"
            >
                No matching medicine with a stock mapping.
            </p>
            <div
                v-if="chosen"
                class="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
            >
                <p class="text-sm font-medium sm:col-span-4">
                    {{ chosen.name }}
                </p>
                <label
                    >Quantity ({{ chosen.unit }})<input
                        v-model="addForm.quantity"
                        :class="inputClass"
                        inputmode="decimal"
                        data-field="add-quantity"
                /></label>
                <label
                    >Dosage<input
                        v-model="addForm.dosage"
                        :class="inputClass"
                        maxlength="500"
                        data-field="add-dosage"
                /></label>
                <label
                    >Frequency<input
                        v-model="addForm.frequency"
                        :class="inputClass"
                        maxlength="500"
                        data-field="add-frequency"
                /></label>
                <label
                    >Duration<input
                        v-model="addForm.duration"
                        :class="inputClass"
                        maxlength="500"
                /></label>
                <label
                    >Route<input
                        v-model="addForm.route"
                        :class="inputClass"
                        maxlength="500"
                /></label>
                <label class="sm:col-span-2"
                    >Instruction<input
                        v-model="addForm.instruction"
                        :class="inputClass"
                        maxlength="2000"
                /></label>
                <label class="sm:col-span-2"
                    >Precaution<input
                        v-model="addForm.precaution"
                        :class="inputClass"
                        maxlength="2000"
                /></label>
                <label class="sm:col-span-2"
                    >Batch<select
                        v-model.number="addForm.availabilityIndex"
                        :class="inputClass"
                    >
                        <option
                            v-for="(stock, index) in chosen.availability"
                            :key="stock.batchPublicId"
                            :value="index"
                        >
                            {{ stock.locationName }} · {{ stock.batchNumber }} ·
                            exp {{ stock.expiryDate }} · {{ stock.quantity }}
                        </option>
                    </select></label
                >
                <div class="flex gap-2 sm:col-span-4">
                    <Button
                        size="sm"
                        :disabled="
                            busy ||
                            !addForm.dosage.trim() ||
                            !addForm.frequency.trim()
                        "
                        data-action="add-medicine"
                        @click="addMedicine"
                        >Add to list</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="busy"
                        @click="chosen = null"
                        >Cancel</Button
                    >
                </div>
            </div>
        </section>
        <section
            class="mt-6 rounded-lg border bg-background"
            data-testid="dispensary-services"
        >
            <h2
                class="border-b px-3 py-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Services
            </h2>
            <p
                v-if="!visibleServices.length"
                class="p-3 text-sm text-muted-foreground"
            >
                No services on this visit.
            </p>
            <div class="divide-y">
                <article
                    v-for="line in visibleServices"
                    :key="line.publicId"
                    class="grid gap-3 p-3 lg:grid-cols-[minmax(220px,1fr)_minmax(0,3fr)]"
                    :data-testid="`service-${line.publicId}`"
                >
                    <div>
                        <h3 class="text-sm font-medium">{{ line.name }}</h3>
                        <p class="text-xs text-muted-foreground">
                            {{ line.code }}
                        </p>
                        <p
                            v-if="serviceChangeLabel(line)"
                            class="mt-1 inline-block rounded-md bg-pink-100/70 px-2 py-0.5 text-[11px] text-pink-800"
                        >
                            {{ serviceChangeLabel(line) }}
                        </p>
                        <p
                            v-if="line.source === 'doctor'"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Doctor ordered
                            <strong class="text-foreground"
                                >{{ line.quantityOrdered }}
                                {{ line.unit }}</strong
                            >
                            · confirmed
                            <strong class="text-foreground">{{
                                line.original.quantity ?? '0.000'
                            }}</strong>
                        </p>
                        <p
                            class="mt-1 text-xs"
                            :class="
                                line.disposition === 'performed'
                                    ? 'text-emerald-700'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                line.disposition === 'performed'
                                    ? 'Performed'
                                    : 'Not performed'
                            }}
                        </p>
                    </div>
                    <div
                        v-if="
                            dispensary.can.update && serviceForms[line.publicId]
                        "
                        class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <label
                            >Performed ({{ line.unit }})<input
                                v-model="serviceForms[line.publicId].quantity"
                                :class="inputClass"
                                inputmode="decimal"
                                data-field="service-quantity"
                        /></label>
                        <label class="sm:col-span-3"
                            >Instruction<input
                                v-model="
                                    serviceForms[line.publicId].instruction
                                "
                                :class="inputClass"
                                maxlength="2000"
                                data-field="service-instruction"
                        /></label>
                        <p class="text-xs text-muted-foreground sm:col-span-4">
                            Enter 0 if the service was not performed.
                        </p>
                        <div class="flex items-end gap-2 sm:col-span-4">
                            <Button
                                size="sm"
                                :disabled="busy || !isServiceDirty(line)"
                                data-action="save-service"
                                @click="saveService(line)"
                                >Confirm and save</Button
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="busy"
                                data-action="remove-service"
                                @click="removeService(line)"
                                ><Trash2 class="size-4" />Remove</Button
                            >
                        </div>
                    </div>
                    <p v-else class="text-xs">
                        {{ line.quantityPerformed }} {{ line.unit }}
                        <span v-if="line.instruction"
                            >· {{ line.instruction }}</span
                        >
                    </p>
                </article>
            </div>

            <div
                v-if="removedServices.length"
                class="border-t border-dashed p-3 text-sm"
                data-testid="removed-services"
            >
                <h3
                    class="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Removed from the list
                </h3>
                <ul class="grid gap-1">
                    <li
                        v-for="line in removedServices"
                        :key="line.publicId"
                        class="flex items-center justify-between gap-2"
                    >
                        <span class="text-muted-foreground line-through">{{
                            line.name
                        }}</span>
                        <Button
                            v-if="dispensary.can.update"
                            size="sm"
                            variant="outline"
                            :disabled="busy"
                            data-action="restore-service"
                            @click="restoreService(line)"
                            >Put back</Button
                        >
                    </li>
                </ul>
            </div>

            <div
                v-if="dispensary.can.update"
                class="border-t p-3 text-sm"
                data-testid="add-service"
            >
                <h3
                    class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Add a service
                </h3>
                <div class="flex gap-2">
                    <input
                        v-model="serviceTerm"
                        class="h-9 w-full max-w-sm rounded-md border px-2"
                        placeholder="Search service name or code"
                        aria-label="Search service"
                        data-field="service-search"
                        @keydown.enter.prevent="searchServices"
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="serviceSearching || busy"
                        @click="searchServices"
                        ><Plus class="size-4" />Search</Button
                    >
                </div>
                <ul
                    v-if="!chosenService && serviceResults.length"
                    class="mt-2 divide-y rounded-md border"
                >
                    <li
                        v-for="service in serviceResults"
                        :key="service.publicId"
                        class="flex items-center justify-between gap-2 px-2 py-1.5"
                    >
                        <span
                            >{{ service.name }}
                            <span class="text-xs text-muted-foreground">{{
                                service.code
                            }}</span></span
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            data-action="choose-service"
                            @click="chosenService = service"
                            >Choose</Button
                        >
                    </li>
                </ul>
                <div
                    v-if="chosenService"
                    class="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
                >
                    <p class="text-sm font-medium sm:col-span-4">
                        {{ chosenService.name }}
                    </p>
                    <label
                        >Performed ({{ chosenService.unit }})<input
                            v-model="serviceAdd.quantity"
                            :class="inputClass"
                            inputmode="decimal"
                            data-field="add-service-quantity"
                    /></label>
                    <label class="sm:col-span-3"
                        >Instruction<input
                            v-model="serviceAdd.instruction"
                            :class="inputClass"
                            maxlength="2000"
                    /></label>
                    <div class="flex gap-2 sm:col-span-4">
                        <Button
                            size="sm"
                            :disabled="busy"
                            data-action="add-service"
                            @click="addService"
                            >Add to list</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="busy"
                            @click="chosenService = null"
                            >Cancel</Button
                        >
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>
