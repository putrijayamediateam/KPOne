<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Eye,
    LoaderCircle,
    Megaphone,
    Pencil,
    Pill,
    Plus,
    Printer,
    RotateCcw,
    ScanLine,
    Trash2,
    TriangleAlert,
    Undo2,
} from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import DoctorCheckActions from '@/components/dispensary/DoctorCheckActions.vue';
import PackQuantityField from '@/components/dispensary/PackQuantityField.vue';
import SupervisorCheckNote from '@/components/dispensary/SupervisorCheckNote.vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import ReadOnlyBanner from '@/components/ReadOnlyBanner.vue';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import {
    displayQuantity,
    packChoiceLabel,
    packCount,
    packsOnHand,
    packsToBase,
    parsePacks,
    usablePackConversion,
} from '@/lib/dispensary-packs';
import type { PackConversion } from '@/lib/dispensary-packs';
import {
    boxBatchCheck,
    checkScan,
    checkedCount,
    isChecked,
    keepCurrentMarks,
    scanAddOutcome,
    scannableCount,
} from '@/lib/dispensary-scan';
import type {
    BoxBatchCheck,
    ScanCheckLine,
    ScanMarks,
} from '@/lib/dispensary-scan';
import { boxBatchHint } from '@/lib/gs1-batch';
import type { BoxBatchHint } from '@/lib/gs1-batch';
import { formatInventoryReason } from '@/lib/inventory-picker';
import { formatDate, formatStatusLabel } from '@/lib/presentation';
import type { Gs1Read, ScannedSku } from '@/lib/scan';
import AllergyMatchWarning from '@/pages/Dispensary/Partials/AllergyMatchWarning.vue';
import OtcAllergyRecordForm from '@/pages/Dispensary/Partials/OtcAllergyRecordForm.vue';
import type { DispensarySupervisorCheck } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dispensary', href: '/registration' }] },
});

type AllergyMatch = { publicId: string; allergen: string };
type Availability = {
    locationPublicId: string;
    locationName: string;
    batchPublicId: string;
    batchNumber: string;
    expiryDate: string;
    quantity: string;
    held?: boolean;
    holdReason?: string;
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
    // DSP-24: whole packs on a saved line ("2 bottles (120 ml)"), and the SKU's current checked conversion.
    packsDispensed?: number | null;
    packText?: string | null;
    packConversion?: PackConversion | null;
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
    // DSP-20: the batches saved on the line, compared with a scanned GS1 box.
    allocations?: {
        batchPublicId: string | null;
        batchNumber?: string | null;
        expiryDate?: string | null;
    }[];
    exception: {
        public_id: string;
        status: string;
        proposed_quantity_dispensed: string;
    } | null;
    allergyMatches?: AllergyMatch[];
    // DSP-5: matches an allergy recorded after the line was saved; tick and save again before completing.
    allergyUnconfirmed?: boolean;
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
    canPrintLabels?: boolean;
    // DSP-26: the Resident Doctor's view-only page; the label preview without printing; the back link.
    viewOnly?: boolean;
    canPreviewLabels?: boolean;
    backLink?: { label: string; href: string };
    labelLimits?: Record<string, number>;
    publicId: string;
    status: 'pending' | 'dispensing' | 'awaiting_doctor_check';
    lockVersion: number;
    receivedAt: string;
    patient: { patientNumber: string; name: string };
    visit: { visitNumber: string };
    doctor: string | null;
    caseType?: 'consultation' | 'otc';
    otc?: { allergyStatement: string | null; allergyConfirmed: boolean } | null;
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
        addOtcAllergy?: boolean;
    };
    tvCall?: {
        canCall: boolean;
        rooms: Array<{ id: number; name: string }>;
    };
    // DSP-23: null on an OTC case.
    doctorCheck?: {
        required: boolean;
        waiting: boolean;
        sentAt: string | null;
        sentBy: string | null;
        sentBack: {
            reason: string;
            note: string | null;
            by: string | null;
            at: string | null;
            checkedAs: 'doctor' | 'supervisor';
        } | null;
        canCheck: boolean;
        preparedByYou: boolean;
        supervisorCheck: DispensarySupervisorCheck | null;
    } | null;
};
type Medicine = {
    publicId: string;
    name: string;
    code: string;
    strength: string | null;
    unit: string;
    sku: { publicId: string; unit: string };
    packConversion?: PackConversion | null;
    availability: Availability[];
    allergyMatches?: AllergyMatch[];
};
type LineForm = {
    quantity: string;
    // DSP-24: the packs typed when the CA chose "Bottles"; the quantity then holds the matching ml.
    packs: string;
    packMode: 'base' | 'packs';
    dosage: string;
    frequency: string;
    duration: string;
    route: string;
    instruction: string;
    precaution: string;
    availabilityIndex: number;
    fefoReason: string;
};

const props = defineProps<{ dispensary: Page }>();
const busy = ref(false);
const actionError = ref<string | null>(null);
const page = usePage();
const branchId = () => page.props.branchContext?.active?.id ?? 0;

// A line the CA has not saved yet shows the doctor's quantity; a saved line shows the CA's.
const formFor = (item: Item): LineForm => ({
    quantity: item.quantityDispensed ?? item.quantityOrdered,
    packs: item.packsDispensed ? String(item.packsDispensed) : '',
    packMode: item.packsDispensed ? 'packs' : 'base',
    dosage: item.dosage ?? '',
    frequency: item.frequency ?? '',
    duration: item.duration ?? '',
    route: item.route ?? '',
    instruction: item.instruction ?? '',
    precaution: item.precaution ?? '',
    availabilityIndex: Math.max(
        0,
        item.availability.findIndex((stock) => !stock.held),
    ),
    fefoReason: '',
});
const forms = reactive<Record<string, LineForm>>({});
const baseLock = reactive<Record<string, number>>({});
// DSP-1: the CA's "I have checked with the patient/doctor" tick, per line. It is keyed by the exact
// allergies shown when ticked, so a newly matched allergy is never covered by an older tick. It is
// sent with one save only; the server asks again on every later save of a matching line.
const allergyChecks = reactive<Record<string, boolean>>({});
const matchesFor = (item: { allergyMatches?: AllergyMatch[] }) =>
    item.allergyMatches ?? [];
const checkKey = (item: Item) =>
    `${item.publicId}:${matchesFor(item)
        .map((match) => match.publicId)
        .join(',')}`;
const needsAllergyCheck = (item: Item) =>
    matchesFor(item).length > 0 && !allergyChecks[checkKey(item)];
const allergyPayload = (matches: AllergyMatch[], checked: boolean) =>
    matches.length && checked
        ? {
              allergy_confirmed_record_ids: matches.map(
                  (match) => match.publicId,
              ),
          }
        : {};
