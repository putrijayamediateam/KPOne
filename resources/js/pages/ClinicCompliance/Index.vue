<script setup lang="ts">
// HR-11a clinic licences: Overview, Licences and Settings (licence types). Statuses come from the server, worked out
// when the page opens; this page only shows them. Warnings only: nothing here blocks clinical work.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import { dateLabel } from '@/components/hr/records/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';

type Status = { code: string; words: string; short: string };
type FileView = { id: string; label: string; kind: string; bytes: number };
type Version = {
    id: string;
    version: number;
    inForce: boolean;
    reference: string | null;
    issuedOn: string | null;
    expiresOn: string | null;
    note: string | null;
    enteredBy: string;
    enteredAt: string;
    errorReason: string | null;
    files: FileView[];
};
type Row = {
    id: string;
    lockVersion: number;
    typeId: string;
    typeName: string;
    expires: boolean;
    typeActive: boolean;
    branchId: number | null;
    branchName: string;
    status: Status;
    expiresOn: string | null;
    history: Version[];
};
type LicenceType = {
    id: string;
    name: string;
    expires: boolean;
    warnFromDays: number | null;
    isActive: boolean;
    lockVersion: number;
};
type Compliance = {
    tab: string;
    tabs: string[];
    today: string;
    canManageLicences: boolean;
    setUp: boolean;
    options: {
        branches: { id: number; name: string }[];
        correctionReasons: { value: string; label: string }[];
        suggestedWarnDays: number;
        suggestedTypes: string[];
    };
    overview: {
        counts: Record<string, number>;
        attention: {
            id: string;
            typeName: string;
            branchName: string;
            status: Status;
            expiresOn: string | null;
        }[];
        typesWithoutRecord: string[];
    } | null;
    licences: { rows: Row[]; types: LicenceType[] } | null;
    settings: { types: LicenceType[] } | null;
};

const props = defineProps<{ compliance: Compliance }>();
const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const base = '/clinic-compliance';
const labels: Record<string, string> = {
    overview: 'Overview',
    licences: 'Licences',
    settings: 'Settings',
};
const tabs = computed(() =>
    props.compliance.tabs.map((value) => ({ value, label: labels[value] })),
);
const selectTab = (tab: string) =>
    router.get(base, { tab }, { preserveScroll: true });
const tone = (code: string) =>
    code === 'valid'
        ? 'success'
        : code === 'expired' || code === 'expiring'
          ? 'warning'
          : 'neutral';
const when = (iso: string) =>
    new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Kuala_Lumpur',
    }).format(new Date(iso));
const size = (bytes: number) =>
    bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

// ---- Add or renew a licence ----
const sheet = reactive({
    open: false,
    row: null as Row | null,
    typeId: '',
    branch: 'clinic',
    reference: '',
    issuedOn: '',
    expiresOn: '',
    note: '',
    files: [] as File[],
    key: '',
    processing: false,
});
const chooser = ref<InstanceType<typeof FileChooseButton> | null>(null);
const typeOptions = computed(() =>
    (props.compliance.licences?.types ?? []).map((t) => ({
        value: t.id,
        label: t.name,
    })),
);
const branchOptions = computed(() => [
    { value: 'clinic', label: 'Whole clinic' },
    ...props.compliance.options.branches.map((b) => ({
        value: String(b.id),
        label: b.name,
    })),
]);
const sheetType = computed(() =>
    (props.compliance.licences?.types ?? []).find((t) => t.id === sheet.typeId),
);
const openSheet = (row: Row | null) => {
    Object.assign(sheet, {
        open: true,
        row,
        typeId: row?.typeId ?? '',
        branch: row
            ? row.branchId === null
                ? 'clinic'
                : String(row.branchId)
            : 'clinic',
        reference: '',
        issuedOn: '',
        expiresOn: '',
        note: '',
        files: [],
        key: crypto.randomUUID(),
        processing: false,
    });
};
const addFile = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file && sheet.files.length < 3) {
        sheet.files.push(file);
    }

    chooser.value?.clear();
};
const sendLicence = () => {
    sheet.processing = true;
    router.post(
        `${base}/licences`,
        {
            type_id: sheet.typeId,
            branch_id: sheet.branch,
            ...(sheet.row
                ? {
                      licence_id: sheet.row.id,
                      expected_lock_version: sheet.row.lockVersion,
                  }
                : {}),
            reference: sheet.reference,
            issued_on: sheet.issuedOn,
            expires_on: sheetType.value?.expires ? sheet.expiresOn : '',
            note: sheet.note,
            ...(sheet.files.length ? { files: sheet.files } : {}),
            idempotency_key: sheet.key,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => (sheet.open = false),
            onFinish: () => (sheet.processing = false),
        },
    );
};

