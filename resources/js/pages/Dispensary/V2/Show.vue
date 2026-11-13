<script setup lang="ts">
/**
 * Version 2 Dispensary case page (shown only in V2; Classic is `pages/Dispensary/Show.vue`, untouched). The logic is a
 * copy of Classic's, kept in step by tests/Frontend/v2-dispensary-case.test.mjs: same props, routes, payload keys,
 * lock versions, permissions and server rules. V2 changes the look only (Daniel's department ruling 2026-11-13): the
 * patient and allergy card is the first card of the main column at every width, one filled button carries the next
 * step, and Verify before completing is a dialog.
 */
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ChevronDown,
    LoaderCircle,
    Megaphone,
    Plus,
    Printer,
    RotateCcw,
    ScanLine,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import { Button } from '@/components/ui/button';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2StickySummary from '@/components/v2/V2StickySummary.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
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
import OtcAllergyRecordForm from '@/pages/Dispensary/Partials/OtcAllergyRecordForm.vue';
import AllergyMatchWarning from '@/pages/Dispensary/V2/Partials/AllergyMatchWarning.vue';

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
    labelLimits?: Record<string, number>;
    publicId: string;
    status: 'pending' | 'dispensing';
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
};
type Medicine = {
    publicId: string;
    name: string;
    code: string;
    strength: string | null;
    unit: string;
    sku: { publicId: string; unit: string };
    availability: Availability[];
    allergyMatches?: AllergyMatch[];
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
const batchOptionText = (stock: Availability, unit: string): string =>
    `${stock.held ? `Held: ${formatInventoryReason(stock.holdReason)} · ` : ''}${stockAmount(stock.quantity, unit)} on hand · exp ${formatDate(stock.expiryDate)} · ${stock.batchNumber} · ${stock.locationName}`;
const selectedBatchText = (
    stock: Availability | undefined,
    unit: string,
): string =>
    stock === undefined
        ? 'No batch selected.'
        : stock.held
          ? 'No usable batch: every batch here is on hold.'
          : `Selected: ${batchOptionText(stock, unit)}`;
const addForm = reactive<LineForm>({
    quantity: '1.000',
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
const changeLabel = (item: Item) =>
    ({
        edited: 'Edited by CA',
        added: 'Added by CA',
        removed: 'Removed',
        unchanged: '',
    })[item.changeState];
const inputClass = 'mt-1 h-9 w-full rounded-md border px-2';
// DSP-17: brand line colour only (not the error look); replaces the focus ring while shown.
const batchHintClass =
    'border-brand ring-2 ring-brand ring-offset-2 ring-offset-background focus-visible:ring-brand';
// Daniel: scan to check opens from a closed bar; opening puts the cursor in the box for the next handheld scan.
const checkScanOpen = ref(false);
const toggleCheckScan = async () => {
    checkScanOpen.value = !checkScanOpen.value;

    if (checkScanOpen.value) {
        await nextTick();
        document.getElementById('scan-dispensary-check')?.focus();
    }
};
// Daniel: V2 has no native selects. Batch and FEFO use OperationalSelect; the batch text wraps in the field and in the
// list (quantity, expiry and batch number never cut), since the batch is the stock-safety choice.
const fefoOptions = [
    { value: '', label: 'No exception' },
    { value: 'earlier_batch_damaged', label: 'Earlier batch damaged' },
    {
        value: 'earlier_batch_insufficient',
        label: 'Earlier batch insufficient',
    },
    { value: 'earlier_batch_held', label: 'Earlier batch held' },
    { value: 'other_approved', label: 'Other approved' },
];
const batchOptions = (
    target: string,
    availability: Availability[],
    unit: string,
) =>
    availability.map((stock, index) => ({
        value: index,
        label: `${matchesBox(target, index) ? 'Matches the box · ' : ''}${batchOptionText(stock, unit)}`,
        disabled: stock.held,
    }));
// Hakim: clicking the visible word opens its field, as a <label> did for the native select. The select opens from a
// real pointer or the keyboard only (a synthetic click just focuses it), so focus the trigger and press Enter on it.
const openSelect = (labelId: string) => {
    const trigger = document.querySelector<HTMLButtonElement>(
        `[aria-labelledby="${labelId}"]`,
    );

    if (trigger === null || trigger.disabled) {
        return;
    }

    trigger.focus();
    trigger.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    );
};
const selectTriggerClass =
    'mt-1 h-auto min-h-11 w-full py-2 text-left whitespace-normal data-[size=default]:h-auto lg:min-h-9 *:data-[slot=select-value]:line-clamp-none';

// Plain words for an earlier partial dispense, never the stored code (Daniel, guide section 15).
const exceptionStatusLabels: Record<string, string> = {
    awaiting_acknowledgement: 'waiting for the doctor to acknowledge',
    acknowledged: 'acknowledged by the doctor',
    superseded: 'replaced by a later change',
};

// V2: crumbs above the title (PageFrame / usePageCrumbs).
usePageCrumbs(() => [
    { title: 'Registration', href: '/registration' },
    { title: props.dispensary.visit.visitNumber },
    { title: 'Dispensary' },
]);
// V2: the counts, for the summary rail (and the disclosure bar on phones).
const unsavedCount = computed(
    () => unsaved.value.length + unsavedServices.value.length,
);
const lineCount = computed(
    () => visibleItems.value.length + visibleServices.value.length,
);
const countsTitle = computed(
    () =>
        `${lineCount.value} line${lineCount.value === 1 ? '' : 's'}${
            unsavedCount.value ? ` · ${unsavedCount.value} not saved` : ''
        }`,
);
const summaryItems = computed(() => [
    { label: 'Medicines', value: String(visibleItems.value.length) },
    ...(isOtc.value
        ? []
        : [{ label: 'Services', value: String(visibleServices.value.length) }]),
    { label: 'Not saved yet', value: String(unsavedCount.value) },
    ...(props.dispensary.can.update && scanTotal.value
        ? [
              {
                  label: 'Checked by scan',
                  value: `${scanChecked.value} of ${scanTotal.value} (not saved)`,
              },
          ]
        : []),
]);
// V2: one filled button, always the next step. While anything is unsaved, Complete is not offered (Daniel, condition 2).
const primaryAction = computed<'start' | 'save' | 'complete' | null>(() => {
    if (props.dispensary.can.start) {
        return 'start';
    }

    if (props.dispensary.can.update && unsavedCount.value > 0) {
        return 'save';
    }

    return props.dispensary.can.complete ? 'complete' : null;
});
const primaryLabel = computed(() =>
    primaryAction.value === 'start'
        ? 'Start Dispensing'
        : primaryAction.value === 'save'
          ? unsavedCount.value === 1
              ? 'Save 1 line'
              : `Save all ${unsavedCount.value} lines`
          : 'Complete Dispensary',
);
// The same disabled rule as Classic's Complete button.
const completeDisabled = computed(
    () =>
        busy.value ||
        unsaved.value.length > 0 ||
        unsavedServices.value.length > 0 ||
        (isOtc.value && !otcAllergyConfirmed.value),
);
const primaryDisabled = computed(() =>
    primaryAction.value === 'complete' ? completeDisabled.value : busy.value,
);
const runPrimary = () => {
    if (primaryDisabled.value) {
        return;
    }

    // Hakim: clear first, so the same refusal pressed twice is brought into view again.
    actionError.value = null;

    if (primaryAction.value === 'start') {
        caseAction('start');
    } else if (primaryAction.value === 'save') {
        saveAll();
    } else if (primaryAction.value === 'complete') {
        confirming.value = true;
    }
};
// Verify: the dialog ignores a second press while completing.
const completing = ref(false);
const confirmComplete = () => {
    if (completing.value || completeDisabled.value) {
        return;
    }

    completing.value = true;
    busy.value = true;
    actionError.value = null;
    router.post(
        `/dispensary/${props.dispensary.publicId}/complete`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                confirming.value = false;
                showActionError(errors);
            },
            onFinish: () => {
                busy.value = false;
                completing.value = false;
            },
        },
    );
};
// Return to Doctor: Classic posts at once; V2 asks first. The payload is unchanged (no reason: the server takes none).
const returnOpen = ref(false);
const confirmReturn = () => {
    returnOpen.value = false;
    caseAction('return-to-doctor');
};
const quantityText = (quantity: string | null) =>
    quantity === null
        ? '—'
        : quantity.includes('.')
          ? quantity.replace(/\.?0+$/, '')
          : quantity;