const isDirty = (item: Item): boolean => {
    const form = forms[item.publicId];
    const saved = formFor(item);

    return (
        !!form &&
        (
            [
                'quantity',
                'packs',
                'dosage',
                'frequency',
                'duration',
                'route',
                'instruction',
                'precaution',
                'fefoReason',
                'availabilityIndex',
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
// DSP-11: scan to check (screen only; nothing is saved). A line stays checked only while its SKU is the one scanned.
const scanMarks = ref<ScanMarks>({});
const toScanLine = (item: Item): ScanCheckLine => ({
    publicId: item.publicId,
    name: item.name,
    strength: item.strength,
    skuPublicId: item.sku?.publicId ?? null,
});
const scanLines = computed(() => visibleItems.value.map(toScanLine));
const removedScanLines = computed(() => removedItems.value.map(toScanLine));
const scanTotal = computed(() => scannableCount(scanLines.value));
const scanChecked = computed(() =>
    checkedCount(scanLines.value, scanMarks.value),
);
const isScanChecked = (item: Item): boolean =>
    isChecked(toScanLine(item), scanMarks.value);
// DSP-20: the GS1 read that checked each line (null for a plain barcode), compared live with the saved batches, so a
// later save of another batch updates the note. Screen only; it goes when the line loses its mark.
const scanBoxReads = ref<Record<string, Gs1Read | null>>({});
const scanBatchAnnouncement = ref('');
const scanBatch = (item: Item): BoxBatchCheck | null =>
    isScanChecked(item)
        ? boxBatchCheck(
              scanBoxReads.value[item.publicId] ?? null,
              item.allocations ?? [],
          )
        : null;
const scanOutcome = (sku: ScannedSku) =>
    checkScan(sku, scanLines.value, removedScanLines.value, scanMarks.value);
const acceptScan = (sku: ScannedSku): string | null => {
    // DSP-17: any new scan clears the batch hint, including one refused here (Hakim).
    clearLineHint();
    scanBatchAnnouncement.value = '';
    const outcome = scanOutcome(sku);

    return outcome.kind === 'removed' || outcome.kind === 'not_on_case'
        ? outcome.message
        : null;
};
const describeScan = (sku: ScannedSku): string => scanOutcome(sku).message;
// DSP-17: a scan that matches a saved line points at that line's batch dropdown. Barcodes carry no batch, so the CA
// still picks the batch printed on the box: nothing is selected, saved or completed here. Focus stays in the scan box,
// which keeps itself ready for the next box (STK-9's ScanInput).
const batchHint = ref<string | null>(null);
const BATCH_HINT = 'Pick the batch printed on the box.';
// DSP-18: a GS1 code also says which batch and expiry are on the box. The hint names them and marks matching options,
// and still never selects one: the CA picks the batch, as in DSP-17.
const boxRead = ref<Gs1Read | null>(null);
// One hint for the target the scan pointed at, computed once per change rather than per option (Hakim).
const activeBoxHint = computed((): BoxBatchHint | null => {
    const target = batchHint.value;

    if (target === null) {
        return null;
    }

    const availability =
        target === 'add'
            ? (chosen.value?.availability ?? [])
            : (visibleItems.value.find((item) => item.publicId === target)
                  ?.availability ?? []);
    const selected =
        target === 'add'
            ? addForm.availabilityIndex
            : forms[target]?.availabilityIndex;
    const hint = boxBatchHint(
        boxRead.value,
        availability,
        formatDate,
        BATCH_HINT,
    );

    // Information only (Daniel): the dropdown still shows the earlier choice, so say when it is not the box's batch.
    // A selected option with the box's batch number but another expiry is the same batch: no clause there, unless an
    // option matches the box exactly (batch and expiry), in which case only those count as the box's batch (Hakim).
    const boxs = hint.matches.length > 0 ? hint.matches : hint.sameBatch;

    return hint.named && (selected === undefined || !boxs.includes(selected))
        ? {
              ...hint,
              message: `${hint.message} The selected batch is not the one on the box.`,
          }
        : hint;
});
const boxHint = (target: string): BoxBatchHint =>
    (batchHint.value === target ? activeBoxHint.value : null) ?? {
        message: BATCH_HINT,
        matches: [],
        warn: false,
        named: false,
        sameBatch: [],
    };
const matchesBox = (target: string, index: number): boolean =>
    batchHint.value === target && boxHint(target).matches.includes(index);
const pointAtBatch = async (publicId: string) => {
    batchHint.value = publicId;
    await nextTick();
    const select = document.querySelector<HTMLSelectElement>(
        `[data-batch-select="${publicId}"]`,
    );

    if (select === null) {
        return;
    }

    const reduced = window.matchMedia?.(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    select.scrollIntoView({
        block: 'center',
        behavior: reduced ? 'auto' : 'smooth',
    });
};
// A typed or scanned code (Enter in the scan box) starts a new scan, even one that turns out unknown.
const clearLineHint = () => {
    if (batchHint.value !== null && batchHint.value !== 'add') {
        batchHint.value = null;
    }
};
// A hinted line that is removed (or otherwise no longer shown) loses its hint, so a restore never brings it back.
watch(visibleItems, (items) => {
    if (
        batchHint.value !== null &&
        batchHint.value !== 'add' &&
        !items.some((item) => item.publicId === batchHint.value)
    ) {
        batchHint.value = null;
    }
});
const markScanned = (sku: ScannedSku, gs1: Gs1Read | null = null) => {
    const outcome = scanOutcome(sku);
    batchHint.value = null;
    boxRead.value = gs1;

    if (outcome.kind === 'match' || outcome.kind === 'already') {
        scanMarks.value = outcome.marks;
        const matched = visibleItems.value.filter(
            (item) => item.sku?.publicId === sku.public_id,
        );
        scanBoxReads.value = {
            ...scanBoxReads.value,
            ...Object.fromEntries(matched.map((item) => [item.publicId, gs1])),
        };
        // Spoken once per scan; the scan box already says which line matched. Set after the next render, so an identical
        // repeat scan still changes the text (acceptScan cleared it) and is spoken again (Hakim).
        const said = matched
            .map((item) => scanBatch(item)?.message)
            .filter((message): message is string => !!message)
            .join(' ');
        void nextTick(() => {
            scanBatchAnnouncement.value = said;
        });
        const line = visibleItems.value.find(
            (item) =>
                item.sku?.publicId === sku.public_id &&
                forms[item.publicId] !== undefined &&
                item.availability.length > 0,
        );

        if (line) {
            void pointAtBatch(line.publicId);
        }
    }
};
const clearBatchHint = (publicId: string) => {
    if (batchHint.value === publicId) {
        batchHint.value = null;
    }
};
// A removed, restored or changed line loses its mark, so it must be scanned again.
watch(scanLines, (lines) => {
    scanMarks.value = keepCurrentMarks(lines, scanMarks.value);
    scanBoxReads.value = Object.fromEntries(
        Object.entries(scanBoxReads.value).filter(
            ([publicId]) => scanMarks.value[publicId] !== undefined,
        ),
    );
});
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
    fefoReason = '',
) =>
    availability && skuPublicId
        ? [
              {
                  location_public_id: availability.locationPublicId,
                  sku_public_id: skuPublicId,
                  batch_public_id: availability.batchPublicId,
                  quantity,
                  ...(fefoReason ? { fefo_exception_reason: fefoReason } : {}),
              },
          ]
        : [];
// DSP-24: with "Bottles" chosen, the packs and the conversion version the CA saw go with the ml; the server refuses a
// save when the bottle size changed meanwhile.
const packPayload = (
    form: LineForm,
    conversion: PackConversion | null | undefined,
    unit: string,
) => {
    const usable = usablePackConversion(conversion, unit);
    const packs = parsePacks(form.packs);

    return form.packMode === 'packs' && usable && packs !== null
        ? {
              packs,
              pack_conversion_public_id: usable.publicId,
              pack_conversion_version: usable.version,
          }
        : {};
};
// DSP-24 (Daniel): Save and Add stay off while "Bottles" holds something other than 1 to 99 whole bottles.
const packsInvalid = (form: LineForm | undefined): boolean =>
    form?.packMode === 'packs' && parsePacks(form.packs) === null;
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
            ...packPayload(form, item.packConversion, item.unit),
            allocations: allocationFor(
                item.availability[form.availabilityIndex],
                item.sku?.publicId,
                form.quantity,
                form.fefoReason,
            ),
            ...allergyPayload(
                matchesFor(item),
                allergyChecks[checkKey(item)] === true,
            ),
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onSuccess: () => {
                allergyChecks[checkKey(item)] = false;
                openCards[item.publicId] = false;
                clearBatchHint(item.publicId);
                done?.();
            },
            onFinish: () => (busy.value = false),
        },
    );
};
const saveAll = () => {
    const unticked = unsaved.value.find((item) => needsAllergyCheck(item));

    if (unticked) {
        actionError.value = `${unticked.name} may match a recorded allergy. Tick "I have checked with the patient/doctor" on that line first.`;

        return;
    }

    // DSP-24 (Daniel): check every line first, so an invalid bottles number never leaves a partly saved set.
    const badPacks = unsaved.value.find((item) =>
        packsInvalid(forms[item.publicId]),
    );

    if (badPacks) {
        const packs = packChoiceLabel(
            badPacks.packConversion?.packUnit ?? 'pack',
        ).toLowerCase();
        actionError.value = `${badPacks.name}: enter whole ${packs}, 1 to 99, before saving. Nothing was saved.`;

        return;
    }

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
    const first = item.availability.find((stock) => !stock.held);
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
            ...allergyPayload(
                matchesFor(item),
                allergyChecks[checkKey(item)] === true,
            ),
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onSuccess: () => (allergyChecks[checkKey(item)] = false),
            onFinish: () => (busy.value = false),
        },
    );
};

// Add a medicine the doctor did not order.
const searchTerm = ref('');
const results = ref<Medicine[]>([]);
const searching = ref(false);
const chosen = ref<Medicine | null>(null);
const addAllergyCheck = ref(false);
// DD-26: the quantity and expiry come first, so a narrow dropdown never cuts them off. Quantities drop trailing
// zeros only (12.5 stays 12.5); the unit is the SKU's stock unit as stored. Dates use the screen format (30 Oct 2027).
const stockAmount = (quantity: string, unit: string): string => {
    const text = quantity.includes('.')
        ? quantity.replace(/0+$/, '').replace(/\.$/, '')
        : quantity;

    return `${text} ${unit}`.trim();
};
// DSP-24: "180 ml (3 bottles) on hand" when the stock divides evenly into whole bottles.
const batchOptionText = (
    stock: Availability,
    unit: string,
    conversion: PackConversion | null = null,
): string =>
    `${stock.held ? `Held: ${formatInventoryReason(stock.holdReason)} · ` : ''}${stockAmount(stock.quantity, unit)}${packsOnHand(stock.quantity, conversion)} on hand · exp ${formatDate(stock.expiryDate)} · ${stock.batchNumber} · ${stock.locationName}`;
const selectedBatchText = (
    stock: Availability | undefined,
    unit: string,
    conversion: PackConversion | null = null,
): string =>
    stock === undefined
        ? 'No batch selected.'
        : stock.held
          ? 'No usable batch: every batch here is on hold.'
          : conversion
            ? `Selected: ${batchOptionText(stock, unit, conversion)}`
            : `Selected: ${batchOptionText(stock, unit)}`;
const addForm = reactive<LineForm>({
    quantity: '1.000',
    packs: '',
    packMode: 'base',
    dosage: '',
    frequency: '',
    duration: '',
    route: '',
    instruction: '',
    precaution: '',
    availabilityIndex: 0,
    fefoReason: '',
});
const csrf = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
const searchMedicines = async () => {
    clearScanAdd();

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
    clearBatchHint('add');
    addForm.packs = '';
    addForm.packMode = 'base';

    if (addForm.quantity === '') {
        addForm.quantity = '1.000';
    }

    addAllergyCheck.value = false;
    addForm.availabilityIndex = Math.max(
        0,
        medicine.availability.findIndex((stock) => !stock.held),
    );
    addForm.fefoReason = '';
};
// DSP-12: scan to find a medicine to add (find only; the CA still sets quantity and batch and presses Add).
const scanAddOpen = ref(false);
const scanAddMessage = ref('');
const scanAddAllergy = ref(false);
// Only the newest scan's answer counts; an earlier, slower lookup is ignored (Hakim, DSP-12).
let scanAddGeneration = 0;
const clearScanAdd = () => {
    scanAddMessage.value = '';
    scanAddAllergy.value = false;
};
const focusScanAddButton = async () => {
    await nextTick();
    document
        .querySelector<HTMLButtonElement>('[data-action="scan-to-add"]')
        ?.focus();
};
const openScanAdd = async () => {
    scanAddOpen.value = !scanAddOpen.value;

    if (scanAddOpen.value) {
        await nextTick();
        document.getElementById('scan-dispensary-add')?.focus();
    }
};
const closeScanAdd = () => {
    scanAddOpen.value = false;
    void focusScanAddButton();
};
// Typed directions on the medicine being added are never replaced by a scan.
const addFormHasTyping = () =>
    chosen.value !== null &&
    [
        addForm.dosage,
        addForm.frequency,
        addForm.duration,
        addForm.route,
        addForm.instruction,
        addForm.precaution,
    ].some((value) => value.trim() !== '');