// ---- Mark a version as entered in error ----
const mistake = reactive({
    open: false,
    row: null as Row | null,
    version: null as Version | null,
    reason: '',
    key: '',
    processing: false,
});
const openMistake = (row: Row, version: Version) =>
    Object.assign(mistake, {
        open: true,
        row,
        version,
        reason: '',
        key: crypto.randomUUID(),
        processing: false,
    });
const sendMistake = () => {
    if (!mistake.version || !mistake.row) {
        return;
    }

    mistake.processing = true;
    router.post(
        `${base}/versions/${mistake.version.id}/error`,
        {
            reason: mistake.reason,
            expected_lock_version: mistake.row.lockVersion,
            idempotency_key: mistake.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (mistake.open = false),
            onFinish: () => (mistake.processing = false),
        },
    );
};

// ---- Licence types ----
const typeSheet = reactive({
    open: false,
    type: null as LicenceType | null,
    name: '',
    expires: 'yes',
    warn: '',
    key: '',
    processing: false,
});
const yesNo = [
    { value: 'yes', label: 'Yes, it expires' },
    { value: 'no', label: 'No, it does not expire' },
];
const openType = (type: LicenceType | null, name = '') =>
    Object.assign(typeSheet, {
        open: true,
        type,
        name: type?.name ?? name,
        expires: type ? (type.expires ? 'yes' : 'no') : 'yes',
        warn: type?.warnFromDays ? String(type.warnFromDays) : '',
        key: crypto.randomUUID(),
        processing: false,
    });
const sendType = () => {
    typeSheet.processing = true;
    router.post(
        `${base}/types`,
        {
            ...(typeSheet.type
                ? {
                      type_id: typeSheet.type.id,
                      expected_lock_version: typeSheet.type.lockVersion,
                  }
                : {}),
            name: typeSheet.name,
            expires: typeSheet.expires === 'yes' ? '1' : '0',
            warn_from_days: typeSheet.expires === 'yes' ? typeSheet.warn : '',
            idempotency_key: typeSheet.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (typeSheet.open = false),
            onFinish: () => (typeSheet.processing = false),
        },
    );
};
const archiveType = (type: LicenceType) =>
    router.post(
        `${base}/types/${type.id}/archive`,
        {
            expected_lock_version: type.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true },
    );
const unusedSuggestions = computed(() => {
    const have = new Set(
        (props.compliance.settings?.types ?? []).map((t) =>
            t.name.toLowerCase(),
        ),
    );

    return props.compliance.options.suggestedTypes.filter(
        (n) => !have.has(n.toLowerCase()),
    );
});
</script>

