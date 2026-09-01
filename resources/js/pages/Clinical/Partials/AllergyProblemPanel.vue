<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, Plus, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type {
    ClinicalAllergyRecord,
    ClinicalAllergySafety,
    ClinicalProblemList,
    ClinicalProblemRecord,
} from '@/types';

const props = defineProps<{
    visitNumber: string;
    branchId: number;
    allergies: ClinicalAllergySafety | null;
    problems: ClinicalProblemList | null;
}>();

const baseUrl = computed(
    () => `/visits/${encodeURIComponent(props.visitNumber)}/encounter`,
);
const allergyBusy = ref(false);
const problemBusy = ref(false);
const actionError = ref<string | null>(null);
const showAllergyForm = ref(false);
const editingAllergy = ref<string | null>(null);
const showProblemForm = ref(false);
const editingProblem = ref<string | null>(null);

const allergyForm = useForm({
    expected_branch_id: props.branchId,
    profile_lock_version: props.allergies?.profileLockVersion ?? null,
    allergen_text: '',
    category: '' as '' | 'medication' | 'food' | 'environmental' | 'other',
    reaction_text: '',
    severity: '' as '' | 'mild' | 'moderate' | 'severe',
});

const problemForm = useForm({
    expected_branch_id: props.branchId,
    lock_version: null as number | null,
    condition_text: '',
    condition_code: '',
    code_system: '',
    onset_date: '',
});

const reviewCurrent = computed(
    () => props.allergies?.encounterReview?.isCurrent === true,
);

const resetAllergyForm = () => {
    editingAllergy.value = null;
    showAllergyForm.value = false;
    allergyForm.reset();
    allergyForm.clearErrors();
};

const startAllergyEdit = (record: ClinicalAllergyRecord) => {
    editingAllergy.value = record.publicId;
    showAllergyForm.value = true;
    allergyForm.allergen_text = record.allergen;
    allergyForm.category = record.category ?? '';
    allergyForm.reaction_text = record.reaction ?? '';
    allergyForm.severity = record.severity ?? '';
    allergyForm.clearErrors();
};

const saveAllergy = () => {
    allergyForm.expected_branch_id = props.branchId;
    allergyForm.profile_lock_version =
        props.allergies?.profileLockVersion ?? null;
    const options = {
        preserveScroll: true,
        onSuccess: resetAllergyForm,
    };

    if (editingAllergy.value) {
        allergyForm.patch(
            `${baseUrl.value}/allergies/${encodeURIComponent(editingAllergy.value)}`,
            options,
        );
    } else {
        allergyForm.post(`${baseUrl.value}/allergies`, options);
    }
};

const declareNoKnown = () => {
    if (
        !window.confirm(
            'Confirm that you explicitly reviewed the current Allergy Profile and identified no known allergies at this time.',
        )
    ) {
        return;
    }

    actionError.value = null;
    allergyBusy.value = true;
    router.post(
        `${baseUrl.value}/allergies/no-known`,
        {
            expected_branch_id: props.branchId,
            profile_lock_version: props.allergies?.profileLockVersion ?? null,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                actionError.value =
                    Object.values(errors)[0] ??
                    'The Allergy action could not be completed. Review the latest information and try again.';
            },
            onFinish: () => (allergyBusy.value = false),
        },
    );
};

const enterAllergyInError = (record: ClinicalAllergyRecord) => {
    if (
        !window.confirm(
            'Mark this recorded allergy as entered in error? This means the record itself was erroneous; it does not mean the allergy was cured.',
        )
    ) {
        return;
    }

    actionError.value = null;
    allergyBusy.value = true;
    router.patch(
        `${baseUrl.value}/allergies/${encodeURIComponent(record.publicId)}/entered-in-error`,
        {
            expected_branch_id: props.branchId,
            profile_lock_version: props.allergies?.profileLockVersion ?? null,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                actionError.value =
                    Object.values(errors)[0] ??
                    'The Allergy action could not be completed. Review the latest information and try again.';
            },
            onFinish: () => (allergyBusy.value = false),
        },
    );
};