const pickMedicine = (medicine: Medicine) => {
    clearScanAdd();
    chooseMedicine(medicine);
};
const scanToAdd = async (sku: ScannedSku, gs1: Gs1Read | null = null) => {
    if (busy.value) {
        scanAddAllergy.value = false;
        scanAddMessage.value = 'Wait for the save to finish, then scan again.';

        return;
    }

    if (addFormHasTyping()) {
        scanAddAllergy.value = false;
        scanAddMessage.value =
            'Finish or cancel the medicine you are adding first, then scan again.';

        return;
    }

    clearBatchHint('add');
    const generation = ++scanAddGeneration;
    scanAddAllergy.value = false;
    scanAddMessage.value = 'Finding the medicine…';
    searching.value = true;

    try {
        const response = await fetch(
            `/dispensary/${props.dispensary.publicId}/medicines/by-sku`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ sku: sku.public_id }),
            },
        );
        const medicines = response.ok
            ? ((await response.json()) as { data: Medicine[] }).data
            : null;

        if (generation !== scanAddGeneration || busy.value) {
            return;
        }

        if (medicines === null) {
            scanAddMessage.value = 'Lookup failed. Try again.';

            return;
        }

        const outcome = scanAddOutcome(medicines, sku);

        if (outcome.kind === 'choose') {
            results.value = [outcome.medicine];
            chooseMedicine(outcome.medicine);
            batchHint.value = 'add';
            boxRead.value = gs1;
            scanAddMessage.value = outcome.message;
            scanAddOpen.value = false;
            await nextTick();
            document
                .querySelector<HTMLInputElement>('[data-field="add-quantity"]')
                ?.focus();
        } else if (outcome.kind === 'list') {
            chosen.value = null;
            results.value = outcome.medicines;
            scanAddMessage.value = outcome.message;
            scanAddAllergy.value = outcome.allergy;
            await nextTick();
            document
                .querySelector<HTMLButtonElement>(
                    '[data-action="choose-medicine"]:not([disabled])',
                )
                ?.focus();
        } else {
            results.value = [];
            scanAddMessage.value = outcome.message;
        }
    } catch {
        if (generation === scanAddGeneration) {
            scanAddMessage.value = 'Lookup failed. Try again.';
        }
    } finally {
        if (generation === scanAddGeneration) {
            searching.value = false;
        }
    }
};
const refreshChosen = async (publicId: string) => {
    await searchMedicines();
    chosen.value =
        results.value.find((medicine) => medicine.publicId === publicId) ??
        null;
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
            ...packPayload(addForm, medicine.packConversion, medicine.unit),
            allocations: allocationFor(
                medicine.availability[addForm.availabilityIndex],
                medicine.sku.publicId,
                addForm.quantity,
                addForm.fefoReason,
            ),
            ...allergyPayload(matchesFor(medicine), addAllergyCheck.value),
        },
        {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                showActionError(errors);

                // A newly recorded allergy may now match: reload the matches and ask for a fresh tick.
                if (errors.allergy_confirmation) {
                    addAllergyCheck.value = false;
                    void refreshChosen(medicine.publicId);
                }
            },
            onSuccess: () => {
                chosen.value = null;
                clearBatchHint('add');
                addAllergyCheck.value = false;
                results.value = [];
                searchTerm.value = '';
                Object.assign(addForm, {
                    quantity: '1.000',
                    packs: '',
                    packMode: 'base',
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

// OTC: no doctor reviewed this order, so the CA records what the patient said about allergies first.
const isOtc = computed(() => props.dispensary.caseType === 'otc');
// DSP-5: no choice until the CA asks; "has an allergy" needs the allergy itself on the profile first.
const otcAllergyChoice = ref<'' | 'none' | 'has_allergy'>(
    props.dispensary.otc?.allergyStatement === 'has_allergy' ||
        props.dispensary.otc?.allergyStatement === 'none'
        ? props.dispensary.otc.allergyStatement
        : '',
);
const otcHasRecordedAllergy = computed(
    () => props.dispensary.allergySafety.allergies.length > 0,
);
const otcAddingAnother = ref(false);
const showOtcAllergyForm = computed(
    () =>
        otcAllergyChoice.value === 'has_allergy' &&
        props.dispensary.can.addOtcAllergy === true &&
        (!otcHasRecordedAllergy.value || otcAddingAnother.value),
);
const otcConfirmBlocked = computed(
    () =>
        otcAllergyChoice.value === '' ||
        (otcAllergyChoice.value === 'has_allergy' &&
            !otcHasRecordedAllergy.value),
);
const otcAllergyConfirmed = computed(
    () => props.dispensary.otc?.allergyConfirmed === true,
);
const confirmOtcAllergy = () =>
    caseAction('otc-allergy', { allergy_statement: otcAllergyChoice.value });

// The CA's own verification, after the doctor's, before stock moves.
const confirming = ref(false);
// Daniel's review of DSP-5: say next to the greyed-out button what is still needed.
const completeBlockedReason = computed(() => {
    if (unsaved.value.length > 0 || unsavedServices.value.length > 0) {
        return 'Save the lines that are not saved yet, then complete.';
    }

    if (props.dispensary.items.some((item) => item.allergyUnconfirmed)) {
        return 'Before completing, save the line with the allergy box ticked.';
    }

    if (isOtc.value && !otcAllergyConfirmed.value) {
        return 'Confirm what the patient reports about allergies, then complete.';
    }

    return '';
});
// DSP-23: with the branch's doctor's check on, Complete becomes "Send to doctor for check"; the server reads the
// switch again under lock and refuses a stale page.
const checkRequired = computed(
    () => !isOtc.value && props.dispensary.doctorCheck?.required === true,
);
const waitingForCheck = computed(
    () => props.dispensary.doctorCheck?.waiting === true,
);
const sendBackReasonLabels: Record<string, string> = {
    wrong_medicine: 'Wrong medicine',
    wrong_quantity: 'Wrong quantity',
    wrong_batch: 'Wrong batch',
    label_problem: 'Label problem',
    other: 'Other',
};
const completeCase = () => {
    caseAction('complete');
};
const sendForCheck = () => {
    caseAction('send-for-check', { retry_key: crypto.randomUUID() });
};
// DSP-CARD-1: the medicine card (owner's demo, 27 Nov). Presentation only: Save line, Remove and the inputs call
// the same endpoints with the same payloads as before.
// One direction line, as the demo writes it: "1 tablet, 3 times a day, before food · 5 days".
type Directions = {
    dosage?: string | null;
    frequency?: string | null;
    duration?: string | null;
    instruction?: string | null;
};
const directionLine = (directions: Directions): string => {
    const how = [
        directions.dosage,
        directions.frequency,
        directions.instruction,
    ]
        .map((part) => (part ?? '').trim())
        .filter(Boolean)
        .join(', ');
    const days = (directions.duration ?? '').trim();

    return [how, days].filter(Boolean).join(' · ') || 'No directions recorded';
};
// While the CA types, the card's direction line follows the inputs; otherwise it shows the saved line.
const livesInForm = (item: Item): boolean =>
    props.dispensary.can.update &&
    forms[item.publicId] !== undefined &&
    (item.status === 'pending' || isDirty(item));
const givenDirections = (item: Item): Directions =>
    livesInForm(item) ? forms[item.publicId] : item;
const givenQuantity = (item: Item): string => {
    if (livesInForm(item)) {
        const form = forms[item.publicId];
        const packs = parsePacks(form.packs);
        const conversion = usablePackConversion(item.packConversion, item.unit);

        // Hakim: while editing in packs, say it the way the saved line will ("2 bottles (120 ml)").
        return form.packMode === 'packs' && packs !== null && conversion
            ? `${packCount(packs, conversion.packUnit)} (${displayQuantity(packsToBase(packs, conversion))} ${item.unit})`
            : `${displayQuantity(form.quantity)} ${item.unit}`;
    }

    return (
        item.packText ??
        `${displayQuantity(item.quantityDispensed ?? item.quantityOrdered)} ${item.unit}`
    );
};
// DD-125: a short batch line on the card ("Batch DM-0207 · exp 3 Mar 2027 · 60 tablet on hand"); the full option text
// stays in the dropdown and its "Selected:" line while editing.
const batchLine = (item: Item): string | null => {
    const unit = item.sku?.unit ?? item.unit;
    const onHand = (stock: Availability | undefined) =>
        stock ? ` · ${stockAmount(stock.quantity, unit)} on hand` : '';

    if (livesInForm(item)) {
        const stock = item.availability[forms[item.publicId].availabilityIndex];

        return stock && !stock.held
            ? `Batch ${stock.batchNumber} · exp ${formatDate(stock.expiryDate)}${onHand(stock)}`
            : null;
    }

    const saved = (item.allocations ?? []).filter(
        (allocation) => allocation.batchNumber,
    );

    return saved.length
        ? saved
              .map(
                  (allocation) =>
                      `Batch ${allocation.batchNumber}${allocation.expiryDate ? ` · exp ${formatDate(allocation.expiryDate)}` : ''}${onHand(
                          item.availability.find(
                              (stock) =>
                                  stock.batchPublicId ===
                                  allocation.batchPublicId,
                          ),
                      )}`,
              )
              .join('; ')
        : null;
};
// A card opens on Edit and stays open while it holds unsaved changes, an allergy re-save or a scan's batch hint, so
// nothing the CA must act on is ever folded away.
const openCards = reactive<Record<string, boolean>>({});
const mustStayOpen = (item: Item): boolean =>
    isDirty(item) ||
    item.allergyUnconfirmed === true ||
    batchHint.value === item.publicId;
const isEditing = (item: Item): boolean =>
    props.dispensary.can.update &&
    forms[item.publicId] !== undefined &&
    (openCards[item.publicId] === true || mustStayOpen(item));
const openCard = async (item: Item) => {
    openCards[item.publicId] = true;
    await nextTick();
    document
        .querySelector<HTMLInputElement>(
            `#line-edit-${item.publicId} [data-field="dosage"]`,
        )
        ?.focus();
};
// Closing hands focus back to the card's Edit button, so a keyboard user keeps their place.
const closeCard = async (item: Item) => {
    openCards[item.publicId] = false;
    await nextTick();
    document
        .querySelector<HTMLElement>(
            `[data-action="edit-line"][aria-controls="line-edit-${item.publicId}"]`,
        )
        ?.focus();
};
// Undo puts the inputs back to the saved line (or the doctor's order on an unsaved line). Nothing is sent.
const undoCard = (item: Item) => {
    forms[item.publicId] = formFor(item);
    clearBatchHint(item.publicId);

    if (item.allergyUnconfirmed === true) {
        // The card stays open for the allergy re-save, so focus goes to its first input (Hakim).
        void openCard(item);

        return;
    }

    void closeCard(item);
};
const canPrintLine = (item: Item): boolean =>
    item.status === 'dispensed' &&
    props.dispensary.canPrintLabels === true &&
    !unsaved.value.length &&
    !unsavedServices.value.length &&
    !isDirty(item);
const changeLabel = (item: Item) =>
    ({
        edited: 'Edited by CA',
        added: 'Added by CA',
        removed: 'Removed',
        unchanged: '',
    })[item.changeState];
const inputClass =
    'mt-1 h-9 w-full rounded-md border border-input bg-field-fill px-3 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
// DSP-17: blue scan hint only (not the error look); replaces the focus ring while shown.
const batchHintClass =
    'border-primary ring-2 ring-ring ring-offset-2 ring-offset-background focus-visible:ring-ring';

// Plain words for an earlier partial dispense, never the stored code (Daniel, guide section 15).
const exceptionStatusLabels: Record<string, string> = {
    awaiting_acknowledgement: 'waiting for the doctor to acknowledge',
    acknowledged: 'acknowledged by the doctor',
    superseded: 'replaced by a later change',
};
</script>

<template>
    <Head title="Dispensary" />
    <main
        class="mx-auto w-full max-w-[1500px] px-4 py-5 text-sm lg:px-6 lg:py-6 max-lg:[&_[data-slot=button]]:min-h-11 lg:[&_[data-slot=button]]:min-h-9 max-lg:[&_[data-slot=select-trigger]]:min-h-11 lg:[&_[data-slot=select-trigger]]:min-h-9 max-lg:[&_input:not([type=radio]):not([type=checkbox])]:min-h-11 max-lg:[&_select]:min-h-11 max-lg:[&_textarea]:min-h-11"
    >
        <!-- DD-25: the page title names the task; the patient card below is the one place that names the patient. -->
        <!-- DSP-26: the Resident Doctor's view-only header; the CA's header below is unchanged. -->
        <PageHeader
            v-if="dispensary.viewOnly"
            class="mb-4 border-b pb-3"
            title="Dispensary"
            description="The medicine the CA prepared for this visit."
        >
            <template
                v-if="visibleItems.length && dispensary.canPreviewLabels"
                #actions
            >
                <Button as-child variant="outline" size="sm">
                    <a
                        :href="`/dispensary/${dispensary.publicId}/labels`"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-testid="view-labels"
                    >
                        <Eye class="size-4" />View labels
                        <span class="sr-only">(opens in a new tab)</span>
                    </a>
                </Button>
            </template>
        </PageHeader>
        <PageHeader
            v-else
            class="mb-4 border-b pb-3"
            title="Dispensary"
            description="Check each line, save it, then complete."
        >
            <template
                v-if="
                    visibleItems.length &&
                    dispensary.canPrintLabels &&
                    !unsaved.length &&
                    !unsavedServices.length
                "
                #actions
            >
                <Button as-child variant="outline" size="sm">
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
            </template>
        </PageHeader>

        <!-- DSP-26 (Daniel): the Resident Doctor sees one muted view-only line, with the way back. -->
        <ReadOnlyBanner
            v-if="dispensary.viewOnly"
            class="mb-4"
            message="View only. You cannot change this case."
        >
            <Button
                v-if="dispensary.backLink"
                as-child
                variant="outline"
                size="sm"
            >
                <Link :href="dispensary.backLink.href"
                    ><ArrowLeft class="size-4" />{{
                        dispensary.backLink.label
                    }}</Link
                >
            </Button>
        </ReadOnlyBanner>
        <!-- DSP-23, Daniel's note 5: the waiting banner, with the hand-over instruction in a warning box. -->
        <section
            v-if="waitingForCheck"
            class="mb-4 rounded-xl border bg-card-quiet p-4 text-sm"
            role="status"
            data-testid="doctor-check-waiting"
        >
            <p class="font-medium">
                Waiting for {{ dispensary.doctor ?? 'the doctor' }}'s check
                <template v-if="dispensary.doctorCheck?.sentAt">
                    since {{ dispensary.doctorCheck.sentAt }}</template
                >
            </p>
            <p class="text-xs text-muted-foreground">
                Sent{{
                    dispensary.doctorCheck?.sentBy
                        ? ` by ${dispensary.doctorCheck.sentBy}`
                        : ''
                }}.<template v-if="dispensary.viewOnly">
                    The doctor checks it on the consultation page.</template
                ><template v-else>
                    The case can't be changed while it waits. You can still
                    print labels and call the patient.</template
                >
            </p>
            <p
                v-if="!dispensary.viewOnly"
                class="mt-2 flex items-start gap-2 rounded-lg border border-warning/25 bg-warning-wash px-3 py-2 text-warning-strong"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                Do not hand over the medicine until the doctor has checked it.
            </p>
        </section>
        <!-- DSP-23, Daniel's note 4: the doctor's reason, in a warning box, not red. -->
        <section
            v-if="dispensary.doctorCheck?.sentBack"
            class="mb-4 flex items-start gap-2 rounded-xl border border-warning/25 bg-warning-wash p-4 text-sm text-warning-strong"
            role="status"
            data-testid="doctor-check-sent-back"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div class="min-w-0">
                <p class="font-medium">
                    Sent back{{
                        dispensary.doctorCheck.sentBack.by
                            ? ` by ${dispensary.doctorCheck.sentBack.by}`
                            : ''
                    }}:
                    {{
                        sendBackReasonLabels[
                            dispensary.doctorCheck.sentBack.reason
                        ] ?? 'Other'
                    }}
                </p>
                <p
                    v-if="dispensary.doctorCheck.sentBack.note"
                    class="break-words"
                >
                    {{ dispensary.doctorCheck.sentBack.note }}
                </p>
                <p v-if="!dispensary.viewOnly" class="text-xs">
                    Correct the case, then send it to the doctor again.
                </p>
                <SupervisorCheckNote
                    v-if="
                        dispensary.doctorCheck.sentBack.checkedAs ===
                            'supervisor' &&
                        dispensary.doctorCheck.supervisorCheck
                    "
                    class="mt-2 text-foreground"
                    :check="dispensary.doctorCheck.supervisorCheck"
                />
            </div>
        </section>

        <div
            class="grid gap-4 xl:grid-cols-[minmax(0,280px)_minmax(0,1fr)_minmax(0,300px)]"
        >
            <aside class="flex flex-col gap-4" data-testid="dispensary-left">
                <section
                    class="rounded-xl border bg-card p-4 text-sm"
                    data-testid="dispensary-patient"
                >
                    <p class="text-[13px] font-semibold text-muted-foreground">
                        Patient
                    </p>
                    <h2 class="text-base font-semibold break-words">
                        {{ dispensary.patient.name }}
                    </h2>
                    <dl
                        class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs"
                    >
                        <dt class="text-muted-foreground">KP number</dt>
                        <dd>{{ dispensary.patient.patientNumber }}</dd>
                        <dt class="text-muted-foreground">Visit</dt>
                        <dd>{{ dispensary.visit.visitNumber }}</dd>
                        <dt class="text-muted-foreground">Doctor</dt>
                        <dd>
                            {{
                                isOtc
                                    ? 'OTC · no doctor'
                                    : (dispensary.doctor ?? 'No doctor')
                            }}
                        </dd>
                    </dl>
                </section>
                <div
                    v-if="isOtc"
                    class="rounded-xl border p-4 text-sm"
                    :class="
                        otcAllergyConfirmed
                            ? 'border-success/25 bg-success-wash'
                            : 'border-warning/25 bg-warning-wash'
                    "
                    data-testid="otc-allergy"
                >
                    <p class="font-medium">Ask the patient about allergies</p>
                    <p class="text-xs text-muted-foreground">
                        Record what the patient tells you. Saved allergies are
                        compared with medicine names and catalogue drug groups
                        by text only, so the check can miss one. Always ask.
                    </p>
                    <p
                        v-if="dispensary.allergySafety.allergies.length"
                        class="mt-2 text-xs font-medium"
                    >
                        Recorded allergies
                    </p>
                    <ul
                        v-if="dispensary.allergySafety.allergies.length"
                        class="mt-1 flex flex-col gap-1 text-xs"
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
                                ({{
                                    formatStatusLabel(allergy.severity)
                                }})</span
                            >
                        </li>
                    </ul>
                    <div v-if="dispensary.can.update" class="mt-2">
                        <fieldset class="flex flex-wrap gap-x-4">
                            <legend class="sr-only">
                                What the patient reports about allergies
                            </legend>
                            <label class="flex min-h-11 items-center gap-2">
                                <input
                                    v-model="otcAllergyChoice"
                                    type="radio"
                                    value="none"
                                    data-field="otc-allergy-none"
                                />
                                Patient reports no allergy
                            </label>
                            <label class="flex min-h-11 items-center gap-2">
                                <input
                                    v-model="otcAllergyChoice"
                                    type="radio"
                                    value="has_allergy"
                                    data-field="otc-allergy-has"
                                />
                                Patient reports an allergy
                            </label>
                        </fieldset>
                        <OtcAllergyRecordForm
                            v-if="showOtcAllergyForm"
                            :case-public-id="dispensary.publicId"
                            :branch-id="branchId()"
                            :case-lock-version="dispensary.lockVersion"
                            :profile-version="
                                dispensary.allergySafety.profileVersion
                            "
                            :optional="otcHasRecordedAllergy"
                            @cancel="otcAddingAnother = false"
                            @saved="otcAddingAnother = false"
                        />
                        <Button
                            v-else-if="
                                otcAllergyChoice === 'has_allergy' &&
                                dispensary.can.addOtcAllergy &&
                                otcHasRecordedAllergy
                            "
                            type="button"
                            variant="outline"
                            class="mt-2 h-11 w-full sm:h-9 sm:w-auto"
                            data-action="open-otc-allergy-form"
                            @click="otcAddingAnother = true"
                        >
                            Add another allergy
                        </Button>
                        <p
                            v-if="
                                otcAllergyChoice === 'has_allergy' &&
                                !otcHasRecordedAllergy &&
                                !dispensary.can.addOtcAllergy
                            "
                            class="mt-2 text-xs"
                            data-testid="otc-allergy-readonly"
                        >
                            No allergy recorded. You cannot add allergies here;
                            ask a supervisor to check your access.
                        </p>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-11 w-full sm:h-9 sm:w-auto"
                                :disabled="busy || otcConfirmBlocked"
                                data-action="confirm-otc-allergy"
                                @click="confirmOtcAllergy"
                            >
                                {{ otcAllergyConfirmed ? 'Update' : 'Confirm' }}
                            </Button>
                            <span
                                v-if="otcAllergyConfirmed"
                                class="text-xs text-success"
                                >Recorded</span
                            >
                            <span
                                v-else-if="
                                    otcAllergyChoice === 'has_allergy' &&
                                    !otcHasRecordedAllergy
                                "
                                class="text-xs text-muted-foreground"
                                >Add the allergy before you confirm.</span
                            >
                            <span
                                v-else-if="!otcAllergyChoice"
                                class="text-xs text-muted-foreground"
                                >Choose what the patient reports, then
                                confirm.</span
                            >
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="rounded-xl border p-4 text-sm"
                    :class="
                        dispensary.allergySafety.isCurrent &&
                        !dispensary.allergySafety.allergies.length
                            ? 'border-success/25 bg-success-wash'
                            : 'border-warning/25 bg-warning-wash'
                    "
                    data-testid="dispensary-allergies"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2"
                            ><TriangleAlert
                                v-if="
                                    !dispensary.allergySafety.isCurrent ||
                                    dispensary.allergySafety.allergies.length
                                "
                                class="size-4 shrink-0 text-warning-strong"
                                aria-hidden="true"
                            /><CheckCircle2
                                v-else
                                class="size-4 shrink-0 text-success"
                                aria-hidden="true"
                            /><span
                                >Allergy safety:
                                <strong>{{
                                    formatStatusLabel(
                                        dispensary.allergySafety.status,
                                    )
                                }}</strong></span
                            ></span
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
            </aside>
            <div
                class="flex min-w-0 flex-col gap-4"
                data-testid="dispensary-main"
            >
                <p
                    v-if="actionError"
                    role="alert"
                    class="rounded-md border border-destructive/25 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ actionError }}
                </p>
                <div
                    v-if="
                        dispensary.can.update &&
                        (unsaved.length || unsavedServices.length)
                    "
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warning/25 bg-warning-wash px-3 py-2 text-sm"
                    data-testid="dispensary-unsaved"
                >
                    <span
                        >{{ unsaved.length + unsavedServices.length }} line{{
                            unsaved.length + unsavedServices.length === 1
                                ? ''
                                : 's'
                        }}
                        not saved yet. Save each line to confirm it, then
                        complete.</span
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        class="dark:border-muted-foreground"
                        :disabled="busy"
                        @click="saveAll"
                        >Save all lines</Button
                    >
                </div>
                <ScanInput
                    v-if="dispensary.can.update && visibleItems.length"
                    context="dispensary"
                    refocus-key="dispensary-check"
                    :accept="acceptScan"
                    :describe="describeScan"
                    lookup-variant="outline"
                    data-testid="dispensary-scan"
                    @keydown.enter.capture="clearLineHint"
                    @found="markScanned"
                />
                <p
                    v-if="dispensary.can.update && visibleItems.length"
                    role="status"
                    class="sr-only"
                    data-testid="scan-batch-announcement"
                >
                    {{ scanBatchAnnouncement }}
                </p>
                <section class="divide-y rounded-xl border bg-card shadow-card">
                    <!-- DSP-CARD-1 (owner's demo, 27 Nov): one card per medicine. The direction line, the doctor's order beside
                         what is given and a short batch line are always shown; the inputs open only while editing the card. -->
                    <article
                        v-for="item in visibleItems"
                        :key="item.publicId"
                        class="flex flex-col gap-3 p-4"
                        :class="isScanChecked(item) && 'bg-success-wash/60'"
                        :data-testid="`line-${item.publicId}`"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-info-wash text-info"
                                aria-hidden="true"
                                ><Pill class="size-4"
                            /></span>
                            <div class="min-w-0 flex-1">
                                <h2 class="text-[15px] font-semibold">
                                    {{ item.name }}
                                </h2>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        [
                                            item.strength,
                                            item.dosageForm,
                                            item.code,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                                <p
                                    class="mt-1 text-[13px] break-words"
                                    data-testid="direction-line"
                                >
                                    {{ directionLine(givenDirections(item)) }}
                                </p>
                            </div>
                            <div
                                class="flex max-w-[45%] flex-wrap justify-end gap-1"
                            >
                                <StatusBadge
                                    v-if="changeLabel(item)"
                                    status="ca_change"
                                    tone="accent"
                                    :label="changeLabel(item)"
                                />
                                <StatusBadge
                                    v-if="isScanChecked(item)"
                                    status="scan_checked"
                                    tone="success"
                                    label="Checked by scan"
                                    data-testid="scan-checked"
                                />
                                <span v-if="isScanChecked(item)" class="sr-only"
                                    >, not saved</span
                                >
                            </div>
                        </div>
                        <div
                            class="grid gap-2 text-[13px] sm:grid-cols-2 sm:pl-12"
                        >
                            <div class="rounded-lg bg-card-quiet px-3 py-2">
                                <p class="text-xs text-muted-foreground">
                                    Doctor ordered
                                </p>
                                <p
                                    v-if="item.source === 'doctor'"
                                    class="font-semibold"
                                >
                                    {{ displayQuantity(item.quantityOrdered) }}
                                    {{ item.unit }}
                                </p>
                                <p
                                    v-if="item.source === 'doctor'"
                                    class="text-xs break-words text-muted-foreground"
                                    data-testid="original-order"
                                >
                                    {{ directionLine(item.original) }}
                                </p>
                                <p v-else class="text-muted-foreground">
                                    Not ordered. Added by CA.
                                </p>
                            </div>
                            <div class="rounded-lg border px-3 py-2">
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        dispensary.viewOnly
                                            ? 'CA gives'
                                            : 'You give'
                                    }}
                                </p>
                                <p
                                    class="font-semibold"
                                    data-testid="given-quantity"
                                >
                                    {{ givenQuantity(item) }}
                                </p>
                                <p
                                    v-if="item.status === 'pending'"
                                    class="text-xs text-muted-foreground"
                                >
                                    Not saved yet
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1 text-xs sm:pl-12">
                            <p v-if="!item.sku" class="text-warning-strong">
                                No approved inventory SKU mapping.
                            </p>
                            <p
                                v-else-if="
                                    !item.availability.length &&
                                    !batchLine(item)
                                "
                                class="text-warning-strong"
                            >
                                No eligible stock in this branch's Dispensary.
                                Stock at other locations requires transfer
                                before fulfilment.
                            </p>
                            <p
                                v-else-if="
                                    !batchLine(item) &&
                                    item.availability.every(
                                        (stock) => stock.held,
                                    )
                                "
                                class="text-warning-strong"
                                data-testid="batch-all-held"
                            >
                                Every batch here is on hold, so this line cannot
                                be saved yet.
                            </p>
                            <p
                                v-else-if="batchLine(item)"
                                class="text-muted-foreground"
                                data-testid="batch-line"
                            >
                                {{ batchLine(item) }}
                            </p>
                            <p
                                v-if="scanBatch(item)"
                                class="flex items-start gap-1 font-medium"
                                :class="
                                    scanBatch(item)?.warn
                                        ? 'text-warning-strong'
                                        : 'text-success'
                                "
                                :data-testid="`scan-batch-${scanBatch(item)?.kind}`"
                            >
                                <TriangleAlert
                                    v-if="scanBatch(item)?.warn"
                                    class="mt-px size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <CheckCircle2
                                    v-else
                                    class="mt-px size-3.5 shrink-0"
                                    aria-hidden="true"
                                />{{ scanBatch(item)?.message }}
                            </p>
                            <p
                                v-if="item.exception"
                                class="text-warning-strong"
                            >
                                Earlier partial dispense:
                                {{
                                    exceptionStatusLabels[
                                        item.exception.status
                                    ] ?? 'status unknown'
                                }}
                            </p>
                        </div>
                        <AllergyMatchWarning
                            v-if="
                                dispensary.can.update && matchesFor(item).length
                            "
                            v-model="allergyChecks[checkKey(item)]"
                            :matches="matchesFor(item)"
                            :field-id="`allergy-check-${item.publicId}`"
                            class="sm:ml-12"
                        />
                        <p
                            v-if="
                                dispensary.can.update && item.allergyUnconfirmed
                            "
                            class="text-xs font-medium sm:pl-12"
                            data-testid="allergy-resave-hint"
                        >
                            This medicine matches an allergy recorded after it
                            was saved. Tick the box, then press Save line.
                        </p>
                        <div
                            :id="`line-edit-${item.publicId}`"
                            v-if="isEditing(item)"
                            class="grid gap-3 text-[13px] sm:grid-cols-2 lg:grid-cols-4 [&_input]:font-normal [&_label:not(:has(input[type=checkbox],input[type=radio]))]:font-semibold [&_select]:font-normal [&_textarea]:font-normal"
                        >
                            <p class="text-muted-foreground sm:col-span-full">
                                Label limits (characters; saved clinical text is
                                unchanged): dosage
                                {{ dispensary.labelLimits?.dosage }}, frequency
                                {{ dispensary.labelLimits?.frequency }},
                                duration {{ dispensary.labelLimits?.duration }},
                                instruction
                                {{ dispensary.labelLimits?.instruction }},
                                precaution
                                {{ dispensary.labelLimits?.precaution }},
                                quantity {{ dispensary.labelLimits?.quantity }}.
                                Dosage, frequency and instruction share 3
                                printed rows. Route is not printed. Over-budget
                                labels are blocked, never truncated.
                            </p>
                            <PackQuantityField
                                v-model:quantity="forms[item.publicId].quantity"
                                v-model:packs="forms[item.publicId].packs"
                                v-model:mode="forms[item.publicId].packMode"
                                :unit="item.unit"
                                :conversion="item.packConversion"
                                :ordered="
                                    item.source === 'doctor'
                                        ? item.original.quantity
                                        : null
                                "
                                :input-class="inputClass"
                                data-field="quantity"
                            />
                            <label class="lg:col-span-3"
                                >Dosage<input
                                    v-model="forms[item.publicId].dosage"
                                    :class="inputClass"
                                    maxlength="500"
                                    data-field="dosage"
                                /><span
                                    class="mt-1 block text-xs font-normal text-muted-foreground"
                                    >Label max
                                    {{ dispensary.labelLimits?.dosage }}</span
                                ></label
                            >
                            <label class="lg:col-span-2"
                                >Frequency<input
                                    v-model="forms[item.publicId].frequency"
                                    :class="inputClass"
                                    maxlength="500"
                                    data-field="frequency"
                                /><span
                                    class="mt-1 block text-xs font-normal text-muted-foreground"
                                    >Label max
                                    {{
                                        dispensary.labelLimits?.frequency
                                    }}</span
                                ></label
                            >
                            <label
                                >Duration<input
                                    v-model="forms[item.publicId].duration"
                                    :class="inputClass"
                                    maxlength="500"
                                    data-field="duration"
                                /><span
                                    class="mt-1 block text-xs font-normal text-muted-foreground"
                                    >Label max
                                    {{ dispensary.labelLimits?.duration }}</span
                                ></label
                            >
                            <label
                                >Route<input
                                    v-model="forms[item.publicId].route"
                                    :class="inputClass"
                                    maxlength="500"
                                    data-field="route"
                                /><span
                                    class="mt-1 block text-xs font-normal text-muted-foreground"
                                    >Not printed on the label</span
                                ></label
                            >
                            <label class="sm:col-span-2"
                                >Instruction<input
                                    v-model="forms[item.publicId].instruction"
                                    :class="inputClass"
                                    maxlength="2000"
                                    data-field="instruction"
                                /><span
                                    class="mt-1 block text-xs font-normal text-muted-foreground"
                                    >Label max
                                    {{
                                        dispensary.labelLimits?.instruction
                                    }}</span
                                ></label
                            >
                            <label class="sm:col-span-2"
                                >Precaution<input
                                    v-model="forms[item.publicId].precaution"
                                    :class="inputClass"
                                    maxlength="2000"
                                    data-field="precaution"
                                /><span
                                    class="mt-1 block text-xs font-normal text-muted-foreground"
                                    >Label max
                                    {{
                                        dispensary.labelLimits?.precaution
                                    }}</span
                                ></label
                            >
                            <label class="sm:col-span-full"
                                >Batch<select
                                    v-model.number="
                                        forms[item.publicId].availabilityIndex
                                    "
                                    :class="[
                                        inputClass,
                                        batchHint === item.publicId &&
                                            batchHintClass,
                                    ]"
                                    :aria-describedby="`batch-selected-${item.publicId}`"
                                    :data-batch-select="item.publicId"
                                    @change="clearBatchHint(item.publicId)"
                                >
                                    <option
                                        v-for="(
                                            stock, index
                                        ) in item.availability"
                                        :key="stock.batchPublicId"
                                        :value="index"
                                        :disabled="stock.held"
                                    >
                                        {{
                                            matchesBox(item.publicId, index)
                                                ? 'Matches the box · '
                                                : ''
                                        }}{{
                                            batchOptionText(
                                                stock,
                                                item.sku?.unit ?? item.unit,
                                                usablePackConversion(
                                                    item.packConversion,
                                                    item.sku?.unit ?? item.unit,
                                                ),
                                            )
                                        }}
                                    </option>
                                </select></label
                            >
                            <p
                                :id="`batch-selected-${item.publicId}`"
                                class="text-xs text-muted-foreground sm:col-span-full"
                                data-testid="batch-selected"
                            >
                                <span
                                    role="status"
                                    class="block font-medium text-primary empty:hidden"
                                    ><span
                                        v-if="batchHint === item.publicId"
                                        class="inline-flex items-start gap-1"
                                        :class="
                                            boxHint(item.publicId).warn &&
                                            'text-warning-strong'
                                        "
                                        data-testid="batch-pick-hint"
                                        ><ScanLine
                                            class="mt-px size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />{{
                                            boxHint(item.publicId).message
                                        }}</span
                                    ></span
                                >
                                {{
                                    selectedBatchText(
                                        item.availability[
                                            forms[item.publicId]
                                                .availabilityIndex
                                        ],
                                        item.sku?.unit ?? item.unit,
                                        usablePackConversion(
                                            item.packConversion,
                                            item.sku?.unit ?? item.unit,
                                        ),
                                    )
                                }}
                            </p>
                            <label class="grid gap-2 sm:col-span-2"
                                >FEFO exception reason (later batch only)<select
                                    v-model="forms[item.publicId].fefoReason"
                                    :class="inputClass"
                                >
                                    <option value="">No exception</option>
                                    <option value="earlier_batch_damaged">
                                        Earlier batch damaged
                                    </option>
                                    <option value="earlier_batch_insufficient">
                                        Earlier batch insufficient
                                    </option>
                                    <option value="earlier_batch_held">
                                        Earlier batch held
                                    </option>
                                    <option value="other_approved">
                                        Other approved
                                    </option>
                                </select></label
                            >
                            <p
                                class="text-xs text-muted-foreground sm:col-span-2"
                            >
                                Up to 100 batches shown. Held batches are
                                visible but cannot be selected. A later batch
                                needs a controlled reason when an eligible
                                earlier-expiry batch exists.
                            </p>
                        </div>
                        <div
                            v-if="
                                (dispensary.can.update &&
                                    forms[item.publicId]) ||
                                canPrintLine(item)
                            "
                            class="flex flex-wrap items-center gap-2 sm:pl-12"
                        >
                            <template
                                v-if="
                                    dispensary.can.update &&
                                    forms[item.publicId]
                                "
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="
                                        busy ||
                                        needsAllergyCheck(item) ||
                                        packsInvalid(forms[item.publicId]) ||
                                        (item.status !== 'pending' &&
                                            !isDirty(item) &&
                                            !item.allergyUnconfirmed)
                                    "
                                    data-action="save-line"
                                    @click="saveItem(item)"
                                    ><LoaderCircle
                                        v-if="busy"
                                        class="size-4 animate-spin"
                                    />Save line<span class="sr-only">
                                        for {{ item.name }}</span
                                    ></Button
                                >
                                <Button
                                    v-if="!isEditing(item)"
                                    size="sm"
                                    variant="outline"
                                    :aria-expanded="false"
                                    :aria-controls="`line-edit-${item.publicId}`"
                                    data-action="edit-line"
                                    @click="openCard(item)"
                                    ><Pencil class="size-4" />Edit<span
                                        class="sr-only"
                                    >
                                        {{ item.name }}</span
                                    ></Button
                                >
                                <Button
                                    v-else-if="isDirty(item)"
                                    size="sm"
                                    variant="ghost"
                                    :disabled="busy"
                                    data-action="undo-line"
                                    @click="undoCard(item)"
                                    ><Undo2 class="size-4" />Undo
                                    changes</Button
                                >
                                <Button
                                    v-else-if="!mustStayOpen(item)"
                                    size="sm"
                                    variant="ghost"
                                    :aria-expanded="true"
                                    :aria-controls="`line-edit-${item.publicId}`"
                                    data-action="close-line"
                                    @click="closeCard(item)"
                                    >Done editing</Button
                                >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="busy"
                                    data-action="remove-line"
                                    @click="removeItem(item)"
                                    ><Trash2 class="size-4" />Remove</Button
                                >
                            </template>
                            <Button
                                v-if="canPrintLine(item)"
                                as-child
                                variant="ghost"
                                size="sm"
                            >
                                <a
                                    :href="`/dispensary/${dispensary.publicId}/items/${item.publicId}/label`"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Printer class="size-3.5" />Print label
                                    <span class="sr-only"
                                        >for {{ item.name }} (opens print
                                        preview in a new tab)</span
                                    >
                                </a>
                            </Button>
                        </div>
                    </article>
                    <p
                        v-if="!visibleItems.length"
                        class="p-3 text-sm text-muted-foreground"
                    >
                        {{
                            isOtc
                                ? 'No medicine on the list yet. Add a medicine below.'
                                : 'Every medicine has been removed from the list. Add a medicine below, or return the case to the doctor.'
                        }}
                    </p>
                </section>

                <section
                    v-if="removedItems.length"
                    class="rounded-xl border border-dashed bg-card-quiet p-4 text-sm"
                    data-testid="removed-lines"
                >
                    <h2 class="mb-2 text-[15px] font-semibold">
                        Removed from the list
                    </h2>
                    <ul class="grid gap-1">
                        <li
                            v-for="item in removedItems"
                            :key="item.publicId"
                            class="grid gap-2"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span
                                    class="text-muted-foreground line-through"
                                    >{{ item.name }}</span
                                >
                                <Button
                                    v-if="dispensary.can.update"
                                    size="sm"
                                    variant="outline"
                                    :disabled="
                                        busy ||
                                        !item.availability.length ||
                                        needsAllergyCheck(item)
                                    "
                                    data-action="restore-line"
                                    @click="restoreItem(item)"
                                    >Put back</Button
                                >
                            </div>
                            <AllergyMatchWarning
                                v-if="
                                    dispensary.can.update &&
                                    matchesFor(item).length
                                "
                                v-model="allergyChecks[checkKey(item)]"
                                :matches="matchesFor(item)"
                                :field-id="`allergy-check-${item.publicId}`"
                            />
                        </li>
                    </ul>
                </section>

                <section
                    v-if="dispensary.can.update"
                    class="rounded-xl border bg-card p-4 text-sm shadow-card"
                    data-testid="add-medicine"
                >
                    <h2 class="mb-3 text-[15px] font-semibold">
                        Add a medicine
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <input
                            v-model="searchTerm"
                            class="h-9 w-full rounded-md border border-input bg-field-fill px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:max-w-sm"
                            placeholder="Search medicine name or code"
                            aria-label="Search medicine"
                            data-field="medicine-search"
                            @keydown.enter.prevent="searchMedicines"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="searching || busy"
                            class="flex-1 sm:flex-none"
                            @click="searchMedicines"
                            ><Plus class="size-4" />Search</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            class="flex-1 sm:flex-none"
                            :disabled="busy"
                            :aria-expanded="scanAddOpen"
                            :aria-controls="
                                scanAddOpen ? 'scan-add-panel' : undefined
                            "
                            data-action="scan-to-add"
                            @click="openScanAdd"
                            ><ScanLine class="size-4" />Scan to add</Button
                        >
                    </div>
                    <div
                        v-if="scanAddOpen"
                        id="scan-add-panel"
                        class="mt-2 grid gap-1"
                        @keydown.esc="closeScanAdd"
                    >
                        <ScanInput
                            context="dispensary-add"
                            refocus-key="dispensary-add"
                            :describe="() => ''"
                            lookup-variant="outline"
                            data-testid="dispensary-scan-add"
                            @found="scanToAdd"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            class="justify-self-start"
                            data-action="close-scan-add"
                            @click="closeScanAdd"
                            >Close scan</Button
                        >
                    </div>
                    <p
                        v-if="scanAddAllergy"
                        role="alert"
                        class="mt-2 flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 font-medium text-destructive"
                        data-testid="scan-add-allergy"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        /><span>{{ scanAddMessage }}</span>
                    </p>
                    <p
                        v-else-if="scanAddMessage"
                        role="status"
                        aria-live="polite"
                        class="mt-2 text-xs text-muted-foreground"
                        data-testid="scan-add-status"
                    >
                        {{ scanAddMessage }}
                    </p>
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
                                }}</span>
                                <span
                                    v-if="matchesFor(medicine).length"
                                    class="ml-1 inline-block rounded-md bg-destructive/10 px-2 py-1 text-xs font-medium text-destructive"
                                    data-testid="allergy-match-badge"
                                    >Possible allergy match</span
                                ></span
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="!medicine.availability.length"
                                data-action="choose-medicine"
                                @click="pickMedicine(medicine)"
                                >{{
                                    medicine.availability.length
                                        ? 'Choose'
                                        : 'No stock'
                                }}</Button
                            >
                        </li>
                    </ul>
                    <p
                        v-else-if="
                            !chosen &&
                            !scanAddMessage &&
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
                        class="mt-3 grid gap-3 text-[13px] sm:grid-cols-2 lg:grid-cols-4 [&_input]:font-normal [&_label:not(:has(input[type=checkbox],input[type=radio]))]:font-semibold [&_select]:font-normal [&_textarea]:font-normal"
                    >
                        <p class="text-sm font-medium sm:col-span-full">
                            {{ chosen.name }}
                        </p>
                        <PackQuantityField
                            v-model:quantity="addForm.quantity"
                            v-model:packs="addForm.packs"
                            v-model:mode="addForm.packMode"
                            :unit="chosen.unit"
                            :conversion="chosen.packConversion"
                            :input-class="inputClass"
                            data-field="add-quantity"
                        />
                        <label class="lg:col-span-3"
                            >Dosage<input
                                v-model="addForm.dosage"
                                :class="inputClass"
                                maxlength="500"
                                data-field="add-dosage"
                            /><span
                                class="mt-1 block text-xs font-normal text-muted-foreground"
                                >Label max
                                {{ dispensary.labelLimits?.dosage }}</span
                            ></label
                        >
                        <label class="lg:col-span-2"
                            >Frequency<input
                                v-model="addForm.frequency"
                                :class="inputClass"
                                maxlength="500"
                                data-field="add-frequency"
                            /><span
                                class="mt-1 block text-xs font-normal text-muted-foreground"
                                >Label max
                                {{ dispensary.labelLimits?.frequency }}</span
                            ></label
                        >
                        <label
                            >Duration<input
                                v-model="addForm.duration"
                                :class="inputClass"
                                maxlength="500"
                            /><span
                                class="mt-1 block text-xs font-normal text-muted-foreground"
                                >Label max
                                {{ dispensary.labelLimits?.duration }}</span
                            ></label
                        >
                        <label
                            >Route<input
                                v-model="addForm.route"
                                :class="inputClass"
                                maxlength="500"
                            /><span
                                class="mt-1 block text-xs font-normal text-muted-foreground"
                                >Not printed on the label</span
                            ></label
                        >
                        <label class="sm:col-span-2"
                            >Instruction<input
                                v-model="addForm.instruction"
                                :class="inputClass"
                                maxlength="2000"
                            /><span
                                class="mt-1 block text-xs font-normal text-muted-foreground"
                                >Label max
                                {{ dispensary.labelLimits?.instruction }}</span
                            ></label
                        >
                        <label class="sm:col-span-2"
                            >Precaution<input
                                v-model="addForm.precaution"
                                :class="inputClass"
                                maxlength="2000"
                            /><span
                                class="mt-1 block text-xs font-normal text-muted-foreground"
                                >Label max
                                {{ dispensary.labelLimits?.precaution }}</span
                            ></label
                        >
                        <label class="sm:col-span-full"
                            >Batch<select
                                v-model.number="addForm.availabilityIndex"
                                :class="[
                                    inputClass,
                                    batchHint === 'add' && batchHintClass,
                                ]"
                                aria-describedby="batch-selected-add"
                                data-batch-select="add"
                                @change="clearBatchHint('add')"
                            >
                                <option
                                    v-for="(
                                        stock, index
                                    ) in chosen.availability"
                                    :key="stock.batchPublicId"
                                    :value="index"
                                    :disabled="stock.held"
                                >
                                    {{
                                        matchesBox('add', index)
                                            ? 'Matches the box · '
                                            : ''
                                    }}{{
                                        batchOptionText(
                                            stock,
                                            chosen.sku?.unit ?? chosen.unit,
                                            usablePackConversion(
                                                chosen.packConversion,
                                                chosen.sku?.unit ?? chosen.unit,
                                            ),
                                        )
                                    }}
                                </option>
                            </select></label
                        >
                        <p
                            id="batch-selected-add"
                            class="text-xs text-muted-foreground sm:col-span-full"
                            data-testid="batch-selected"
                        >
                            <span
                                role="status"
                                class="block font-medium text-primary empty:hidden"
                                ><span
                                    v-if="batchHint === 'add'"
                                    class="inline-flex items-start gap-1"
                                    :class="
                                        boxHint('add').warn &&
                                        'text-warning-strong'
                                    "
                                    data-testid="batch-pick-hint"
                                    ><ScanLine
                                        class="mt-px size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />{{ boxHint('add').message }}</span
                                ></span
                            >
                            {{
                                selectedBatchText(
                                    chosen.availability[
                                        addForm.availabilityIndex
                                    ],
                                    chosen.sku?.unit ?? chosen.unit,
                                    usablePackConversion(
                                        chosen.packConversion,
                                        chosen.sku?.unit ?? chosen.unit,
                                    ),
                                )
                            }}
                        </p>
                        <label class="grid gap-2 sm:col-span-full"
                            >FEFO exception reason (later batch only)<select
                                v-model="addForm.fefoReason"
                                :class="inputClass"
                            >
                                <option value="">No exception</option>
                                <option value="earlier_batch_damaged">
                                    Earlier batch damaged
                                </option>
                                <option value="earlier_batch_insufficient">
                                    Earlier batch insufficient
                                </option>
                                <option value="earlier_batch_held">
                                    Earlier batch held
                                </option>
                                <option value="other_approved">
                                    Other approved
                                </option>
                            </select></label
                        >
                        <p
                            class="text-xs text-muted-foreground sm:col-span-full"
                        >
                            Up to 100 batches shown. Held batches cannot be
                            selected. A later batch needs a controlled reason
                            when an eligible earlier-expiry batch exists.
                        </p>
                        <p
                            class="text-xs text-muted-foreground sm:col-span-full"
                        >
                            Label limits: dosage
                            {{ dispensary.labelLimits?.dosage }}, frequency
                            {{ dispensary.labelLimits?.frequency }}, duration
                            {{ dispensary.labelLimits?.duration }}, instruction
                            {{ dispensary.labelLimits?.instruction }},
                            precaution {{ dispensary.labelLimits?.precaution }},
                            quantity
                            {{ dispensary.labelLimits?.quantity }} characters.
                            Dosage, frequency and instruction share 3 printed
                            rows. Route is not printed. Printing does not
                            truncate saved text.
                        </p>
                        <AllergyMatchWarning
                            v-if="matchesFor(chosen).length"
                            v-model="addAllergyCheck"
                            :matches="matchesFor(chosen)"
                            field-id="allergy-check-add"
                            class="sm:col-span-full"
                        />
                        <div class="flex gap-2 sm:col-span-full">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="
                                    busy ||
                                    (matchesFor(chosen).length > 0 &&
                                        !addAllergyCheck) ||
                                    !addForm.dosage.trim() ||
                                    !addForm.frequency.trim() ||
                                    packsInvalid(addForm)
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
                    v-if="!isOtc"
                    class="rounded-xl border bg-card shadow-card"
                    data-testid="dispensary-services"
                >
                    <h2 class="border-b px-4 py-3 text-[15px] font-semibold">
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
                            class="grid gap-4 p-4 xl:grid-cols-[minmax(180px,1fr)_minmax(0,3fr)]"
                            :data-testid="`service-${line.publicId}`"
                        >
                            <div>
                                <h3 class="text-sm font-medium">
                                    {{ line.name }}
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    {{ line.code }}
                                </p>
                                <StatusBadge
                                    v-if="serviceChangeLabel(line)"
                                    class="mt-1"
                                    status="ca_change"
                                    tone="accent"
                                    :label="serviceChangeLabel(line)"
                                />
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
                                            ? 'text-success'
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
                                    dispensary.can.update &&
                                    serviceForms[line.publicId]
                                "
                                class="grid gap-3 text-[13px] sm:grid-cols-2 lg:grid-cols-4 [&_input]:font-normal [&_label:not(:has(input[type=checkbox],input[type=radio]))]:font-semibold [&_select]:font-normal [&_textarea]:font-normal"
                            >
                                <label
                                    >Performed ({{ line.unit }})<input
                                        v-model="
                                            serviceForms[line.publicId].quantity
                                        "
                                        :class="inputClass"
                                        inputmode="decimal"
                                        data-field="service-quantity"
                                /></label>
                                <label class="sm:col-span-2 lg:col-span-3"
                                    >Instruction<input
                                        v-model="
                                            serviceForms[line.publicId]
                                                .instruction
                                        "
                                        :class="inputClass"
                                        maxlength="2000"
                                        data-field="service-instruction"
                                /></label>
                                <p
                                    class="text-xs text-muted-foreground sm:col-span-full"
                                >
                                    Enter 0 if the service was not performed.
                                </p>
                                <div
                                    class="flex items-end gap-2 sm:col-span-full"
                                >
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :disabled="
                                            busy || !isServiceDirty(line)
                                        "
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
                        <h3 class="mb-2 text-[15px] font-semibold">
                            Removed from the list
                        </h3>
                        <ul class="grid gap-1">
                            <li
                                v-for="line in removedServices"
                                :key="line.publicId"
                                class="flex items-center justify-between gap-2"
                            >
                                <span
                                    class="text-muted-foreground line-through"
                                    >{{ line.name }}</span
                                >
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
                        <h3 class="mb-3 text-[15px] font-semibold">
                            Add a service
                        </h3>
                        <div class="flex gap-2">
                            <input
                                v-model="serviceTerm"
                                class="h-9 w-full max-w-sm rounded-md border border-input bg-field-fill px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ service.code }}</span
                                    ></span
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
                            class="mt-3 grid gap-3 text-[13px] sm:grid-cols-2 lg:grid-cols-4 [&_input]:font-normal [&_label:not(:has(input[type=checkbox],input[type=radio]))]:font-semibold [&_select]:font-normal [&_textarea]:font-normal"
                        >
                            <p class="text-sm font-medium sm:col-span-full">
                                {{ chosenService.name }}
                            </p>
                            <label
                                >Performed ({{ chosenService.unit }})<input
                                    v-model="serviceAdd.quantity"
                                    :class="inputClass"
                                    inputmode="decimal"
                                    data-field="add-service-quantity"
                            /></label>
                            <label class="sm:col-span-2 lg:col-span-3"
                                >Instruction<input
                                    v-model="serviceAdd.instruction"
                                    :class="inputClass"
                                    maxlength="2000"
                            /></label>
                            <div class="flex gap-2 sm:col-span-full">
                                <Button
                                    size="sm"
                                    variant="outline"
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
            </div>
            <aside class="flex flex-col gap-4" data-testid="dispensary-right">
                <section
                    class="rounded-xl border bg-card p-4 text-sm"
                    data-testid="dispensary-summary"
                >
                    <h2 class="mb-3 text-[15px] font-semibold">
                        This dispensary
                    </h2>
                    <dl class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1">
                        <dt class="text-muted-foreground">Medicines</dt>
                        <dd>{{ visibleItems.length }}</dd>
                        <template v-if="!isOtc">
                            <dt class="text-muted-foreground">Services</dt>
                            <dd>{{ visibleServices.length }}</dd>
                        </template>
                        <template v-if="!dispensary.viewOnly">
                            <dt class="text-muted-foreground">Not saved yet</dt>
                            <dd>
                                {{ unsaved.length + unsavedServices.length }}
                            </dd>
                        </template>
                        <template v-if="dispensary.can.update && scanTotal">
                            <dt class="text-muted-foreground">
                                Checked by scan
                            </dt>
                            <dd class="tabular-nums">
                                {{ scanChecked }} of {{ scanTotal }} (not saved)
                            </dd>
                        </template>
                    </dl>
                </section>
                <section
                    v-if="!dispensary.viewOnly"
                    class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm"
                    data-testid="dispensary-actions"
                >
                    <h2 class="text-[15px] font-semibold">Actions</h2>
                    <OperationalSelect
                        v-if="
                            dispensary.tvCall?.canCall &&
                            dispensary.tvCall.rooms.length > 1
                        "
                        v-model="callRoomId"
                        label="Dispensary room"
                        placeholder="Choose room"
                        trigger-class="min-h-9 w-full max-lg:min-h-11"
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
                        ><Megaphone class="size-4" />Call patient</Button
                    >
                    <p
                        v-if="dispensary.tvCall?.canCall && waitingForCheck"
                        class="text-xs text-muted-foreground"
                        data-testid="doctor-check-call-help"
                    >
                        Do not hand over the medicine until the doctor has
                        checked it.
                    </p>
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
                        :variant="confirming ? 'outline' : 'default'"
                        :disabled="
                            busy ||
                            unsaved.length > 0 ||
                            unsavedServices.length > 0 ||
                            (isOtc && !otcAllergyConfirmed)
                        "
                        data-testid="dispensary-complete-open"
                        @click="confirming = true"
                        ><CheckCircle2 class="size-4" />{{
                            checkRequired
                                ? 'Send to doctor for check'
                                : 'Complete Dispensary'
                        }}</Button
                    >
                    <p
                        v-if="dispensary.can.complete && completeBlockedReason"
                        class="w-full text-xs text-muted-foreground"
                        data-testid="dispensary-complete-blocked-reason"
                    >
                        {{ completeBlockedReason }}
                    </p>
                </section>
                <section
                    v-if="confirming && dispensary.can.complete"
                    class="rounded-xl border border-info/25 bg-info-wash p-4 text-sm"
                    data-testid="dispensary-verify"
                >
                    <p class="font-medium">
                        {{
                            checkRequired
                                ? 'Send to the doctor for check'
                                : 'Verify before completing'
                        }}
                    </p>
                    <p class="mb-2 text-xs text-muted-foreground">
                        {{
                            checkRequired
                                ? "The doctor checks the prepared medicine before the visit goes to billing. The stock is taken out only when the doctor verifies. You can't change the case while it waits."
                                : isOtc
                                  ? 'There is no doctor on an OTC visit. Completing now records your verification of the list below and takes the stock out.'
                                  : 'The doctor has verified the order. Completing now records your verification of the final list below and takes the stock out.'
                        }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            :disabled="busy"
                            data-testid="dispensary-complete-confirm"
                            @click="
                                checkRequired ? sendForCheck() : completeCase()
                            "
                            ><CheckCircle2 class="size-4" />{{
                                checkRequired
                                    ? 'Confirm and send'
                                    : 'Confirm and complete'
                            }}</Button
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
                <!-- DSP-23, owner answer 13 = B: a CA Supervisor who did not prepare the case may check instead. -->
                <section
                    v-if="dispensary.doctorCheck?.canCheck"
                    class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm"
                    data-testid="doctor-check-supervisor"
                >
                    <h2 class="text-[15px] font-semibold">
                        Check instead of the doctor
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Only when the doctor can't check. You give a reason, and
                        it is recorded.
                    </p>
                    <DoctorCheckActions
                        :verify-url="`/dispensary/${dispensary.publicId}/doctor-check/verify`"
                        :send-back-url="`/dispensary/${dispensary.publicId}/doctor-check/send-back`"
                        :case-lock-version="dispensary.lockVersion"
                        :expected-branch-id="branchId()"
                        :doctor-name="dispensary.doctor"
                        as-supervisor
                    />
                </section>
                <p
                    v-else-if="dispensary.doctorCheck?.preparedByYou"
                    class="text-xs text-muted-foreground"
                    data-testid="doctor-check-prepared-by-you"
                >
                    You prepared this case, so someone else must check it.
                </p>
            </aside>
        </div>
    </main>
</template>