<template>
    <Head title="Clinic compliance" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <PageHeader
            eyebrow="Clinic compliance"
            title="Clinic licences"
            description="Clinic licences and when they expire. Warnings only: nothing here stops clinical work. Dates are what someone typed; KPOne does not check them with the authority."
        />

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="compliance.tab"
            :tabs="tabs"
            label="Clinic compliance sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="
                errors.compliance &&
                !sheet.open &&
                !mistake.open &&
                !typeSheet.open
            "
            :message="errors.compliance"
        />

        <EmptyState
            v-if="!compliance.canManageLicences"
            title="You cannot open this page."
            description="Ask the Director if you need access."
        />

        <!-- Overview -->
        <section
            v-else-if="compliance.tab === 'overview' && compliance.overview"
            class="flex flex-col gap-4"
            aria-label="Overview"
        >
            <EmptyState
                v-if="!compliance.setUp"
                title="No licence types yet."
                description="Add the licences your clinic holds under Settings, then record each one."
            />
            <template v-else>
                <ul class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <li
                        v-for="[code, label] in [
                            ['expired', 'Expired'],
                            ['expiring', 'Expiring soon'],
                            ['valid', 'Valid'],
                            ['no_expiry', 'No expiry'],
                        ]"
                        :key="code"
                        class="rounded-lg border bg-card p-4"
                    >
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ compliance.overview.counts[code] ?? 0 }}
                        </p>
                        <p class="text-sm text-muted-foreground">{{ label }}</p>
                    </li>
                </ul>
                <div class="rounded-lg border bg-card p-4">
                    <h2 class="text-sm font-semibold">Needs attention</h2>
                    <p
                        v-if="!compliance.overview.attention.length"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        Nothing is expired or close to expiring.
                    </p>
                    <ul v-else class="mt-2 divide-y">
                        <li
                            v-for="item in compliance.overview.attention"
                            :key="item.id"
                            class="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <span class="min-w-0 text-sm">
                                {{ item.typeName }}
                                <span class="text-muted-foreground"
                                    >· {{ item.branchName }}</span
                                >
                            </span>
                            <span class="flex items-center gap-2">
                                <StatusBadge
                                    :status="item.status.code"
                                    :label="item.status.words"
                                    :tone="tone(item.status.code)"
                                />
                                <span class="text-xs text-muted-foreground">{{
                                    dateLabel(item.expiresOn)
                                }}</span>
                            </span>
                        </li>
                    </ul>
                </div>
                <div
                    v-if="compliance.overview.typesWithoutRecord.length"
                    class="rounded-lg border bg-card p-4"
                >
                    <h2 class="text-sm font-semibold">Not recorded yet</h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ compliance.overview.typesWithoutRecord.join(', ') }}
                    </p>
                </div>
            </template>
        </section>

        <!-- Licences -->
        <section
            v-else-if="compliance.tab === 'licences' && compliance.licences"
            class="flex flex-col gap-3"
            aria-label="Licences"
        >
            <div class="flex justify-end">
                <Button
                    type="button"
                    class="min-h-11 lg:min-h-9"
                    :disabled="!compliance.licences.types.length"
                    data-test="add-licence"
                    @click="openSheet(null)"
                    >Add a licence</Button
                >
            </div>
            <EmptyState
                v-if="!compliance.licences.types.length"
                title="No licence types yet."
                description="Add the types under Settings first."
            />
            <EmptyState
                v-else-if="!compliance.licences.rows.length"
                title="No licences recorded yet."
                description="Press Add a licence and enter its dates and reference."
            />
            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="row in compliance.licences.rows"
                    :key="row.id"
                    class="rounded-lg border bg-card p-4"
                    data-test="licence-row"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold break-words">
                                {{ row.typeName }}
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                {{ row.branchName }}
                                <template v-if="row.expiresOn">
                                    · Expires
                                    {{ dateLabel(row.expiresOn) }}</template
                                >
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                :status="row.status.code"
                                :label="row.status.words"
                                :tone="tone(row.status.code)"
                            />
                            <Button
                                v-if="row.typeActive"
                                type="button"
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                @click="openSheet(row)"
                                >Renew<span class="sr-only">
                                    {{ row.typeName }},
                                    {{ row.branchName }}</span
                                ></Button
                            >
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary
                            class="min-h-11 cursor-pointer py-2 text-sm text-muted-foreground lg:min-h-0"
                        >
                            History ({{ row.history.length }})
                        </summary>
                        <ul class="mt-2 divide-y">
                            <li
                                v-for="v in row.history"
                                :key="v.id"
                                class="flex flex-col gap-1 py-3 text-sm"
                            >
                                <p class="font-medium">
                                    Version {{ v.version }}
                                    <span
                                        v-if="v.inForce"
                                        class="text-xs font-normal text-muted-foreground"
                                        >· in force</span
                                    >
                                    <span
                                        v-if="v.errorReason"
                                        class="text-xs font-normal text-muted-foreground"
                                        >· entered in error ({{
                                            v.errorReason
                                        }})</span
                                    >
                                </p>
                                <p v-if="v.reference" class="break-words">
                                    Reference: {{ v.reference }}
                                </p>
                                <p class="text-muted-foreground">
                                    <template v-if="v.issuedOn"
                                        >Issued {{ dateLabel(v.issuedOn) }}
                                    </template>
                                    <template v-if="v.expiresOn"
                                        >· Expires
                                        {{ dateLabel(v.expiresOn) }}</template
                                    >
                                </p>
                                <p v-if="v.note" class="break-words">
                                    {{ v.note }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Entered by {{ v.enteredBy }},
                                    {{ when(v.enteredAt) }}
                                </p>
                                <ul
                                    v-if="v.files.length"
                                    class="flex flex-wrap gap-2"
                                >
                                    <li v-for="f in v.files" :key="f.id">
                                        <a
                                            :href="`${base}/files/${f.id}`"
                                            target="_blank"
                                            rel="noopener"
                                            class="inline-flex min-h-11 items-center text-brand-strong underline lg:min-h-0"
                                            >{{ f.label }} ({{
                                                size(f.bytes)
                                            }})</a
                                        >
                                    </li>
                                </ul>
                                <div v-if="!v.errorReason">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        class="min-h-11 px-2 sm:min-h-9"
                                        @click="openMistake(row, v)"
                                        >Mark entered in error<span
                                            class="sr-only"
                                        >
                                            , version {{ v.version }}</span
                                        ></Button
                                    >
                                </div>
                            </li>
                        </ul>
                    </details>
                </li>
            </ul>
        </section>

        <!-- Settings -->
        <section
            v-else-if="compliance.tab === 'settings' && compliance.settings"
            class="flex flex-col gap-3"
            aria-label="Licence types"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted-foreground">
                    KPOne ships no licence list. Add the ones your clinic holds.
                </p>
                <Button
                    type="button"
                    class="min-h-11 lg:min-h-9"
                    data-test="add-type"
                    @click="openType(null)"
                    >Add a licence type</Button
                >
            </div>
            <div
                v-if="unusedSuggestions.length"
                class="flex flex-wrap items-center gap-2 text-sm"
            >
                <span class="text-muted-foreground">Suggestions:</span>
                <Button
                    v-for="s in unusedSuggestions"
                    :key="s"
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    @click="openType(null, s)"
                    >{{ s }}</Button
                >
            </div>
            <EmptyState
                v-if="!compliance.settings.types.length"
                title="No licence types yet."
                description="Add the first one above."
            />
            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="t in compliance.settings.types"
                    :key="t.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-semibold break-words">
                            {{ t.name }}
                            <span
                                v-if="!t.isActive"
                                class="text-xs font-normal text-muted-foreground"
                                >· archived</span
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t.expires
                                    ? `Expires. Warn from ${t.warnFromDays ?? 0} days before.`
                                    : 'Does not expire.'
                            }}
                        </p>
                    </div>
                    <div v-if="t.isActive" class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="openType(t)"
                            >Edit<span class="sr-only">
                                {{ t.name }}</span
                            ></Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="archiveType(t)"
                            >Archive<span class="sr-only">
                                {{ t.name }}</span
                            ></Button
                        >
                    </div>
                </li>
            </ul>
        </section>

        <!-- Add or renew -->
        <Sheet
            :open="sheet.open"
            @update:open="(v: boolean) => (sheet.open = v)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="licence-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        sheet.row
                            ? `Renew ${sheet.row.typeName}`
                            : 'Add a licence'
                    }}</SheetTitle>
                    <SheetDescription
                        >Type the dates from the licence. Add up to 3 scans or
                        photos (PDF, JPG or PNG).</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendLicence"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <div class="grid gap-1.5">
                        <Label for="lic-type"
                            ><span id="lic-type-label"
                                >Licence type *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="lic-type"
                            v-model="sheet.typeId"
                            labelledby="lic-type-label"
                            trigger-class="max-lg:h-11"
                            label="Licence type"
                            placeholder="Choose"
                            :options="typeOptions"
                            :disabled="!!sheet.row"
                            :invalid="!!errors.type_id"
                        />
                        <InputError :message="errors.type_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="lic-branch"
                            ><span id="lic-branch-label"
                                >Applies to *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="lic-branch"
                            v-model="sheet.branch"
                            labelledby="lic-branch-label"
                            trigger-class="max-lg:h-11"
                            label="Applies to"
                            :options="branchOptions"
                            :disabled="!!sheet.row"
                            :invalid="!!errors.branch_id"
                        />
                        <InputError :message="errors.branch_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="lic-ref">Licence number or reference</Label>
                        <Input
                            id="lic-ref"
                            v-model="sheet.reference"
                            class="max-lg:h-11"
                            maxlength="120"
                            :aria-invalid="!!errors.reference"
                        />
                        <InputError :message="errors.reference" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="lic-issued">Issue date</Label>
                        <Input
                            id="lic-issued"
                            v-model="sheet.issuedOn"
                            type="date"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.issued_on"
                        />
                        <InputError :message="errors.issued_on" />
                    </div>
                    <div v-if="sheetType?.expires" class="grid gap-1.5">
                        <Label for="lic-expires">Expiry date *</Label>
                        <Input
                            id="lic-expires"
                            v-model="sheet.expiresOn"
                            type="date"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.expires_on"
                        />
                        <InputError :message="errors.expires_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="lic-note">Note (optional)</Label>
                        <Input
                            id="lic-note"
                            v-model="sheet.note"
                            class="max-lg:h-11"
                            maxlength="300"
                            :aria-invalid="!!errors.note"
                        />
                        <InputError :message="errors.note" />
                    </div>
                    <div class="grid gap-1.5">
                        <FileChooseButton
                            id="lic-file"
                            ref="chooser"
                            accept="application/pdf,image/jpeg,image/png"
                            label="Add a scan or photo"
                            :status="
                                sheet.files.length
                                    ? sheet.files.map((f) => f.name).join(', ')
                                    : 'No file chosen'
                            "
                            status-id="lic-file-state"
                            :disabled="sheet.files.length >= 3"
                            @change="addFile"
                        />
                        <InputError :message="errors.files" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="sheet.processing"
                        >Save licence</Button
                    >
                </form>
            </SheetContent>
        </Sheet>

        <!-- Entered in error -->
        <Sheet
            :open="mistake.open"
            @update:open="(v: boolean) => (mistake.open = v)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="mistake-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Mark entered in error</SheetTitle>
                    <SheetDescription
                        >The entry stays in the history. The one before it
                        counts again.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendMistake"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <div class="grid gap-1.5">
                        <Label for="mistake-reason"
                            ><span id="mistake-reason-label"
                                >Reason *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="mistake-reason"
                            v-model="mistake.reason"
                            labelledby="mistake-reason-label"
                            trigger-class="max-lg:h-11"
                            label="Reason"
                            placeholder="Choose"
                            :options="compliance.options.correctionReasons"
                            :invalid="!!errors.reason"
                        />
                        <InputError :message="errors.reason" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="mistake.processing"
                        >Mark entered in error</Button
                    >
                </form>
            </SheetContent>
        </Sheet>

        <!-- Licence type -->
        <Sheet
            :open="typeSheet.open"
            @update:open="(v: boolean) => (typeSheet.open = v)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="type-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        typeSheet.type
                            ? 'Edit licence type'
                            : 'Add a licence type'
                    }}</SheetTitle>
                    <SheetDescription
                        >Warning days are a reminder inside KPOne only.
                        Suggestion:
                        {{ compliance.options.suggestedWarnDays }}
                        days.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendType"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <div class="grid gap-1.5">
                        <Label for="type-name">Name *</Label>
                        <Input
                            id="type-name"
                            v-model="typeSheet.name"
                            class="max-lg:h-11"
                            maxlength="120"
                            :aria-invalid="!!errors.name"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="type-expires"
                            ><span id="type-expires-label"
                                >Does it expire? *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="type-expires"
                            v-model="typeSheet.expires"
                            labelledby="type-expires-label"
                            trigger-class="max-lg:h-11"
                            label="Does it expire?"
                            :options="yesNo"
                            :invalid="!!errors.expires"
                        />
                        <InputError :message="errors.expires" />
                    </div>
                    <div
                        v-if="typeSheet.expires === 'yes'"
                        class="grid gap-1.5"
                    >
                        <Label for="type-warn"
                            >Warn this many days before *</Label
                        >
                        <Input
                            id="type-warn"
                            v-model="typeSheet.warn"
                            inputmode="numeric"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.warn_from_days"
                        />
                        <InputError :message="errors.warn_from_days" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="typeSheet.processing"
                        >Save licence type</Button
                    >
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
