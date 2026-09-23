<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    LoaderCircle,
    ShieldAlert,
    UserCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import OperationalSelect from '@/components/ui/select/OperationalSelect.vue';
import VisitReasonPicker from '@/components/visit/VisitReasonPicker.vue';
import type { VisitReasonOption } from '@/components/visit/VisitReasonPicker.vue';

type Intake = {
    publicId: string;
    status: string;
    submissionType: string;
    privacyNoticeVersion: string;
    consentedAt: string;
    submittedAt: string;
    lockVersion: number;
    fields: {
        submission_type: string;
        full_name: string;
        date_of_birth: string;
        sex: string;
        nationality_code: string | null;
        mobile_phone: string;
        phone_country: string;
        identifier_type: string;
        identifier_value: string;
        identifier_issuing_country_code: string | null;
        guardian_name: string | null;
        guardian_relationship: string | null;
        guardian_contact_number: string | null;
        guardian_attestation: boolean;
        visit_purpose: string;
        chief_complaint: string;
        complaint_duration: string | null;
        consent_confirmed: boolean;
        privacy_notice_version: string;
    };
    duplicateCandidates: Array<{
        patientNumber: string;
        fullName: string;
        dateOfBirth: string | null;
        maskedPhone: string | null;
        maskedIdentifier: string | null;
    }>;
};
const props = defineProps<{
    intake: Intake;
    visitOptions: {
        branch: { id: number; name: string };
        idempotencyKey: string;
        doctors: Array<{ id: number; name: string }>;
        panels: Array<{ id: number; name: string }>;
    };
    rejectionCategories: string[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Registration', href: '/registration' },
            { title: 'QR Intake', href: '/registration?tab=qr-intake' },
        ],
    },
});
const selectedReasons = ref<VisitReasonOption[]>([]);
const actionProcessing = ref(false);
const actionError = ref('');
const correction = useForm({
    ...props.intake.fields,
    lock_version: props.intake.lockVersion,
});
const acceptance = useForm({
    lock_version: props.intake.lockVersion,
    idempotency_key: props.visitOptions.idempotencyKey,
    resolution: 'match',
    patient_number: '',
    duplicate_override: false,
    assigned_doctor_user_id: '' as string | number,
    visit_reason_public_ids: [] as string[],
    priority: 'normal',
    coverage_type: 'self_pay',
    panel_id: '' as string | number,
    coverage_member_reference: '',
    confirm_repeat: false,
    status: '',
});
const rejectionCategory = ref('insufficient_information');
const doctorOptions = computed(() =>
    props.visitOptions.doctors.map((d) => ({ value: d.id, label: d.name })),
);
const panelOptions = computed(() =>
    props.visitOptions.panels.map((p) => ({ value: p.id, label: p.name })),
);
const canMutate = computed(() =>
    ['pending', 'under_review', 'correction_required'].includes(
        props.intake.status,
    ),
);
const postAction = (path: string, data: Parameters<typeof router.post>[1]) => {
    actionProcessing.value = true;
    actionError.value = '';
    router.post(path, data, {
        preserveScroll: true,
        onError: () =>
            (actionError.value =
                'Action failed. Reload and review the current record.'),
        onFinish: () => (actionProcessing.value = false),
    });
};
const requireCorrection = () =>
    postAction(
        `/registration-review/${props.intake.publicId}/correction-required`,
        { lock_version: props.intake.lockVersion },
    );
const reject = () =>
    postAction(`/registration-review/${props.intake.publicId}/reject`, {
        lock_version: props.intake.lockVersion,
        category: rejectionCategory.value,
    });
const saveCorrection = () =>
    correction.patch(`/registration-review/${props.intake.publicId}/correct`, {
        preserveScroll: true,
    });
const accept = () =>
    acceptance.post(`/registration-review/${props.intake.publicId}/accept`, {
        preserveScroll: true,
    });
const selectCandidate = (patientNumber: string) => {
    acceptance.resolution = 'match';
    acceptance.patient_number = patientNumber;
};
</script>