const reviewAllergies = () => {
    if (!props.allergies?.profileLockVersion) {
        return;
    }

    actionError.value = null;
    allergyBusy.value = true;
    router.post(
        `${baseUrl.value}/allergies/review`,
        {
            expected_branch_id: props.branchId,
            profile_lock_version: props.allergies.profileLockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                actionError.value =
                    Object.values(errors)[0] ??
                    'The Allergy review could not be recorded. Review the latest information and try again.';
            },
            onFinish: () => (allergyBusy.value = false),
        },
    );
};

const resetProblemForm = () => {
    editingProblem.value = null;
    showProblemForm.value = false;
    problemForm.reset();
    problemForm.clearErrors();
};

const startProblemEdit = (record: ClinicalProblemRecord) => {
    editingProblem.value = record.publicId;
    showProblemForm.value = true;
    problemForm.lock_version = record.lockVersion;
    problemForm.condition_text = record.condition;
    problemForm.condition_code = record.conditionCode ?? '';
    problemForm.code_system = record.codeSystem ?? '';
    problemForm.onset_date = record.onsetDate ?? '';
    problemForm.clearErrors();
};

const saveProblem = () => {
    problemForm.expected_branch_id = props.branchId;
    const options = {
        preserveScroll: true,
        onSuccess: resetProblemForm,
    };

    if (editingProblem.value) {
        problemForm.patch(
            `${baseUrl.value}/problems/${encodeURIComponent(editingProblem.value)}`,
            options,
        );
    } else {
        problemForm.transform((data) => ({
            expected_branch_id: data.expected_branch_id,
            condition_text: data.condition_text,
            condition_code: data.condition_code,
            code_system: data.code_system,
            onset_date: data.onset_date,
        }));
        problemForm.post(`${baseUrl.value}/problems`, {
            ...options,
            onFinish: () => problemForm.transform((data) => data),
        });
    }
};

const transitionProblem = (
    record: ClinicalProblemRecord,
    action: 'resolve' | 'entered-in-error',
) => {
    const label =
        action === 'resolve'
            ? 'mark this condition as resolved'
            : 'mark this problem record as entered in error because the record itself was erroneous, not because the condition was resolved';

    if (!window.confirm(`Are you sure you want to ${label}?`)) {
        return;
    }

    actionError.value = null;
    problemBusy.value = true;
    router.patch(
        `${baseUrl.value}/problems/${encodeURIComponent(record.publicId)}/${action}`,
        {
            expected_branch_id: props.branchId,
            lock_version: record.lockVersion,
            ...(action === 'resolve' ? { resolved_date: null } : {}),
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                actionError.value =
                    Object.values(errors)[0] ??
                    'The Problem List action could not be completed. Review the latest information and try again.';
            },
            onFinish: () => (problemBusy.value = false),
        },
    );
};
</script>