// Daniel: read-only quantities read as words ("10 tablets"); the fields keep the exact thousandths.
const countUnits = new Set([
    'tablet',
    'capsule',
    'sachet',
    'bottle',
    'tube',
    'vial',
    'ampoule',
    'strip',
    'box',
    'pack',
    'piece',
    'patch',
    'suppository',
    'lozenge',
    'service',
    'session',
]);
const readQuantity = (
    quantity: string | number | null | undefined,
    unit: string | null | undefined,
) => {
    const amount = quantityText(
        quantity === null || quantity === undefined ? null : String(quantity),
    );
    const word = (unit ?? '').trim();

    if (word === '') {
        return amount;
    }

    const plural =
        amount !== '1' && countUnits.has(word.toLowerCase())
            ? word.toLowerCase().endsWith('x')
                ? `${word}es`
                : `${word}s`
            : word;

    return `${amount} ${plural}`;
};
const batchText = (item: Item) =>
    (item.allocations ?? [])
        .map((allocation) => allocation.batchNumber)
        .filter((batch): batch is string => !!batch)
        .join(', ') || 'Not chosen';
const canPrintAll = computed(
    () =>
        visibleItems.value.length > 0 &&
        props.dispensary.canPrintLabels &&
        unsavedCount.value === 0,
);
const labelsUrl = computed(
    () => `/dispensary/${props.dispensary.publicId}/labels`,
);
const callNeedsRoom = computed(
    () =>
        (props.dispensary.tvCall?.rooms.length ?? 0) > 1 &&
        callRoomId.value === '',
);
// Phones: Call patient, Return to Doctor and Print all labels go in an "Actions" menu (Daniel, condition 2).
const actionGroups = computed<RowMenuGroup[]>(() => {
    const groups: RowMenuGroup[] = [];

    if (props.dispensary.can.return) {
        groups.push({
            label: 'Send back to the doctor',
            items: [
                {
                    id: 'return',
                    label: 'Return to Doctor',
                    allowed: !busy.value,
                },
            ],
        });
    }

    const call = [];

    if (props.dispensary.tvCall?.canCall) {
        call.push({
            id: 'call',
            label: 'Call patient',
            allowed: !busy.value && !callNeedsRoom.value,
            reason: callNeedsRoom.value
                ? 'Choose a dispensary room under the counts first.'
                : undefined,
        });
    }

    if (call.length) {
        groups.push({ label: 'Call', items: call });
    }

    if (canPrintAll.value) {
        groups.push({
            label: 'Labels',
            items: [
                {
                    id: 'print-all',
                    label: 'Print all labels',
                    allowed: true,
                },
            ],
        });
    }

    return groups;
});
const onAction = (id: string) => {
    if (id === 'call') {
        callPatient();
    } else if (id === 'return') {
        returnOpen.value = true;
    } else if (id === 'print-all') {
        window.open(labelsUrl.value, '_blank', 'noopener,noreferrer');
    }
};
const allergyNames = computed(() =>
    props.dispensary.allergySafety.allergies.map((allergy) => allergy.allergen),
);
// Daniel: three allergy states in words; "not recorded" must never look like "no allergies".
const allergyState = computed<'none' | 'listed' | 'unrecorded'>(() =>
    props.dispensary.allergySafety.allergies.length > 0
        ? 'listed'
        : props.dispensary.allergySafety.status === 'no_known_allergies'
          ? 'none'
          : 'unrecorded',
);
const allergyHeading = computed(() =>
    allergyState.value === 'listed'
        ? `Allergies (${props.dispensary.allergySafety.allergies.length})`
        : allergyState.value === 'none'
          ? 'No known allergies'
          : 'Allergies not recorded',
);
const otcOptions = [
    { value: 'none', label: 'Patient reports no allergy' },
    { value: 'has_allergy', label: 'Patient reports an allergy' },
];
// Hakim: an error can appear far above the sticky bar on a phone; bring it into view and focus it. The focus waits one
// task so it lands after a closing dialog has handed focus back to its trigger (a failed Complete closes Verify).
const actionErrorRef = ref<HTMLElement | null>(null);
// Daniel: a refusal (for example Complete refused: stock short, a batch held or expired) shows in words next to the
// filled button, in the phone and tablet bar or in the rail, whichever is on screen; the top alert is only for a page
// with no filled action.
const actionErrorBarRef = ref<HTMLElement | null>(null);
const actionErrorRailRef = ref<HTMLElement | null>(null);
const shownActionError = () =>
    [
        actionErrorBarRef.value,
        actionErrorRailRef.value,
        actionErrorRef.value,
    ].find((node) => node !== null && node.offsetParent !== null) ?? null;
watch(
    actionError,
    (message) => {
        if (!message) {
            return;
        }

        void nextTick(() =>
            window.setTimeout(() => {
                const reduce = window.matchMedia?.(
                    '(prefers-reduced-motion: reduce)',
                ).matches;

                shownActionError()?.scrollIntoView({
                    block: 'center',
                    behavior: reduce ? 'auto' : 'smooth',
                });
                shownActionError()?.focus({ preventScroll: true });
            }, 0),
        );
    },
    // Hakim: sync, so clearing the error and setting the same text again in one tick still runs (a second press of
    // "Save all" with the same refusal); the callback only schedules the scroll and focus.
    { flush: 'sync' },
);
// Hakim: tie the reason Complete is blocked to the button, for screen readers.
const primaryDescribedBy = computed(() =>
    primaryAction.value === 'complete' && completeBlockedReason.value
        ? 'dispensary-complete-blocked-reason'
        : undefined,
);
const primaryBarDescribedBy = computed(() =>
    primaryAction.value === 'complete' && completeBlockedReason.value
        ? 'dispensary-complete-blocked-reason-phone'
        : undefined,
);
// Daniel: the label budget shows only while the field is focused or near its limit; over it, the field turns red and
// says by how much. The budgets are the server's (labelLimits); a long text still saves and the label then blocks.
const focusedField = ref<string | null>(null);
const labelOver = (value: string | null | undefined, limit?: number | null) =>
    limit ? Math.max(0, (value ?? '').length - limit) : 0;
const labelHint = (
    value: string | null | undefined,
    limit: number | null | undefined,
    key: string,
) => {
    if (!limit) {
        return '';
    }

    const over = labelOver(value, limit);

    if (over > 0) {
        return `${over} character${over === 1 ? '' : 's'} over the label limit`;
    }

    return focusedField.value === key || limit - (value ?? '').length <= 10
        ? `Label max ${limit} characters`
        : '';
};
// Announce an allergy match only when it appears after the page has loaded (an add or edit), never on load.
const pageReady = ref(false);
onMounted(() => {
    pageReady.value = true;
});
const outlineButton =
    'inline-flex h-11 w-full items-center justify-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60 lg:h-9';