<template>
    <Head title="Registration Review" />
    <main class="flex flex-1 flex-col gap-6 p-4 md:p-7">
        <header
            class="flex flex-col justify-between gap-3 md:flex-row md:items-start"
        >
            <div>
                <Link
                    href="/registration?tab=qr-intake"
                    class="mb-2 inline-flex items-center gap-1 text-xs font-medium text-pink-800 hover:underline"
                >
                    <ArrowLeft class="size-3.5" /> Registration · QR Intake
                </Link>
                <p class="text-sm text-muted-foreground">
                    {{ visitOptions.branch.name }}
                </p>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Registration Review
                </h1>
                <p class="font-mono text-xs text-muted-foreground">
                    {{ intake.publicId }}
                </p>
            </div>
            <Badge variant="outline">{{
                intake.status.replace('_', ' ')
            }}</Badge>
        </header>
        <div
            v-if="actionError"
            role="alert"
            class="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
        >
            {{ actionError }}
        </div>
        <form
            class="grid gap-5 xl:grid-cols-2"
            @submit.prevent="saveCorrection"
        >
            <Card
                ><CardHeader
                    ><CardTitle>Patient information</CardTitle
                    ><CardDescription
                        >Encrypted public payload; visible only in this
                        authorised branch workspace.</CardDescription
                    ></CardHeader
                ><CardContent class="grid gap-4">
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Full name</span
                        ><input
                            v-model="correction.full_name"
                            class="h-10 rounded-md border bg-background px-3" /><InputError
                            :message="correction.errors.full_name"
                    /></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1"
                            ><span class="text-sm font-medium"
                                >Date of birth</span
                            ><input
                                v-model="correction.date_of_birth"
                                type="date"
                                class="h-10 rounded-md border bg-background px-3" /><InputError
                                :message="
                                    correction.errors.date_of_birth
                                " /></label
                        ><label class="grid gap-1"
                            ><span class="text-sm font-medium">Gender</span
                            ><select
                                v-model="correction.sex"
                                class="h-10 rounded-md border bg-background px-3"
                            >
                                <option value="female">Female</option>
                                <option value="male">Male</option>
                                <option value="indeterminate">
                                    Indeterminate
                                </option>
                                <option value="unknown">Unknown</option>
                            </select></label
                        >
                    </div>
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Mobile phone</span
                        ><input
                            v-model="correction.mobile_phone"
                            type="tel"
                            class="h-10 rounded-md border bg-background px-3" /><InputError
                            :message="correction.errors.mobile_phone"
                    /></label>
                    <div class="grid grid-cols-[8rem_1fr] gap-2">
                        <select
                            v-model="correction.identifier_type"
                            aria-label="Identifier type"
                            class="h-10 rounded-md border bg-background px-2"
                        >
                            <option value="nric">NRIC</option>
                            <option value="passport">Passport</option></select
                        ><input
                            v-model="correction.identifier_value"
                            aria-label="Identifier value"
                            class="h-10 min-w-0 rounded-md border bg-background px-3"
                        />
                    </div>
                    <template v-if="correction.submission_type === 'guardian'"
                        ><label class="grid gap-1"
                            ><span class="text-sm font-medium"
                                >Guardian name</span
                            ><input
                                v-model="correction.guardian_name"
                                class="h-10 rounded-md border bg-background px-3" /></label
                        ><label class="grid gap-1"
                            ><span class="text-sm font-medium"
                                >Guardian contact</span
                            ><input
                                v-model="correction.guardian_contact_number"
                                type="tel"
                                class="h-10 rounded-md border bg-background px-3" /></label
                    ></template>
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Tujuan lawatan</span
                        ><select
                            v-model="correction.visit_purpose"
                            class="h-10 rounded-md border bg-background px-3"
                        >
                            <option value="doctor_illness">
                                Jumpa doktor / sakit
                            </option>
                            <option value="pregnancy_check">
                                Pemeriksaan kehamilan
                            </option>
                            <option value="scan">Scan</option>
                            <option value="vaccination">Vaksin</option>
                            <option value="medical_checkup">
                                Medical check-up
                            </option>
                            <option value="procedure">Prosedur</option>
                            <option value="other">Lain-lain</option>
                        </select></label
                    >
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium"
                            >Aduan / tujuan utama</span
                        ><textarea
                            v-model="correction.chief_complaint"
                            rows="3"
                            maxlength="500"
                            class="rounded-md border bg-background px-3 py-2"
                        ></textarea>
                    </label>
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Sejak bila?</span
                        ><input
                            v-model="correction.complaint_duration"
                            maxlength="120"
                            class="h-10 rounded-md border bg-background px-3"
                    /></label>
                    <div
                        class="rounded-md bg-muted p-3 text-xs text-muted-foreground"
                    >
                        Consent {{ intake.privacyNoticeVersion }} recorded
                        {{ intake.consentedAt }}. Consent fields cannot be
                        edited here.
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="!canMutate || correction.processing"
                        >Save permitted corrections</Button
                    >
                </CardContent></Card
            >

            <Card
                ><CardHeader
                    ><CardTitle>Duplicate resolution</CardTitle
                    ><CardDescription
                        >Candidates are private to staff and never appear on the
                        public status page.</CardDescription
                    ></CardHeader
                ><CardContent class="space-y-4">
                    <div
                        v-for="candidate in intake.duplicateCandidates"
                        :key="candidate.patientNumber"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="font-semibold">
                                    {{ candidate.fullName }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ candidate.patientNumber }} ·
                                    {{
                                        candidate.dateOfBirth ||
                                        'DOB unavailable'
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ candidate.maskedIdentifier }} ·
                                    {{ candidate.maskedPhone }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="
                                    selectCandidate(candidate.patientNumber)
                                "
                                >Match</Button
                            >
                        </div>
                    </div>
                    <p
                        v-if="intake.duplicateCandidates.length === 0"
                        class="rounded-lg bg-muted p-3 text-sm text-muted-foreground"
                    >
                        No soft or exact duplicate candidate was found.
                    </p>
                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">Resolution</legend>
                        <label class="flex items-center gap-2"
                            ><input
                                v-model="acceptance.resolution"
                                type="radio"
                                value="match"
                            />Match existing Patient</label
                        ><label class="flex items-center gap-2"
                            ><input
                                v-model="acceptance.resolution"
                                type="radio"
                                value="create"
                            />Create new Patient</label
                        >
                    </fieldset>
                    <label
                        v-if="acceptance.resolution === 'match'"
                        class="grid gap-1"
                        ><span class="text-sm font-medium">Patient number</span
                        ><input
                            v-model="acceptance.patient_number"
                            class="h-10 rounded-md border bg-background px-3" /><InputError
                            :message="acceptance.errors.patient_number"
                    /></label>
                    <label
                        v-else
                        class="flex items-start gap-2 rounded-lg border p-3 text-sm"
                        ><input
                            v-model="acceptance.duplicate_override"
                            type="checkbox"
                            class="mt-1"
                        /><span
                            >Confirm creation only after duplicate review. The
                            server still enforces exact identifier
                            uniqueness.</span
                        ></label
                    >
                </CardContent></Card
            >
        </form>

        <Card
            ><CardHeader
                ><CardTitle>Visit and Queue</CardTitle
                ><CardDescription
                    >One transaction creates or matches Patient, creates Visit
                    and Queue Entry, then accepts the intake.</CardDescription
                ></CardHeader
            ><CardContent
                ><form
                    class="grid gap-4 md:grid-cols-2"
                    @submit.prevent="accept"
                >
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Doctor</span
                        ><OperationalSelect
                            v-model="acceptance.assigned_doctor_user_id"
                            :options="doctorOptions"
                            label="Doctor" /><InputError
                            :message="
                                acceptance.errors.assigned_doctor_user_id
                            "
                    /></label>
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Priority</span
                        ><select
                            v-model="acceptance.priority"
                            class="h-10 rounded-md border bg-background px-3"
                        >
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                        </select></label
                    >
                    <div class="md:col-span-2">
                        <VisitReasonPicker
                            v-model="acceptance.visit_reason_public_ids"
                            v-model:selected="selectedReasons"
                            :error="acceptance.errors.visit_reason_public_ids"
                        />
                    </div>
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Coverage</span
                        ><select
                            v-model="acceptance.coverage_type"
                            class="h-10 rounded-md border bg-background px-3"
                        >
                            <option value="self_pay">Self-pay</option>
                            <option value="panel">Panel</option>
                        </select></label
                    >
                    <label
                        v-if="acceptance.coverage_type === 'panel'"
                        class="grid gap-1"
                        ><span class="text-sm font-medium">Panel</span
                        ><OperationalSelect
                            v-model="acceptance.panel_id"
                            :options="panelOptions"
                            label="Panel"
                    /></label>
                    <label
                        v-if="acceptance.errors.confirm_repeat"
                        class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm md:col-span-2"
                        ><input
                            v-model="acceptance.confirm_repeat"
                            type="checkbox"
                            class="mt-1"
                        /><span>{{
                            acceptance.errors.confirm_repeat
                        }}</span></label
                    >
                    <InputError
                        class="md:col-span-2"
                        :message="
                            acceptance.errors.idempotency_key ||
                            acceptance.errors.lock_version ||
                            acceptance.errors.status
                        "
                    />
                    <Button
                        class="min-h-11 md:col-span-2"
                        :disabled="!canMutate || acceptance.processing"
                        ><LoaderCircle
                            v-if="acceptance.processing"
                            class="size-4 animate-spin"
                        /><UserCheck v-else class="size-4" />Sahkan &amp;
                        Masukkan Queue</Button
                    >
                </form></CardContent
            ></Card
        >

        <Card v-if="canMutate"
            ><CardHeader
                ><CardTitle>Controlled alternatives</CardTitle></CardHeader
            ><CardContent class="flex flex-wrap items-end gap-3"
                ><Button
                    type="button"
                    variant="outline"
                    :disabled="actionProcessing"
                    @click="requireCorrection"
                    ><ShieldAlert class="size-4" />Correction required</Button
                ><label class="grid gap-1"
                    ><span class="text-xs text-muted-foreground"
                        >Rejection category</span
                    ><select
                        v-model="rejectionCategory"
                        class="h-9 rounded-md border bg-background px-2"
                    >
                        <option
                            v-for="category in rejectionCategories"
                            :key="category"
                            :value="category"
                        >
                            {{ category.replaceAll('_', ' ') }}
                        </option>
                    </select></label
                ><Button
                    type="button"
                    variant="destructive"
                    :disabled="actionProcessing"
                    @click="reject"
                    >Reject intake</Button
                ></CardContent
            ></Card
        >
        <div
            class="flex items-center gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800"
        >
            <CheckCircle2 class="size-5" />No Patient, Visit or Queue record is
            created until the confirmation action commits.
        </div>
    </main>
</template>