<template>
    <section
        class="space-y-4 rounded-lg border border-amber-200 bg-amber-50/30 p-4"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 font-semibold">
                    <ShieldCheck class="size-4 text-amber-800" /> Allergies &
                    Conditions
                </h2>
                <p class="text-xs text-muted-foreground">
                    Longitudinal clinical safety information for the current
                    Patient.
                </p>
            </div>
            <div
                v-if="allergies"
                class="rounded-md border px-3 py-2 text-xs"
                :class="
                    reviewCurrent
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        : 'border-amber-300 bg-amber-100/60 text-amber-900'
                "
            >
                <span v-if="reviewCurrent" class="flex items-center gap-1">
                    <Check class="size-3.5" /> Reviewed for this consultation
                </span>
                <span v-else class="flex items-center gap-1">
                    <AlertTriangle class="size-3.5" /> Review required for this
                    consultation
                </span>
            </div>
        </div>

        <p
            v-if="actionError"
            role="alert"
            class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            {{ actionError }}
        </p>

        <div v-if="allergies" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-semibold">Allergies</h3>
                    <p
                        v-if="allergies.status === 'unknown'"
                        class="text-sm text-amber-900"
                    >
                        Allergy status not yet reviewed
                    </p>
                    <p
                        v-else-if="allergies.status === 'no_known_allergies'"
                        class="text-sm font-medium text-emerald-800"
                    >
                        No known allergies
                        <span
                            v-if="allergies.reviewedAt"
                            class="font-normal text-muted-foreground"
                        >
                            · Recorded
                            {{
                                new Date(allergies.reviewedAt).toLocaleString()
                            }}
                        </span>
                    </p>
                    <p v-else class="text-sm font-medium text-amber-900">
                        Allergies recorded
                    </p>
                </div>
                <div v-if="allergies.canUpdate" class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        type="button"
                        @click="showAllergyForm = true"
                    >
                        <Plus class="size-3.5" /> Record allergy
                    </Button>
                    <Button
                        v-if="allergies.status !== 'has_allergies'"
                        size="sm"
                        variant="outline"
                        type="button"
                        :disabled="allergyBusy"
                        @click="declareNoKnown"
                    >
                        Declare no known allergies
                    </Button>
                </div>
            </div>

            <div
                v-for="record in allergies.records"
                :key="record.publicId"
                class="flex flex-wrap items-start justify-between gap-3 border-t py-3 text-sm"
            >
                <div>
                    <div class="font-medium">{{ record.allergen }}</div>
                    <div class="text-xs text-muted-foreground">
                        {{ record.category ?? 'Category not recorded' }} ·
                        Reaction: {{ record.reaction ?? 'Not recorded' }} ·
                        Severity: {{ record.severity ?? 'Not recorded' }}
                    </div>
                </div>
                <div v-if="allergies.canUpdate" class="flex gap-2">
                    <Button
                        size="sm"
                        variant="ghost"
                        type="button"
                        @click="startAllergyEdit(record)"
                    >
                        Edit
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        type="button"
                        :disabled="allergyBusy"
                        @click="enterAllergyInError(record)"
                    >
                        Entered in error
                    </Button>
                </div>
            </div>

            <form
                v-if="showAllergyForm && allergies.canUpdate"
                class="grid gap-3 rounded-md bg-background p-3 md:grid-cols-2"
                @submit.prevent="saveAllergy"
            >
                <label class="text-sm md:col-span-2">
                    Allergen
                    <input
                        v-model="allergyForm.allergen_text"
                        maxlength="500"
                        required
                        class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                    />
                </label>
                <label class="text-sm">
                    Category
                    <select
                        v-model="allergyForm.category"
                        class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                    >
                        <option value="">Not recorded</option>
                        <option value="medication">Medication</option>
                        <option value="food">Food</option>
                        <option value="environmental">Environmental</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label class="text-sm">
                    Severity
                    <select
                        v-model="allergyForm.severity"
                        class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                    >
                        <option value="">Not recorded</option>
                        <option value="mild">Mild</option>
                        <option value="moderate">Moderate</option>
                        <option value="severe">Severe</option>
                    </select>
                </label>
                <label class="text-sm md:col-span-2">
                    Reaction
                    <textarea
                        v-model="allergyForm.reaction_text"
                        maxlength="1000"
                        rows="2"
                        class="mt-1 w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <p
                    v-if="Object.keys(allergyForm.errors).length"
                    class="text-xs text-red-700 md:col-span-2"
                >
                    Review the highlighted Allergy information and try again.
                </p>
                <div class="flex gap-2 md:col-span-2">
                    <Button size="sm" :disabled="allergyForm.processing">
                        {{ editingAllergy ? 'Save allergy' : 'Add allergy' }}
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        type="button"
                        @click="resetAllergyForm"
                    >
                        Cancel
                    </Button>
                </div>
            </form>

            <Button
                v-if="
                    allergies.canReview &&
                    allergies.status !== 'unknown' &&
                    !reviewCurrent
                "
                size="sm"
                type="button"
                :disabled="allergyBusy"
                @click="reviewAllergies"
            >
                Reviewed for this consultation
            </Button>
        </div>

        <div
            v-else
            class="rounded-md border border-amber-300 bg-amber-100/60 px-3 py-2 text-sm text-amber-900"
        >
            Allergy information is unavailable for this consultation. Do not
            interpret this as no known allergies.
        </div>

        <div v-if="problems" class="space-y-3 border-t pt-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-semibold">Problem List</h3>
                    <p class="text-xs text-muted-foreground">
                        Active conditions are shown first. No automated medicine
                        checks are performed.
                    </p>
                </div>
                <Button
                    v-if="problems.canUpdate"
                    size="sm"
                    variant="outline"
                    type="button"
                    @click="showProblemForm = true"
                >
                    <Plus class="size-3.5" /> Add problem
                </Button>
            </div>

            <div
                v-for="record in problems.active"
                :key="record.publicId"
                class="flex flex-wrap items-start justify-between gap-3 border-t py-3 text-sm"
            >
                <div>
                    <div class="font-medium">{{ record.condition }}</div>
                    <div class="text-xs text-muted-foreground">
                        <span v-if="record.conditionCode">
                            {{ record.codeSystem }} {{ record.conditionCode }} ·
                        </span>
                        Onset: {{ record.onsetDate ?? 'Not recorded' }}
                    </div>
                </div>
                <div v-if="problems.canUpdate" class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="ghost"
                        type="button"
                        @click="startProblemEdit(record)"
                    >
                        Edit
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        type="button"
                        :disabled="problemBusy"
                        @click="transitionProblem(record, 'resolve')"
                    >
                        Resolve
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        type="button"
                        :disabled="problemBusy"
                        @click="transitionProblem(record, 'entered-in-error')"
                    >
                        Entered in error
                    </Button>
                </div>
            </div>

            <p
                v-if="!problems.active.length"
                class="rounded-md bg-background px-3 py-2 text-sm text-muted-foreground"
            >
                No active structured conditions recorded. This does not imply
                that no medical conditions exist.
            </p>

            <details v-if="problems.resolved.length" class="text-sm">
                <summary class="cursor-pointer font-medium">
                    Resolved problems ({{ problems.resolved.length }})
                </summary>
                <div
                    v-for="record in problems.resolved"
                    :key="record.publicId"
                    class="flex flex-wrap items-center justify-between gap-2 border-t py-2"
                >
                    <span>
                        {{ record.condition }} · Resolved
                        {{ record.resolvedDate ?? 'date not recorded' }}
                    </span>
                    <Button
                        v-if="problems.canUpdate"
                        size="sm"
                        variant="ghost"
                        type="button"
                        :disabled="problemBusy"
                        @click="transitionProblem(record, 'entered-in-error')"
                    >
                        Entered in error
                    </Button>
                </div>
            </details>

            <form
                v-if="showProblemForm && problems.canUpdate"
                class="grid gap-3 rounded-md bg-background p-3 md:grid-cols-2"
                @submit.prevent="saveProblem"
            >
                <label class="text-sm md:col-span-2">
                    Condition
                    <input
                        v-model="problemForm.condition_text"
                        maxlength="500"
                        required
                        class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                    />
                </label>
                <label class="text-sm">
                    Condition code
                    <input
                        v-model="problemForm.condition_code"
                        maxlength="50"
                        class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                    />
                </label>
                <label class="text-sm">
                    Code system
                    <input
                        v-model="problemForm.code_system"
                        maxlength="50"
                        class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                    />
                </label>
                <label class="text-sm">
                    Onset date
                    <input
                        v-model="problemForm.onset_date"
                        type="date"
                        class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                    />
                </label>
                <p
                    v-if="Object.keys(problemForm.errors).length"
                    class="text-xs text-red-700 md:col-span-2"
                >
                    Review the highlighted Problem information and try again.
                </p>
                <div class="flex gap-2 md:col-span-2">
                    <Button size="sm" :disabled="problemForm.processing">
                        {{ editingProblem ? 'Save problem' : 'Add problem' }}
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        type="button"
                        @click="resetProblemForm"
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        </div>

        <div v-else class="border-t pt-4 text-sm text-muted-foreground">
            Structured Problem List information is unavailable for this
            consultation.
        </div>
    </section>
</template>