const filledButton =
    'inline-flex h-11 w-full items-center justify-center gap-2 rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none aria-disabled:cursor-not-allowed aria-disabled:opacity-60 lg:h-9';
</script>

<template>
    <Head title="Dispensary" />
    <PageFrame>
        <V2WorkLayout
            title="Dispensary"
            :eyebrow="isOtc ? 'OTC visit' : 'Consultation visit'"
            description="Check each line, save it, then complete."
            rail-label="This dispensary"
            rail-from="2xl"
        >
            <template v-if="actionGroups.length" #actions>
                <div class="sm:hidden" data-testid="dispensary-actions-menu">
                    <V2RowMenu
                        label="Actions"
                        show-label
                        :groups="actionGroups"
                        :busy="busy"
                        @select="onAction"
                    />
                </div>
            </template>
            <template #main>
                <!-- Daniel's ruling: the patient and allergy card is the first card at every width; it never moves to the rail. -->
                <section
                    class="rounded-[14px] border bg-card p-4"
                    data-testid="dispensary-patient"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p
                                class="text-[13px] font-medium text-muted-foreground"
                            >
                                Patient
                            </p>
                            <h2
                                class="text-[17px] leading-snug font-semibold break-words"
                            >
                                {{ dispensary.patient.name }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ dispensary.patient.patientNumber }} ·
                                {{
                                    isOtc
                                        ? 'OTC · no doctor'
                                        : `Doctor: ${dispensary.doctor ?? 'No doctor'}`
                                }}
                            </p>
                        </div>
                        <V2Chip
                            v-if="!isOtc"
                            :tone="
                                dispensary.allergySafety.isCurrent
                                    ? 'success'
                                    : 'warning'
                            "
                            :label="
                                dispensary.allergySafety.isCurrent
                                    ? 'Allergy check current'
                                    : 'Return to doctor required'
                            "
                        />
                    </div>
                    <div
                        v-if="!isOtc"
                        class="mt-3 rounded-[10px] border p-3 text-sm"
                        :class="
                            allergyState === 'none'
                                ? 'border-border bg-muted/40'
                                : 'border-warning/30 bg-warning/10'
                        "
                        :data-allergy-state="allergyState"
                        data-testid="dispensary-allergies"
                    >
                        <p class="flex items-center gap-2 font-semibold">
                            <TriangleAlert
                                v-if="allergyState !== 'none'"
                                class="size-4 shrink-0 text-warning-strong"
                                aria-hidden="true"
                            />{{ allergyHeading }}
                        </p>
                        <ul
                            v-if="dispensary.allergySafety.allergies.length"
                            class="mt-1 flex flex-col gap-1"
                        >
                            <li
                                v-for="(allergy, index) in dispensary
                                    .allergySafety.allergies"
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
                    </div>
                    <div
                        v-else
                        class="mt-3 rounded-[10px] border p-3 text-sm"
                        :class="
                            otcAllergyConfirmed
                                ? 'border-success/30 bg-success/10'
                                : 'border-warning/30 bg-warning/10'
                        "
                        data-testid="otc-allergy"
                    >
                        <p class="text-[15px] font-semibold">
                            Ask the patient about allergies
                        </p>
                        <p class="text-[13px] text-foreground/75">
                            Record what the patient tells you. Saved allergies
                            are compared with medicine names and catalogue drug
                            groups by text only, so the check can miss one.
                            Always ask.
                        </p>
                        <template
                            v-if="dispensary.allergySafety.allergies.length"
                        >
                            <p class="mt-2 font-medium">Recorded allergies</p>
                            <ul class="mt-1 flex flex-col gap-1">
                                <li
                                    v-for="(allergy, index) in dispensary
                                        .allergySafety.allergies"
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
                        </template>
                        <div v-if="dispensary.can.update" class="mt-3">
                            <V2OptionGroup
                                v-model="otcAllergyChoice"
                                :options="otcOptions"
                                label="What the patient reports about allergies"
                            />
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
                            <button
                                v-else-if="
                                    otcAllergyChoice === 'has_allergy' &&
                                    dispensary.can.addOtcAllergy &&
                                    otcHasRecordedAllergy
                                "
                                type="button"
                                :class="[outlineButton, 'mt-2 lg:w-auto']"
                                data-action="open-otc-allergy-form"
                                @click="otcAddingAnother = true"
                            >
                                Add another allergy
                            </button>
                            <p
                                v-if="
                                    otcAllergyChoice === 'has_allergy' &&
                                    !otcHasRecordedAllergy &&
                                    !dispensary.can.addOtcAllergy
                                "
                                class="mt-2"
                                data-testid="otc-allergy-readonly"
                            >
                                No allergy recorded. You cannot add allergies
                                here; ask a supervisor to check your access.
                            </p>
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <button
                                    type="button"
                                    :class="[outlineButton, 'lg:w-auto']"
                                    :disabled="busy || otcConfirmBlocked"
                                    data-action="confirm-otc-allergy"
                                    @click="confirmOtcAllergy"
                                >
                                    {{
                                        otcAllergyConfirmed
                                            ? 'Update'
                                            : 'Confirm'
                                    }}
                                </button>
                                <V2Chip
                                    v-if="otcAllergyConfirmed"
                                    tone="success"
                                    label="Recorded"
                                />
                                <span
                                    v-else-if="
                                        otcAllergyChoice === 'has_allergy' &&
                                        !otcHasRecordedAllergy
                                    "
                                    class="text-[13px] text-foreground/75"
                                    >Add the allergy before you confirm.</span
                                >
                                <span
                                    v-else-if="!otcAllergyChoice"
                                    class="text-[13px] text-foreground/75"
                                    >Choose what the patient reports, then
                                    confirm.</span
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Phones: the counts fold into one bar (the summary rail is hidden below 640 px). -->
                <div class="sm:hidden" data-testid="dispensary-counts-bar">
                    <V2DisclosureBar
                        :title="countsTitle"
                        :tone="unsavedCount ? 'warning' : 'neutral'"
                    >
                        <dl class="flex flex-col gap-2 text-sm">
                            <div
                                v-for="item in summaryItems"
                                :key="item.label"
                                class="flex justify-between gap-3"
                            >
                                <dt class="text-muted-foreground">
                                    {{ item.label }}
                                </dt>
                                <dd class="font-medium tabular-nums">
                                    {{ item.value }}
                                </dd>
                            </div>
                        </dl>
                        <OperationalSelect
                            v-if="
                                dispensary.tvCall?.canCall &&
                                dispensary.tvCall.rooms.length > 1
                            "
                            v-model="callRoomId"
                            class="mt-3"
                            label="Dispensary room"
                            placeholder="Choose room"
                            trigger-class="h-11 w-full"
                            :options="
                                dispensary.tvCall.rooms.map((room) => ({
                                    value: room.id,
                                    label: room.name,
                                }))
                            "
                        />
                    </V2DisclosureBar>
                </div>

                <p
                    v-if="actionError && !primaryAction"
                    ref="actionErrorRef"
                    role="alert"
                    tabindex="-1"
                    class="rounded-[10px] border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {{ actionError }}
                </p>
                <div
                    v-if="dispensary.can.update && unsavedCount"
                    class="rounded-[10px] border border-warning/30 bg-warning/10 px-3 py-2 text-sm"
                    data-testid="dispensary-unsaved"
                >
                    {{ unsavedCount }} line{{ unsavedCount === 1 ? '' : 's' }}
                    not saved yet. Save each line to confirm it, then complete.
                </div>
                <!-- Daniel: scan to check (DSP-11) sits behind a closed "Check by scan" bar so the lines are not pushed
                     down. The panel stays mounted (v-show), so a wrong-medicine warning and the scan status survive closing. -->
                <section
                    v-if="dispensary.can.update && visibleItems.length"
                    class="rounded-[14px] border bg-card"
                    data-testid="dispensary-check-scan"
                >
                    <button
                        type="button"
                        :aria-expanded="checkScanOpen"
                        aria-controls="dispensary-check-scan-panel"
                        class="flex min-h-11 w-full items-center justify-between gap-3 rounded-[14px] px-4 py-2 text-left text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:min-h-9"
                        data-action="check-by-scan"
                        @click="toggleCheckScan"
                    >
                        <span class="flex min-w-0 items-center gap-2"
                            ><ScanLine
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />Check by scan
                            <span class="font-normal text-muted-foreground"
                                >{{ scanChecked }} of
                                {{ scanTotal }} checked</span
                            ></span
                        >
                        <ChevronDown
                            class="size-4 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none"
                            :class="checkScanOpen ? 'rotate-180' : ''"
                            aria-hidden="true"
                        />
                    </button>
                    <div
                        v-show="checkScanOpen"
                        id="dispensary-check-scan-panel"
                        class="border-t p-3"
                    >
                        <ScanInput
                            context="dispensary"
                            refocus-key="dispensary-check"
                            lookup-variant="outline"
                            help="Scanning only checks. It doesn't save anything or check the quantity or batch."
                            hide-unavailable-camera
                            class="dark:border-foreground/20"
                            :accept="acceptScan"
                            :describe="describeScan"
                            data-testid="dispensary-scan"
                            @keydown.enter.capture="clearLineHint"
                            @found="markScanned"
                        />
                    </div>
                </section>
                <p
                    v-if="dispensary.can.update && visibleItems.length"
                    role="status"
                    class="sr-only"
                    data-testid="scan-batch-announcement"
                >
                    {{ scanBatchAnnouncement }}
                </p>
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
                            <StatusBadge
                                v-if="changeLabel(item)"
                                class="mt-1"
                                status="ca_change"
                                tone="accent"
                                :label="changeLabel(item)"
                            />
                            <StatusBadge
                                v-if="isScanChecked(item)"
                                class="mt-1 ml-1"
                                status="scan_checked"
                                tone="success"
                                label="Checked by scan"
                                data-testid="scan-checked"
                            />
                            <span v-if="isScanChecked(item)" class="sr-only"
                                >, not saved</span
                            >
                            <p
                                v-if="scanBatch(item)"
                                class="mt-1 flex items-start gap-1 text-xs font-medium"
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
                                v-if="item.source === 'doctor'"
                                class="mt-2 text-xs text-muted-foreground"
                            >
                                Doctor ordered
                                <strong class="text-foreground">{{
                                    readQuantity(
                                        item.quantityOrdered,
                                        item.unit,
                                    )
                                }}</strong>
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
                                class="mt-1 text-xs text-amber-700 dark:text-amber-300"
                            >
                                Earlier partial dispense:
                                {{
                                    exceptionStatusLabels[
                                        item.exception.status
                                    ] ?? 'status unknown'
                                }}
                            </p>
                            <Button
                                v-if="
                                    item.status === 'dispensed' &&
                                    dispensary.canPrintLabels &&
                                    !unsaved.length &&
                                    !unsavedServices.length &&
                                    !isDirty(item)
                                "
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
                                    <Printer class="size-3.5" />Print label
                                    <span class="sr-only"
                                        >for {{ item.name }} (opens print
                                        preview in a new tab)</span
                                    >
                                </a>
                            </Button>
                        </div>
                        <div
                            v-if="dispensary.can.update && forms[item.publicId]"
                            class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <div
                                class="sm:col-span-full"
                                data-testid="label-limits"
                            >
                                <V2DisclosureBar title="Label limits">
                                    <p
                                        class="text-[13px] text-muted-foreground"
                                    >
                                        Label limits (characters; saved clinical
                                        text is unchanged): dosage
                                        {{ dispensary.labelLimits?.dosage }},
                                        frequency
                                        {{ dispensary.labelLimits?.frequency }},
                                        duration
                                        {{ dispensary.labelLimits?.duration }},
                                        instruction
                                        {{
                                            dispensary.labelLimits?.instruction
                                        }}, precaution
                                        {{
                                            dispensary.labelLimits?.precaution
                                        }}, quantity
                                        {{ dispensary.labelLimits?.quantity }}.
                                        Dosage, frequency and instruction share
                                        3 printed rows. Route is not printed.
                                        Over-budget labels are blocked, never
                                        truncated.
                                    </p>
                                </V2DisclosureBar>
                            </div>
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
                                    :class="[
                                        inputClass,
                                        labelOver(
                                            forms[item.publicId].dosage,
                                            dispensary.labelLimits?.dosage,
                                        )
                                            ? 'border-destructive'
                                            : '',
                                    ]"
                                    :aria-invalid="
                                        labelOver(
                                            forms[item.publicId].dosage,
                                            dispensary.labelLimits?.dosage,
                                        ) > 0
                                    "
                                    @focus="
                                        focusedField =
                                            'forms[item.publicId].dosage'
                                    "
                                    @blur="focusedField = null"
                                    maxlength="500"
                                    data-field="dosage"
                                /><span
                                    v-if="
                                        labelHint(
                                            forms[item.publicId].dosage,
                                            dispensary.labelLimits?.dosage,
                                            'forms[item.publicId].dosage',
                                        )
                                    "
                                    class="mt-1 block text-[13px]"
                                    :class="
                                        labelOver(
                                            forms[item.publicId].dosage,
                                            dispensary.labelLimits?.dosage,
                                        )
                                            ? 'font-medium text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    >{{
                                        labelHint(
                                            forms[item.publicId].dosage,
                                            dispensary.labelLimits?.dosage,
                                            'forms[item.publicId].dosage',
                                        )
                                    }}</span
                                ></label
                            >
                            <label
                                >Frequency<input
                                    v-model="forms[item.publicId].frequency"
                                    :class="[
                                        inputClass,
                                        labelOver(
                                            forms[item.publicId].frequency,
                                            dispensary.labelLimits?.frequency,
                                        )
                                            ? 'border-destructive'
                                            : '',
                                    ]"
                                    :aria-invalid="
                                        labelOver(
                                            forms[item.publicId].frequency,
                                            dispensary.labelLimits?.frequency,
                                        ) > 0
                                    "
                                    @focus="
                                        focusedField =
                                            'forms[item.publicId].frequency'
                                    "
                                    @blur="focusedField = null"
                                    maxlength="500"
                                    data-field="frequency"
                                /><span
                                    v-if="
                                        labelHint(
                                            forms[item.publicId].frequency,
                                            dispensary.labelLimits?.frequency,
                                            'forms[item.publicId].frequency',
                                        )
                                    "
                                    class="mt-1 block text-[13px]"
                                    :class="
                                        labelOver(
                                            forms[item.publicId].frequency,
                                            dispensary.labelLimits?.frequency,
                                        )
                                            ? 'font-medium text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    >{{
                                        labelHint(
                                            forms[item.publicId].frequency,
                                            dispensary.labelLimits?.frequency,
                                            'forms[item.publicId].frequency',
                                        )
                                    }}</span
                                ></label
                            >
                            <label
                                >Duration<input
                                    v-model="forms[item.publicId].duration"
                                    :class="[
                                        inputClass,
                                        labelOver(
                                            forms[item.publicId].duration,
                                            dispensary.labelLimits?.duration,
                                        )
                                            ? 'border-destructive'
                                            : '',
                                    ]"
                                    :aria-invalid="
                                        labelOver(
                                            forms[item.publicId].duration,
                                            dispensary.labelLimits?.duration,
                                        ) > 0
                                    "
                                    @focus="
                                        focusedField =
                                            'forms[item.publicId].duration'
                                    "
                                    @blur="focusedField = null"
                                    maxlength="500"
                                    data-field="duration"
                                /><span
                                    v-if="
                                        labelHint(
                                            forms[item.publicId].duration,
                                            dispensary.labelLimits?.duration,
                                            'forms[item.publicId].duration',
                                        )
                                    "
                                    class="mt-1 block text-[13px]"
                                    :class="
                                        labelOver(
                                            forms[item.publicId].duration,
                                            dispensary.labelLimits?.duration,
                                        )
                                            ? 'font-medium text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    >{{
                                        labelHint(
                                            forms[item.publicId].duration,
                                            dispensary.labelLimits?.duration,
                                            'forms[item.publicId].duration',
                                        )
                                    }}</span
                                ></label
                            >
                            <label
                                >Route<input
                                    v-model="forms[item.publicId].route"
                                    :class="inputClass"
                                    maxlength="500"
                                    data-field="route"
                                /><span
                                    class="mt-1 block text-[13px] text-muted-foreground"
                                    >Not printed on the label</span
                                ></label
                            >
                            <label class="sm:col-span-2"
                                >Instruction<input
                                    v-model="forms[item.publicId].instruction"
                                    :class="[
                                        inputClass,
                                        labelOver(
                                            forms[item.publicId].instruction,
                                            dispensary.labelLimits?.instruction,
                                        )
                                            ? 'border-destructive'
                                            : '',
                                    ]"
                                    :aria-invalid="
                                        labelOver(
                                            forms[item.publicId].instruction,
                                            dispensary.labelLimits?.instruction,
                                        ) > 0
                                    "
                                    @focus="
                                        focusedField =
                                            'forms[item.publicId].instruction'
                                    "
                                    @blur="focusedField = null"
                                    maxlength="2000"
                                    data-field="instruction"
                                /><span
                                    v-if="
                                        labelHint(
                                            forms[item.publicId].instruction,
                                            dispensary.labelLimits?.instruction,
                                            'forms[item.publicId].instruction',
                                        )
                                    "
                                    class="mt-1 block text-[13px]"
                                    :class="
                                        labelOver(
                                            forms[item.publicId].instruction,
                                            dispensary.labelLimits?.instruction,
                                        )
                                            ? 'font-medium text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    >{{
                                        labelHint(
                                            forms[item.publicId].instruction,
                                            dispensary.labelLimits?.instruction,
                                            'forms[item.publicId].instruction',
                                        )
                                    }}</span
                                ></label
                            >
                            <label class="sm:col-span-2"
                                >Precaution<input
                                    v-model="forms[item.publicId].precaution"
                                    :class="[
                                        inputClass,
                                        labelOver(
                                            forms[item.publicId].precaution,
                                            dispensary.labelLimits?.precaution,
                                        )
                                            ? 'border-destructive'
                                            : '',
                                    ]"
                                    :aria-invalid="
                                        labelOver(
                                            forms[item.publicId].precaution,
                                            dispensary.labelLimits?.precaution,
                                        ) > 0
                                    "
                                    @focus="
                                        focusedField =
                                            'forms[item.publicId].precaution'
                                    "
                                    @blur="focusedField = null"
                                    maxlength="2000"
                                    data-field="precaution"
                                /><span
                                    v-if="
                                        labelHint(
                                            forms[item.publicId].precaution,
                                            dispensary.labelLimits?.precaution,
                                            'forms[item.publicId].precaution',
                                        )
                                    "
                                    class="mt-1 block text-[13px]"
                                    :class="
                                        labelOver(
                                            forms[item.publicId].precaution,
                                            dispensary.labelLimits?.precaution,
                                        )
                                            ? 'font-medium text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    >{{
                                        labelHint(
                                            forms[item.publicId].precaution,
                                            dispensary.labelLimits?.precaution,
                                            'forms[item.publicId].precaution',
                                        )
                                    }}</span
                                ></label
                            >
                            <div class="sm:col-span-2">
                                <span
                                    :id="`batch-label-${item.publicId}`"
                                    class="cursor-pointer"
                                    @click="
                                        openSelect(
                                            `batch-label-${item.publicId}`,
                                        )
                                    "
                                    >Batch</span
                                >
                                <div :data-batch-select="item.publicId">
                                    <OperationalSelect
                                        v-model="
                                            forms[item.publicId]
                                                .availabilityIndex
                                        "
                                        label="Batch"
                                        :labelledby="`batch-label-${item.publicId}`"
                                        :describedby="`batch-selected-${item.publicId}`"
                                        :options="
                                            batchOptions(
                                                item.publicId,
                                                item.availability,
                                                item.sku?.unit ?? item.unit,
                                            )
                                        "
                                        :content-avoid-collisions="false"
                                        :trigger-class="
                                            [
                                                selectTriggerClass,
                                                batchHint === item.publicId &&
                                                    batchHintClass,
                                            ]
                                                .filter(Boolean)
                                                .join(' ')
                                        "
                                        @update:model-value="
                                            clearBatchHint(item.publicId)
                                        "
                                    />
                                </div>
                            </div>
                            <p
                                :id="`batch-selected-${item.publicId}`"
                                class="text-xs text-muted-foreground sm:col-span-full"
                                data-testid="batch-selected"
                            >
                                <span
                                    role="status"
                                    class="block font-medium text-brand-strong empty:hidden"
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
                                    )
                                }}
                            </p>
                            <div class="sm:col-span-2">
                                <span
                                    :id="`fefo-label-${item.publicId}`"
                                    class="cursor-pointer"
                                    @click="
                                        openSelect(
                                            `fefo-label-${item.publicId}`,
                                        )
                                    "
                                    >FEFO exception reason (later batch
                                    only)</span
                                >
                                <OperationalSelect
                                    v-model="forms[item.publicId].fefoReason"
                                    label="FEFO exception reason (later batch only)"
                                    :labelledby="`fefo-label-${item.publicId}`"
                                    :options="fefoOptions"
                                    :trigger-class="selectTriggerClass"
                                />
                            </div>
                            <p
                                class="text-xs text-muted-foreground sm:col-span-2"
                            >
                                Up to 100 batches shown. Held batches are
                                visible but cannot be selected. A later batch
                                needs a controlled reason when an eligible
                                earlier-expiry batch exists.
                            </p>
                            <p
                                v-if="!item.sku"
                                class="text-xs text-amber-700 sm:col-span-2 dark:text-amber-300"
                            >
                                No approved inventory SKU mapping.
                            </p>
                            <p
                                v-else-if="!item.availability.length"
                                class="text-xs text-amber-700 sm:col-span-2 dark:text-amber-300"
                            >
                                No eligible stock in this branch's Dispensary.
                                Stock at other locations requires transfer
                                before fulfilment.
                            </p>
                            <AllergyMatchWarning
                                :announce="pageReady"
                                v-if="matchesFor(item).length"
                                v-model="allergyChecks[checkKey(item)]"
                                :matches="matchesFor(item)"
                                :field-id="`allergy-check-${item.publicId}`"
                                class="sm:col-span-full"
                            />
                            <p
                                v-if="item.allergyUnconfirmed"
                                class="text-xs font-medium sm:col-span-full"
                                data-testid="allergy-resave-hint"
                            >
                                This medicine matches an allergy recorded after
                                it was saved. Tick the box, then press Save this
                                line.
                            </p>
                            <p
                                v-if="needsAllergyCheck(item)"
                                :id="`save-blocked-${item.publicId}`"
                                class="text-[13px] font-medium text-destructive sm:col-span-full"
                                data-testid="save-blocked-allergy"
                            >
                                Tick the allergy check to save this line.
                            </p>
                            <div class="flex items-end gap-2 sm:col-span-full">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="
                                        busy ||
                                        (item.status !== 'pending' &&
                                            !isDirty(item) &&
                                            !item.allergyUnconfirmed)
                                    "
                                    :aria-disabled="needsAllergyCheck(item)"
                                    :aria-describedby="
                                        needsAllergyCheck(item)
                                            ? `save-blocked-${item.publicId}`
                                            : undefined
                                    "
                                    class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
                                    data-action="save-line"
                                    @click="
                                        needsAllergyCheck(item)
                                            ? null
                                            : saveItem(item)
                                    "
                                    ><LoaderCircle
                                        v-if="busy"
                                        class="size-4 animate-spin"
                                    />Save this line</Button
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
                                <span class="text-muted-foreground"
                                    >Quantity</span
                                ><br />{{
                                    item.quantityDispensed ??
                                    item.quantityOrdered
                                }}
                                {{ item.unit }}
                            </p>
                            <p>
                                <span class="text-muted-foreground">Dosage</span
                                ><br />{{ item.dosage }}
                            </p>
                            <p>
                                <span class="text-muted-foreground"
                                    >Frequency</span
                                ><br />{{ item.frequency }}
                            </p>
                            <p>
                                <span class="text-muted-foreground"
                                    >Duration</span
                                ><br />{{ item.duration ?? 'Not recorded' }}
                            </p>
                        </dl>
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
                                :announce="pageReady"
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
                    class="mt-3 rounded-lg border p-3 text-sm"
                    data-testid="add-medicine"
                >
                    <h2
                        class="mb-2 text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Add a medicine
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <input
                            v-model="searchTerm"
                            class="h-9 w-full rounded-md border px-2 sm:max-w-sm"
                            placeholder="Search medicine name or code"
                            aria-label="Search medicine"
                            data-field="medicine-search"
                            @keydown.enter.prevent="searchMedicines"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="searching || busy"
                            class="flex-1 max-lg:min-h-11 sm:flex-none"
                            @click="searchMedicines"
                            ><Plus class="size-4" />Search</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            class="flex-1 max-lg:min-h-11 sm:flex-none"
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
                            lookup-variant="outline"
                            help="Scanning only finds the medicine. You still set the quantity, directions and batch, then press Add."
                            hide-unavailable-camera
                            class="dark:border-foreground/20"
                            :describe="() => ''"
                            data-testid="dispensary-scan-add"
                            @found="scanToAdd"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            class="justify-self-start max-lg:min-h-11"
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
                    <!-- Daniel: the scan result is announced in words. The polite region stays in the page so a
                         screen reader hears each new message; it is empty while the allergy alert speaks. -->
                    <p
                        role="status"
                        aria-live="polite"
                        class="text-sm text-foreground"
                        :class="
                            scanAddMessage && !scanAddAllergy
                                ? 'mt-2'
                                : 'sr-only'
                        "
                        data-testid="scan-add-status"
                    >
                        {{ scanAddAllergy ? '' : scanAddMessage }}
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
                                    class="ml-1 inline-block rounded-md bg-red-100 px-1.5 py-0.5 text-[11px] font-medium text-red-800 dark:bg-red-950/70 dark:text-red-100"
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
                        class="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <p class="text-sm font-medium sm:col-span-full">
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
                                :class="[
                                    inputClass,
                                    labelOver(
                                        addForm.dosage,
                                        dispensary.labelLimits?.dosage,
                                    )
                                        ? 'border-destructive'
                                        : '',
                                ]"
                                :aria-invalid="
                                    labelOver(
                                        addForm.dosage,
                                        dispensary.labelLimits?.dosage,
                                    ) > 0
                                "
                                @focus="focusedField = 'addForm.dosage'"
                                @blur="focusedField = null"
                                maxlength="500"
                                data-field="add-dosage"
                            /><span
                                v-if="
                                    labelHint(
                                        addForm.dosage,
                                        dispensary.labelLimits?.dosage,
                                        'addForm.dosage',
                                    )
                                "
                                class="mt-1 block text-[13px]"
                                :class="
                                    labelOver(
                                        addForm.dosage,
                                        dispensary.labelLimits?.dosage,
                                    )
                                        ? 'font-medium text-destructive'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    labelHint(
                                        addForm.dosage,
                                        dispensary.labelLimits?.dosage,
                                        'addForm.dosage',
                                    )
                                }}</span
                            ></label
                        >
                        <label
                            >Frequency<input
                                v-model="addForm.frequency"
                                :class="[
                                    inputClass,
                                    labelOver(
                                        addForm.frequency,
                                        dispensary.labelLimits?.frequency,
                                    )
                                        ? 'border-destructive'
                                        : '',
                                ]"
                                :aria-invalid="
                                    labelOver(
                                        addForm.frequency,
                                        dispensary.labelLimits?.frequency,
                                    ) > 0
                                "
                                @focus="focusedField = 'addForm.frequency'"
                                @blur="focusedField = null"
                                maxlength="500"
                                data-field="add-frequency"
                            /><span
                                v-if="
                                    labelHint(
                                        addForm.frequency,
                                        dispensary.labelLimits?.frequency,
                                        'addForm.frequency',
                                    )
                                "
                                class="mt-1 block text-[13px]"
                                :class="
                                    labelOver(
                                        addForm.frequency,
                                        dispensary.labelLimits?.frequency,
                                    )
                                        ? 'font-medium text-destructive'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    labelHint(
                                        addForm.frequency,
                                        dispensary.labelLimits?.frequency,
                                        'addForm.frequency',
                                    )
                                }}</span
                            ></label
                        >
                        <label
                            >Duration<input
                                v-model="addForm.duration"
                                :class="[
                                    inputClass,
                                    labelOver(
                                        addForm.duration,
                                        dispensary.labelLimits?.duration,
                                    )
                                        ? 'border-destructive'
                                        : '',
                                ]"
                                :aria-invalid="
                                    labelOver(
                                        addForm.duration,
                                        dispensary.labelLimits?.duration,
                                    ) > 0
                                "
                                @focus="focusedField = 'addForm.duration'"
                                @blur="focusedField = null"
                                maxlength="500"
                            /><span
                                v-if="
                                    labelHint(
                                        addForm.duration,
                                        dispensary.labelLimits?.duration,
                                        'addForm.duration',
                                    )
                                "
                                class="mt-1 block text-[13px]"
                                :class="
                                    labelOver(
                                        addForm.duration,
                                        dispensary.labelLimits?.duration,
                                    )
                                        ? 'font-medium text-destructive'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    labelHint(
                                        addForm.duration,
                                        dispensary.labelLimits?.duration,
                                        'addForm.duration',
                                    )
                                }}</span
                            ></label
                        >
                        <label
                            >Route<input
                                v-model="addForm.route"
                                :class="inputClass"
                                maxlength="500"
                            /><span
                                class="mt-1 block text-[13px] text-muted-foreground"
                                >Not printed on the label</span
                            ></label
                        >
                        <label class="sm:col-span-2"
                            >Instruction<input
                                v-model="addForm.instruction"
                                :class="[
                                    inputClass,
                                    labelOver(
                                        addForm.instruction,
                                        dispensary.labelLimits?.instruction,
                                    )
                                        ? 'border-destructive'
                                        : '',
                                ]"
                                :aria-invalid="
                                    labelOver(
                                        addForm.instruction,
                                        dispensary.labelLimits?.instruction,
                                    ) > 0
                                "
                                @focus="focusedField = 'addForm.instruction'"
                                @blur="focusedField = null"
                                maxlength="2000"
                            /><span
                                v-if="
                                    labelHint(
                                        addForm.instruction,
                                        dispensary.labelLimits?.instruction,
                                        'addForm.instruction',
                                    )
                                "
                                class="mt-1 block text-[13px]"
                                :class="
                                    labelOver(
                                        addForm.instruction,
                                        dispensary.labelLimits?.instruction,
                                    )
                                        ? 'font-medium text-destructive'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    labelHint(
                                        addForm.instruction,
                                        dispensary.labelLimits?.instruction,
                                        'addForm.instruction',
                                    )
                                }}</span
                            ></label
                        >
                        <label class="sm:col-span-2"
                            >Precaution<input
                                v-model="addForm.precaution"
                                :class="[
                                    inputClass,
                                    labelOver(
                                        addForm.precaution,
                                        dispensary.labelLimits?.precaution,
                                    )
                                        ? 'border-destructive'
                                        : '',
                                ]"
                                :aria-invalid="
                                    labelOver(
                                        addForm.precaution,
                                        dispensary.labelLimits?.precaution,
                                    ) > 0
                                "
                                @focus="focusedField = 'addForm.precaution'"
                                @blur="focusedField = null"
                                maxlength="2000"
                            /><span
                                v-if="
                                    labelHint(
                                        addForm.precaution,
                                        dispensary.labelLimits?.precaution,
                                        'addForm.precaution',
                                    )
                                "
                                class="mt-1 block text-[13px]"
                                :class="
                                    labelOver(
                                        addForm.precaution,
                                        dispensary.labelLimits?.precaution,
                                    )
                                        ? 'font-medium text-destructive'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    labelHint(
                                        addForm.precaution,
                                        dispensary.labelLimits?.precaution,
                                        'addForm.precaution',
                                    )
                                }}</span
                            ></label
                        >
                        <div class="sm:col-span-2">
                            <span
                                id="batch-label-add"
                                class="cursor-pointer"
                                @click="openSelect('batch-label-add')"
                                >Batch</span
                            >
                            <div data-batch-select="add">
                                <OperationalSelect
                                    v-model="addForm.availabilityIndex"
                                    label="Batch"
                                    labelledby="batch-label-add"
                                    describedby="batch-selected-add"
                                    :options="
                                        batchOptions(
                                            'add',
                                            chosen.availability,
                                            chosen.sku?.unit ?? chosen.unit,
                                        )
                                    "
                                    :content-avoid-collisions="false"
                                    :trigger-class="
                                        [
                                            selectTriggerClass,
                                            batchHint === 'add' &&
                                                batchHintClass,
                                        ]
                                            .filter(Boolean)
                                            .join(' ')
                                    "
                                    @update:model-value="clearBatchHint('add')"
                                />
                            </div>
                        </div>
                        <p
                            id="batch-selected-add"
                            class="text-xs text-muted-foreground sm:col-span-full"
                            data-testid="batch-selected"
                        >
                            <span
                                role="status"
                                class="block font-medium text-brand-strong empty:hidden"
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
                                )
                            }}
                        </p>
                        <div class="sm:col-span-full">
                            <span
                                id="fefo-label-add"
                                class="cursor-pointer"
                                @click="openSelect('fefo-label-add')"
                                >FEFO exception reason (later batch only)</span
                            >
                            <OperationalSelect
                                v-model="addForm.fefoReason"
                                label="FEFO exception reason (later batch only)"
                                labelledby="fefo-label-add"
                                :options="fefoOptions"
                                :trigger-class="selectTriggerClass"
                            />
                        </div>
                        <p
                            class="text-xs text-muted-foreground sm:col-span-full"
                        >
                            Up to 100 batches shown. Held batches cannot be
                            selected. A later batch needs a controlled reason
                            when an eligible earlier-expiry batch exists.
                        </p>
                        <div
                            class="sm:col-span-full"
                            data-testid="label-limits-add"
                        >
                            <V2DisclosureBar title="Label limits">
                                <p class="text-[13px] text-muted-foreground">
                                    Dosage
                                    {{ dispensary.labelLimits?.dosage }},
                                    frequency
                                    {{ dispensary.labelLimits?.frequency }},
                                    duration
                                    {{ dispensary.labelLimits?.duration }},
                                    instruction
                                    {{ dispensary.labelLimits?.instruction }},
                                    precaution
                                    {{ dispensary.labelLimits?.precaution }},
                                    quantity
                                    {{ dispensary.labelLimits?.quantity }}
                                    characters. Dosage, frequency and
                                    instruction share 3 printed rows. Route is
                                    not printed. Printing does not truncate
                                    saved text.
                                </p>
                            </V2DisclosureBar>
                        </div>
                        <AllergyMatchWarning
                            :announce="pageReady"
                            v-if="matchesFor(chosen).length"
                            v-model="addAllergyCheck"
                            :matches="matchesFor(chosen)"
                            field-id="allergy-check-add"
                            class="sm:col-span-full"
                        />
                        <div class="flex gap-2 sm:col-span-full">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="
                                    busy ||
                                    (matchesFor(chosen).length > 0 &&
                                        !addAllergyCheck) ||
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
                    v-if="!isOtc"
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
                                    <strong class="text-foreground">{{
                                        readQuantity(
                                            line.quantityOrdered,
                                            line.unit,
                                        )
                                    }}</strong>
                                    · confirmed
                                    <strong class="text-foreground">{{
                                        quantityText(
                                            line.original.quantity ?? '0',
                                        )
                                    }}</strong>
                                </p>
                                <p
                                    class="mt-1 text-xs"
                                    :class="
                                        line.disposition === 'performed'
                                            ? 'text-emerald-700 dark:text-emerald-300'
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
                                class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
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
                                        variant="outline"
                                        size="sm"
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
                            class="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"
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
                                    variant="outline"
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

                <!-- Below the rail (1536 px): the counts and actions are one card at the end of the main column
                     (from 640 px; phones use the counts bar and the Actions menu), and the filled action is the
                     sticky bar, so a long case reaches it without scrolling to the end (Daniel). -->
                <section
                    class="hidden flex-col gap-3 rounded-[14px] border bg-card p-4 sm:flex 2xl:hidden"
                    aria-label="This dispensary"
                    data-testid="dispensary-end-card"
                >
                    <h2 class="text-base leading-[22px] font-semibold">
                        This dispensary
                    </h2>
                    <dl class="flex flex-col gap-2 text-sm">
                        <div
                            v-for="item in summaryItems"
                            :key="item.label"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-muted-foreground">
                                {{ item.label }}
                            </dt>
                            <dd class="text-right font-medium tabular-nums">
                                {{ item.value }}
                            </dd>
                        </div>
                    </dl>
                    <div class="flex flex-col gap-2 sm:max-w-sm">
                        <OperationalSelect
                            v-if="
                                dispensary.tvCall?.canCall &&
                                dispensary.tvCall.rooms.length > 1
                            "
                            v-model="callRoomId"
                            label="Dispensary room"
                            placeholder="Choose room"
                            trigger-class="h-11 w-full lg:h-9"
                            :options="
                                dispensary.tvCall.rooms.map((room) => ({
                                    value: room.id,
                                    label: room.name,
                                }))
                            "
                        />
                        <button
                            v-if="dispensary.tvCall?.canCall"
                            type="button"
                            :class="outlineButton"
                            :disabled="busy || callNeedsRoom"
                            data-testid="dispensary-call"
                            @click="callPatient"
                        >
                            <Megaphone class="size-4" aria-hidden="true" />Call
                            patient
                        </button>
                        <a
                            v-if="canPrintAll"
                            :href="labelsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            :class="outlineButton"
                            ><Printer class="size-4" aria-hidden="true" />Print
                            all labels
                            <span class="sr-only"
                                >(opens print preview in a new tab)</span
                            ></a
                        >
                        <!-- Daniel: Return to Doctor sits apart, behind its own label, so a misclick cannot send the case back. -->
                        <div
                            v-if="dispensary.can.return"
                            class="!h-auto border-t pt-3"
                            data-testid="dispensary-send-back"
                        >
                            <p
                                class="mb-2 text-[13px] font-medium text-muted-foreground"
                            >
                                Send back to the doctor
                            </p>
                            <button
                                type="button"
                                :class="outlineButton"
                                :disabled="busy"
                                data-testid="dispensary-return"
                                @click="returnOpen = true"
                            >
                                <RotateCcw
                                    class="size-4"
                                    aria-hidden="true"
                                />Return to Doctor
                            </button>
                        </div>
                    </div>
                </section>
                <div
                    v-if="primaryAction"
                    class="sticky bottom-0 z-10 -mx-4 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:-mx-6 lg:px-6 2xl:hidden"
                    data-testid="dispensary-bar"
                >
                    <button
                        type="button"
                        :class="[filledButton, 'sm:w-auto sm:min-w-64']"
                        :aria-disabled="primaryDisabled"
                        :aria-describedby="primaryBarDescribedBy"
                        data-testid="dispensary-primary-bar"
                        @click="runPrimary"
                    >
                        {{ primaryLabel }}
                    </button>
                    <p
                        v-if="actionError"
                        ref="actionErrorBarRef"
                        role="alert"
                        tabindex="-1"
                        class="mt-2 !h-auto rounded-[10px] border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        data-testid="dispensary-action-error-bar"
                    >
                        {{ actionError }}
                    </p>
                    <p
                        v-if="
                            primaryAction === 'complete' &&
                            completeBlockedReason
                        "
                        id="dispensary-complete-blocked-reason-phone"
                        class="mt-2 text-[13px] text-muted-foreground"
                        data-testid="dispensary-complete-blocked-reason-phone"
                    >
                        {{ completeBlockedReason }}
                    </p>
                </div>
            </template>
            <template #rail>
                <V2StickySummary title="This dispensary" :items="summaryItems">
                    <button
                        v-if="primaryAction"
                        type="button"
                        :class="filledButton"
                        :aria-disabled="primaryDisabled"
                        :aria-describedby="primaryDescribedBy"
                        data-testid="dispensary-primary"
                        @click="runPrimary"
                    >
                        {{ primaryLabel }}
                    </button>
                    <p
                        v-if="actionError && primaryAction"
                        ref="actionErrorRailRef"
                        role="alert"
                        tabindex="-1"
                        class="mt-2 !h-auto rounded-[10px] border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        data-testid="dispensary-action-error-rail"
                    >
                        {{ actionError }}
                    </p>
                    <OperationalSelect
                        v-if="
                            dispensary.tvCall?.canCall &&
                            dispensary.tvCall.rooms.length > 1
                        "
                        v-model="callRoomId"
                        label="Dispensary room"
                        placeholder="Choose room"
                        trigger-class="h-11 w-full lg:h-9"
                        :options="
                            dispensary.tvCall.rooms.map((room) => ({
                                value: room.id,
                                label: room.name,
                            }))
                        "
                    />
                    <button
                        v-if="dispensary.tvCall?.canCall"
                        type="button"
                        :class="outlineButton"
                        :disabled="busy || callNeedsRoom"
                        data-testid="dispensary-call"
                        @click="callPatient"
                    >
                        <Megaphone class="size-4" aria-hidden="true" />Call
                        patient
                    </button>
                    <a
                        v-if="canPrintAll"
                        :href="labelsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="outlineButton"
                        ><Printer class="size-4" aria-hidden="true" />Print all
                        labels
                        <span class="sr-only"
                            >(opens print preview in a new tab)</span
                        ></a
                    >
                    <!-- Daniel: Return to Doctor sits apart, behind its own label, so a misclick cannot send the case back. -->
                    <div
                        v-if="dispensary.can.return"
                        class="!h-auto border-t pt-3"
                        data-testid="dispensary-send-back"
                    >
                        <p
                            class="mb-2 text-[13px] font-medium text-muted-foreground"
                        >
                            Send back to the doctor
                        </p>
                        <button
                            type="button"
                            :class="outlineButton"
                            :disabled="busy"
                            data-testid="dispensary-return"
                            @click="returnOpen = true"
                        >
                            <RotateCcw
                                class="size-4"
                                aria-hidden="true"
                            />Return to Doctor
                        </button>
                    </div>
                    <p
                        v-if="
                            primaryAction === 'complete' &&
                            completeBlockedReason
                        "
                        id="dispensary-complete-blocked-reason"
                        class="!h-auto text-[13px] text-muted-foreground"
                        data-testid="dispensary-complete-blocked-reason"
                    >
                        {{ completeBlockedReason }}
                    </p>
                </V2StickySummary>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="confirming && dispensary.can.complete"
            title="Verify before completing"
            :description="
                isOtc
                    ? 'There is no doctor on an OTC visit. Completing now records your verification of the list below and takes the stock out.'
                    : 'The doctor has verified the order. Completing now records your verification of the final list below and takes the stock out.'
            "
            :confirm-label="completing ? 'Completing…' : 'Confirm and complete'"
            :busy="completing"
            data-testid="dispensary-verify"
            @update:open="(open: boolean) => (confirming = open || completing)"
            @confirm="confirmComplete"
        >
            <div class="flex flex-col gap-3 text-sm">
                <p class="font-medium">
                    {{ lineCount }} line{{ lineCount === 1 ? '' : 's' }} ·
                    Allergies:
                    {{
                        allergyNames.length
                            ? allergyNames.join(', ')
                            : allergyState === 'none'
                              ? 'No known allergies'
                              : 'Not recorded'
                    }}
                </p>
                <ul class="flex flex-col divide-y rounded-[10px] border">
                    <li
                        v-for="item in visibleItems"
                        :key="item.publicId"
                        class="flex flex-wrap justify-between gap-x-3 gap-y-0.5 px-3 py-2"
                    >
                        <span class="font-medium">{{ item.name }}</span>
                        <span class="text-muted-foreground tabular-nums"
                            >{{ quantityText(item.quantityDispensed) }}
                            {{ item.unit }} · Batch {{ batchText(item) }}</span
                        >
                    </li>
                    <li
                        v-for="line in visibleServices"
                        :key="line.publicId"
                        class="flex flex-wrap justify-between gap-x-3 px-3 py-2"
                    >
                        <span class="font-medium">{{ line.name }}</span>
                        <span class="text-muted-foreground tabular-nums"
                            >{{ quantityText(line.quantityPerformed) }}
                            {{ line.unit }}</span
                        >
                    </li>
                </ul>
            </div>
        </V2Dialog>
        <V2Dialog
            :open="returnOpen"
            title="Return this case to the doctor?"
            description="The doctor sees the case again. Nothing is dispensed."
            confirm-label="Return to Doctor"
            @update:open="(open: boolean) => (returnOpen = open)"
            @confirm="confirmReturn"
        />
    </PageFrame>
</template>
