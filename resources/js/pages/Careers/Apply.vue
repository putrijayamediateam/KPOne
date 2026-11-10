<script setup lang="ts">
// HR-13 public job-application form (HR-PLAN 15.2), with HR-17a's questions per position type (HR-PLAN 19.4). The
// server is the only judge: it checks the signed form token, the honeypot, the time taken, every field and the CV by
// its content. Nothing typed here is kept in the browser. No IC number, date of birth, photo, salary or wage is asked.
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import { DateField } from '@/components/ui/date-field';
import {
    STEP_COUNT,
    shiftDate,
    stepOf,
    validateApplicationStep,
} from '@/lib/job-application-form';

import type { PositionType } from '@/lib/job-application-form';
type Position = {
    id: string;
    title: string;
    branch: string | null;
    type: PositionType;
};
type Choice = { value: string; label: string };

const props = defineProps<{
    clinicName: string;
    positions: Position[];
    branches: Choice[];
    education: Choice[];
    languages: Choice[];
    formToken: string;
    privacyNoticeVersion: string;
    cvMaxMb: number;
}>();

const form = useForm({
    form_token: props.formToken,
    position: props.positions.length === 1 ? props.positions[0].id : '',
    name: '',
    phone: '',
    email: '',
    message: '',
    preferred_branch: '',
    start_date: '',
    education: '',
    years_experience: '',
    languages: [] as string[],
    institution: '',
    programme: '',
    intern_from: '',
    intern_to: '',
    availability: '',
    mmc_number: '',
    apc_year: '',
    apc_expiry: '',
    privacy_accepted: false,
    website: '',
    cv: null as File | null,
});

// The questions only this kind of position asks (HR-PLAN 19.4).
const positionType = computed<PositionType | null>(
    () => props.positions.find((p) => p.id === form.position)?.type ?? null,
);
const errorOf = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];
type Question = {
    key:
        | 'institution'
        | 'programme'
        | 'intern_from'
        | 'intern_to'
        | 'mmc_number'
        | 'apc_year'
        | 'apc_expiry';
    label: string;
    ms: string;
    type: 'text' | 'date' | 'number';
    inputmode?: 'numeric';
};
const typeQuestions = computed<Question[]>(() => {
    if (positionType.value === 'intern') {
        return [
            {
                key: 'institution',
                label: 'College or university',
                ms: 'Kolej atau universiti',
                type: 'text',
            },
            {
                key: 'programme',
                label: 'Course or programme',
                ms: 'Kursus atau program',
                type: 'text',
            },
            {
                key: 'intern_from',
                label: 'Internship starts',
                ms: 'Latihan industri bermula',
                type: 'date',
            },
            {
                key: 'intern_to',
                label: 'Internship ends',
                ms: 'Latihan industri tamat',
                type: 'date',
            },
        ];
    }

    if (positionType.value === 'resident_doctor') {
        return [
            {
                key: 'mmc_number',
                label: 'MMC registration number',
                ms: 'Nombor pendaftaran MMC',
                type: 'text',
                inputmode: 'numeric',
            },
            {
                key: 'apc_year',
                label: 'Year of your current APC',
                ms: 'Tahun APC semasa',
                type: 'number',
                inputmode: 'numeric',
            },
            {
                key: 'apc_expiry',
                label: 'APC expiry date',
                ms: 'Tarikh APC tamat',
                type: 'date',
            },
        ];
    }

    return [];
});
const typeTitle: Record<PositionType, string> = {
    ca: '',
    intern: 'About your internship / Tentang latihan industri anda',
    locum: 'Your availability / Masa anda lapang',
    resident_doctor: 'Your registration / Pendaftaran anda',
};

const formError = computed(
    () => (form.errors as Record<string, string | undefined>).form,
);
const cvPicker = ref<InstanceType<typeof FileChooseButton> | null>(null);
const onCv = (event: Event) => {
    form.cv = (event.target as HTMLInputElement).files?.[0] ?? null;
};
// Three steps, as on the QR form (Daniel, HR-17a). Answers live only in this page's memory.
const step = ref(1);
const summary = ref<HTMLElement | null>(null);
const sentOnce = ref(false);
const pad = (n: number) => String(n).padStart(2, '0');
const now = new Date();
const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
const inAYear = shiftDate(today, 0, 1);
const focusField = (key: string) =>
    nextTick(() => {
        const target =
            document.getElementById(`apply-${key}`) ??
            document.querySelector<HTMLElement>(
                `[data-field="${key}"] input, [data-field="${key}"] select, [data-field="${key}"] textarea, [data-field="${key}"] button`,
            );
        target?.focus();
    });
const check = (which: number): boolean => {
    const found = validateApplicationStep(
        which,
        form,
        positionType.value,
        today,
    );
    form.clearErrors();

    if (Object.keys(found).length) {
        form.setError(found);
        focusField(Object.keys(found)[0]);

        return false;
    }

    return true;
};
const next = () => {
    if (!check(step.value)) {
        return;
    }

    step.value = Math.min(STEP_COUNT, step.value + 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
const back = () => {
    form.clearErrors();
    step.value = Math.max(1, step.value - 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
const errorList = computed(() =>
    Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([, message]) => !!message)
        .map(([key, message]) => ({ key, message: message as string })),
);
const submit = () => {
    if (step.value < STEP_COUNT) {
        next();

        return;
    }

    if (!check(STEP_COUNT)) {
        return;
    }

    form.post('/careers/apply', {
        forceFormData: true,
        preserveScroll: true,
        // The CV is never kept after a refusal: the applicant chooses it again.
        onError: (errors) => {
            form.reset('cv');
            cvPicker.value?.clear();
            sentOnce.value = true;
            // Open the earliest step with an error, show the summary and move focus to it.
            const first = Object.keys(errors).sort(
                (a, b) => stepOf(a) - stepOf(b),
            )[0];
            step.value = first ? stepOf(first) : step.value;
            nextTick(() => summary.value?.focus());
        },
    });
};
const field =
    'block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-base text-zinc-950 focus-visible:border-pink-600 focus-visible:ring-2 focus-visible:ring-pink-600/30 focus-visible:outline-none aria-[invalid=true]:border-red-600 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50';
</script>

<template>
    <Head title="Apply for a job / Mohon jawatan"
        ><meta name="referrer" content="no-referrer"
    /></Head>
    <main class="mx-auto min-h-svh max-w-xl px-4 py-5 sm:px-6 sm:py-8">
        <section
            class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-xl shadow-zinc-950/5 dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="h-1.5 bg-pink-600" aria-hidden="true"></div>
            <div class="space-y-6 p-5 sm:p-8">
                <header class="flex items-center gap-3">
                    <img
                        src="/kp-mark.png"
                        :alt="clinicName"
                        class="size-11 object-contain"
                    />
                    <div class="min-w-0">
                        <p
                            class="text-sm font-semibold text-pink-700 dark:text-pink-400"
                        >
                            {{ clinicName }}
                        </p>
                        <h1 class="text-xl font-semibold">
                            Apply for a job
                            <span
                                lang="ms"
                                class="block text-base font-normal text-zinc-600 dark:text-zinc-400"
                                >Mohon jawatan</span
                            >
                        </h1>
                    </div>
                </header>

                <p
                    v-if="!positions.length"
                    class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-800"
                    data-test="apply-no-positions"
                >
                    There are no open positions right now. Please check again
                    later.
                    <span
                        lang="ms"
                        class="mt-1 block text-zinc-600 dark:text-zinc-400"
                        >Tiada jawatan kosong buat masa ini. Sila semak semula
                        kemudian.</span
                    >
                </p>

                <form
                    v-else
                    class="space-y-5"
                    novalidate
                    data-test="apply-form"
                    @submit.prevent="submit"
                >
                    <div class="space-y-2" data-test="apply-steps">
                        <p
                            class="text-xs font-medium text-zinc-600 dark:text-zinc-400"
                        >
                            Step {{ step }} of {{ STEP_COUNT }}
                            <span lang="ms"
                                >/ Langkah {{ step }} daripada
                                {{ STEP_COUNT }}</span
                            >
                        </p>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                            aria-hidden="true"
                        >
                            <div
                                class="h-full rounded-full bg-pink-600 transition-all"
                                :style="{
                                    width: `${(step / STEP_COUNT) * 100}%`,
                                }"
                            ></div>
                        </div>
                    </div>

                    <div
                        v-if="sentOnce && errorList.length"
                        ref="summary"
                        role="alert"
                        tabindex="-1"
                        class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 focus-visible:outline-2 focus-visible:outline-red-600 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                        data-test="apply-error-summary"
                    >
                        <p class="font-semibold">
                            Please check these answers.
                            <span lang="ms">/ Sila semak jawapan ini.</span>
                        </p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            <li v-for="e in errorList" :key="e.key">
                                {{ e.message }}
                            </li>
                        </ul>
                    </div>
                    <p
                        v-else-if="formError"
                        role="alert"
                        class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                        data-test="apply-form-error"
                    >
                        {{ formError }}
                    </p>

                    <template v-if="step === 1">
                        <h2 class="text-base font-semibold">
                            You and the position<span
                                lang="ms"
                                class="font-normal text-zinc-600 dark:text-zinc-400"
                            >
                                / Anda dan jawatan</span
                            >
                        </h2>
                        <div class="space-y-1.5">
                            <label
                                for="apply-position"
                                class="text-sm font-medium"
                                >Position<span
                                    lang="ms"
                                    class="font-normal text-zinc-600 dark:text-zinc-400"
                                >
                                    / Jawatan</span
                                >
                                *</label
                            >
                            <select
                                id="apply-position"
                                v-model="form.position"
                                :class="field"
                                :aria-invalid="
                                    form.errors.position ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.position
                                        ? 'apply-position-error'
                                        : undefined
                                "
                                required
                            >
                                <option value="" disabled>
                                    Choose a position / Pilih jawatan
                                </option>
                                <option
                                    v-for="p in positions"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{
                                        p.branch
                                            ? `${p.title} · ${p.branch}`
                                            : p.title
                                    }}
                                </option>
                            </select>
                            <p
                                v-if="form.errors.position"
                                id="apply-position-error"
                                role="alert"
                                class="text-sm text-red-700 dark:text-red-400"
                            >
                                {{ form.errors.position }}
                            </p>
                        </div>

                        <div
                            v-for="item in [
                                {
                                    key: 'name',
                                    label: 'Full name',
                                    ms: 'Nama penuh',
                                    type: 'text',
                                    auto: 'name',
                                },
                                {
                                    key: 'phone',
                                    label: 'Phone number',
                                    ms: 'Nombor telefon',
                                    type: 'tel',
                                    auto: 'tel',
                                },
                                {
                                    key: 'email',
                                    label: 'Email',
                                    ms: 'E-mel',
                                    type: 'email',
                                    auto: 'email',
                                },
                            ] as const"
                            :key="item.key"
                            class="space-y-1.5"
                        >
                            <label
                                :for="`apply-${item.key}`"
                                class="text-sm font-medium"
                                >{{ item.label
                                }}<span
                                    lang="ms"
                                    class="font-normal text-zinc-600 dark:text-zinc-400"
                                >
                                    / {{ item.ms }}</span
                                >
                                *</label
                            >
                            <input
                                :id="`apply-${item.key}`"
                                v-model="form[item.key]"
                                :type="item.type"
                                :autocomplete="item.auto"
                                :class="field"
                                :aria-invalid="
                                    form.errors[item.key] ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors[item.key]
                                        ? `apply-${item.key}-error`
                                        : undefined
                                "
                                required
                            />
                            <p
                                v-if="form.errors[item.key]"
                                :id="`apply-${item.key}-error`"
                                role="alert"
                                class="text-sm text-red-700 dark:text-red-400"
                            >
                                {{ form.errors[item.key] }}
                            </p>
                        </div>
                    </template>

                    <template v-if="step === 2">
                        <fieldset class="space-y-4" data-test="apply-about">
                            <legend class="text-base font-semibold">
                                About you<span
                                    lang="ms"
                                    class="font-normal text-zinc-600 dark:text-zinc-400"
                                >
                                    / Tentang anda</span
                                >
                            </legend>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label
                                        for="apply-preferred_branch"
                                        class="text-sm font-medium"
                                        >Preferred branch<span
                                            lang="ms"
                                            class="font-normal text-zinc-600 dark:text-zinc-400"
                                        >
                                            / Cawangan pilihan</span
                                        >
                                        *</label
                                    >
                                    <select
                                        id="apply-preferred_branch"
                                        v-model="form.preferred_branch"
                                        :class="field"
                                        :aria-invalid="
                                            errorOf('preferred_branch')
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            errorOf('preferred_branch')
                                                ? 'apply-preferred_branch-error'
                                                : undefined
                                        "
                                    >
                                        <option value="" disabled>
                                            Choose / Pilih
                                        </option>
                                        <option value="any">
                                            Any branch / Mana-mana cawangan
                                        </option>
                                        <option
                                            v-for="b in branches"
                                            :key="b.value"
                                            :value="b.value"
                                        >
                                            {{ b.label }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="errorOf('preferred_branch')"
                                        id="apply-preferred_branch-error"
                                        role="alert"
                                        class="text-sm text-red-700 dark:text-red-400"
                                    >
                                        {{ errorOf('preferred_branch') }}
                                    </p>
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        for="apply-start_date"
                                        class="text-sm font-medium"
                                        >Earliest start date<span
                                            lang="ms"
                                            class="font-normal text-zinc-600 dark:text-zinc-400"
                                        >
                                            / Tarikh terawal boleh mula</span
                                        >
                                        *</label
                                    >
                                    <!-- The shared dd/mm/yyyy date box (UX-17), never the browser's own. -->
                                    <DateField
                                        id="apply-start_date"
                                        v-model="form.start_date"
                                        :min="today"
                                        :max="inAYear"
                                        :aria-invalid="
                                            errorOf('start_date')
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            errorOf('start_date')
                                                ? 'apply-start_date-hint apply-start_date-error'
                                                : 'apply-start_date-hint'
                                        "
                                    />
                                    <p
                                        id="apply-start_date-hint"
                                        class="text-sm text-zinc-600 dark:text-zinc-400"
                                    >
                                        From today, within a year.
                                        <span lang="ms"
                                            >/ Dari hari ini, dalam tempoh
                                            setahun.</span
                                        >
                                    </p>
                                    <p
                                        v-if="errorOf('start_date')"
                                        id="apply-start_date-error"
                                        role="alert"
                                        class="text-sm text-red-700 dark:text-red-400"
                                    >
                                        {{ errorOf('start_date') }}
                                    </p>
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        for="apply-education"
                                        class="text-sm font-medium"
                                        >Highest education<span
                                            lang="ms"
                                            class="font-normal text-zinc-600 dark:text-zinc-400"
                                        >
                                            / Pendidikan tertinggi</span
                                        >
                                        *</label
                                    >
                                    <select
                                        id="apply-education"
                                        v-model="form.education"
                                        :class="field"
                                        :aria-invalid="
                                            errorOf('education')
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            errorOf('education')
                                                ? 'apply-education-error'
                                                : undefined
                                        "
                                    >
                                        <option value="" disabled>
                                            Choose / Pilih
                                        </option>
                                        <option
                                            v-for="e in education"
                                            :key="e.value"
                                            :value="e.value"
                                        >
                                            {{ e.label }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="errorOf('education')"
                                        id="apply-education-error"
                                        role="alert"
                                        class="text-sm text-red-700 dark:text-red-400"
                                    >
                                        {{ errorOf('education') }}
                                    </p>
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        for="apply-years_experience"
                                        class="text-sm font-medium"
                                        >Years of relevant work<span
                                            lang="ms"
                                            class="font-normal text-zinc-600 dark:text-zinc-400"
                                        >
                                            / Tahun pengalaman berkaitan</span
                                        >
                                        *</label
                                    >
                                    <input
                                        id="apply-years_experience"
                                        v-model="form.years_experience"
                                        type="number"
                                        inputmode="numeric"
                                        min="0"
                                        max="50"
                                        :class="field"
                                        :aria-invalid="
                                            errorOf('years_experience')
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            errorOf('years_experience')
                                                ? 'apply-years_experience-error'
                                                : undefined
                                        "
                                    />
                                    <p
                                        v-if="errorOf('years_experience')"
                                        id="apply-years_experience-error"
                                        role="alert"
                                        class="text-sm text-red-700 dark:text-red-400"
                                    >
                                        {{ errorOf('years_experience') }}
                                    </p>
                                </div>
                            </div>
                            <fieldset
                                class="space-y-2"
                                :aria-invalid="
                                    errorOf('languages') ? 'true' : undefined
                                "
                                :aria-describedby="
                                    errorOf('languages')
                                        ? 'apply-languages-error'
                                        : undefined
                                "
                            >
                                <legend class="text-sm font-medium">
                                    Languages you speak<span
                                        lang="ms"
                                        class="font-normal text-zinc-600 dark:text-zinc-400"
                                    >
                                        / Bahasa yang anda tuturkan</span
                                    >
                                    *
                                </legend>
                                <div class="flex flex-wrap gap-x-5 gap-y-1">
                                    <label
                                        v-for="l in languages"
                                        :key="l.value"
                                        class="flex min-h-11 items-center gap-2.5 text-sm"
                                    >
                                        <input
                                            v-model="form.languages"
                                            type="checkbox"
                                            :value="l.value"
                                            class="size-5 accent-pink-600"
                                        />
                                        {{ l.label }}
                                    </label>
                                </div>
                                <p
                                    v-if="errorOf('languages')"
                                    id="apply-languages-error"
                                    role="alert"
                                    class="text-sm text-red-700 dark:text-red-400"
                                >
                                    {{ errorOf('languages') }}
                                </p>
                            </fieldset>
                        </fieldset>

                        <fieldset
                            v-if="positionType && positionType !== 'ca'"
                            class="space-y-4"
                            :data-test="`apply-type-${positionType}`"
                        >
                            <legend class="text-base font-semibold">
                                {{ typeTitle[positionType] }}
                            </legend>
                            <div
                                v-if="typeQuestions.length"
                                class="grid gap-4 sm:grid-cols-2"
                            >
                                <div
                                    v-for="q in typeQuestions"
                                    :key="q.key"
                                    class="space-y-1.5"
                                >
                                    <label
                                        :for="`apply-${q.key}`"
                                        class="text-sm font-medium"
                                        >{{ q.label
                                        }}<span
                                            lang="ms"
                                            class="font-normal text-zinc-600 dark:text-zinc-400"
                                        >
                                            / {{ q.ms }}</span
                                        >
                                        *</label
                                    >
                                    <DateField
                                        v-if="q.type === 'date'"
                                        :id="`apply-${q.key}`"
                                        v-model="form[q.key]"
                                        :aria-invalid="
                                            errorOf(q.key) ? 'true' : undefined
                                        "
                                        :aria-describedby="
                                            errorOf(q.key)
                                                ? `apply-${q.key}-error`
                                                : undefined
                                        "
                                    />
                                    <input
                                        v-else
                                        :id="`apply-${q.key}`"
                                        v-model="form[q.key]"
                                        :type="q.type"
                                        :inputmode="q.inputmode"
                                        :class="field"
                                        :aria-invalid="
                                            errorOf(q.key) ? 'true' : undefined
                                        "
                                        :aria-describedby="
                                            errorOf(q.key)
                                                ? `apply-${q.key}-error`
                                                : undefined
                                        "
                                    />
                                    <p
                                        v-if="errorOf(q.key)"
                                        :id="`apply-${q.key}-error`"
                                        role="alert"
                                        class="text-sm text-red-700 dark:text-red-400"
                                    >
                                        {{ errorOf(q.key) }}
                                    </p>
                                </div>
                            </div>
                            <div
                                v-if="positionType === 'locum'"
                                class="space-y-1.5"
                            >
                                <label
                                    for="apply-availability"
                                    class="text-sm font-medium"
                                    >Days and times you are usually free<span
                                        lang="ms"
                                        class="font-normal text-zinc-600 dark:text-zinc-400"
                                    >
                                        / Hari dan masa anda biasanya
                                        lapang</span
                                    >
                                    *</label
                                >
                                <textarea
                                    id="apply-availability"
                                    v-model="form.availability"
                                    rows="3"
                                    maxlength="300"
                                    :class="field"
                                    :aria-invalid="
                                        errorOf('availability')
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="
                                        errorOf('availability')
                                            ? 'apply-availability-error'
                                            : undefined
                                    "
                                ></textarea>
                                <p
                                    v-if="errorOf('availability')"
                                    id="apply-availability-error"
                                    role="alert"
                                    class="text-sm text-red-700 dark:text-red-400"
                                >
                                    {{ errorOf('availability') }}
                                </p>
                            </div>
                            <p
                                v-if="positionType === 'resident_doctor'"
                                class="text-sm text-zinc-600 dark:text-zinc-400"
                            >
                                HR checks your MMC registration and APC with you
                                at the interview.
                                <span lang="ms" class="block"
                                    >HR akan menyemak pendaftaran MMC dan APC
                                    anda semasa temu duga.</span
                                >
                            </p>
                        </fieldset>

                        <div class="space-y-1.5">
                            <label
                                for="apply-message"
                                class="text-sm font-medium"
                                >Short message (optional)<span
                                    lang="ms"
                                    class="font-normal text-zinc-600 dark:text-zinc-400"
                                >
                                    / Mesej ringkas (pilihan)</span
                                ></label
                            >
                            <textarea
                                id="apply-message"
                                v-model="form.message"
                                rows="4"
                                maxlength="1000"
                                :class="field"
                                :aria-invalid="
                                    form.errors.message ? 'true' : undefined
                                "
                                aria-describedby="apply-message-hint"
                            ></textarea>
                            <p
                                id="apply-message-hint"
                                class="text-sm text-zinc-600 dark:text-zinc-400"
                            >
                                Up to 1,000 characters. Please do not include
                                your IC number or health details.
                                <span lang="ms" class="block"
                                    >Sehingga 1,000 aksara. Jangan sertakan
                                    nombor kad pengenalan atau maklumat
                                    kesihatan anda.</span
                                >
                            </p>
                            <p
                                v-if="form.errors.message"
                                role="alert"
                                class="text-sm text-red-700 dark:text-red-400"
                            >
                                {{ form.errors.message }}
                            </p>
                        </div>
                    </template>

                    <template v-if="step === 3">
                        <div class="space-y-1.5" data-field="cv">
                            <label for="apply-cv" class="text-sm font-medium"
                                >Your CV (PDF)<span
                                    lang="ms"
                                    class="font-normal text-zinc-600 dark:text-zinc-400"
                                >
                                    / CV anda (PDF)</span
                                >
                                *</label
                            >
                            <FileChooseButton
                                id="apply-cv"
                                ref="cvPicker"
                                accept="application/pdf,.pdf"
                                label="Choose PDF / Pilih PDF"
                                status-id="apply-cv-status"
                                :status="
                                    form.cv
                                        ? form.cv.name
                                        : 'No file chosen / Tiada fail dipilih'
                                "
                                :aria-invalid="
                                    form.errors.cv ? 'true' : undefined
                                "
                                aria-describedby="apply-cv-hint"
                                @change="onCv"
                            />
                            <p
                                id="apply-cv-hint"
                                class="text-sm text-zinc-600 dark:text-zinc-400"
                            >
                                One PDF file, up to {{ cvMaxMb }} MB.
                                <span lang="ms"
                                    >/ Satu fail PDF, sehingga
                                    {{ cvMaxMb }} MB.</span
                                >
                            </p>
                            <p
                                v-if="form.errors.cv"
                                role="alert"
                                class="text-sm text-red-700 dark:text-red-400"
                                data-test="apply-cv-error"
                            >
                                {{ form.errors.cv }}
                            </p>
                        </div>
                    </template>

                    <!-- Left empty by people; robots fill it in. Hidden from sight and from screen readers. -->
                    <div class="sr-only" aria-hidden="true">
                        <label for="apply-website">Website</label>
                        <input
                            id="apply-website"
                            v-model="form.website"
                            type="text"
                            tabindex="-1"
                            autocomplete="off"
                        />
                    </div>

                    <template v-if="step === 3">
                        <section
                            class="space-y-2 rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-800"
                            aria-labelledby="apply-privacy-title"
                            data-test="apply-privacy"
                        >
                            <h2 id="apply-privacy-title" class="font-semibold">
                                Privacy notice<span
                                    lang="ms"
                                    class="font-normal text-zinc-600 dark:text-zinc-400"
                                >
                                    / Notis privasi</span
                                >
                            </h2>
                            <p>
                                {{ clinicName }} uses what you send here only to
                                consider your application for this position, and
                                may email you about it. Only the clinic's HR
                                team can see it. It is never used for marketing.
                                Your details and CV are deleted after the period
                                the clinic sets.
                            </p>
                            <p
                                lang="ms"
                                class="text-zinc-600 dark:text-zinc-400"
                            >
                                {{ clinicName }} menggunakan maklumat yang anda
                                hantar di sini hanya untuk menimbang permohonan
                                anda bagi jawatan ini, dan mungkin menghantar
                                e-mel kepada anda tentangnya. Hanya pasukan HR
                                klinik boleh melihatnya. Ia tidak sekali-kali
                                digunakan untuk pemasaran. Maklumat dan CV anda
                                dipadam selepas tempoh yang ditetapkan oleh
                                klinik.
                            </p>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400">
                                Notice version / Versi notis
                                {{ privacyNoticeVersion }}
                            </p>
                            <label class="flex items-start gap-3 pt-1">
                                <input
                                    v-model="form.privacy_accepted"
                                    type="checkbox"
                                    class="mt-0.5 size-5 accent-pink-600"
                                    :aria-invalid="
                                        form.errors.privacy_accepted
                                            ? 'true'
                                            : undefined
                                    "
                                    data-test="apply-privacy-accept"
                                />
                                <span
                                    >I have read the privacy notice and agree
                                    that the clinic may use my details to
                                    consider my application.
                                    <span
                                        lang="ms"
                                        class="block text-zinc-600 dark:text-zinc-400"
                                        >Saya telah membaca notis privasi dan
                                        bersetuju klinik menggunakan maklumat
                                        saya untuk menimbang permohonan
                                        saya.</span
                                    ></span
                                >
                            </label>
                            <p
                                v-if="form.errors.privacy_accepted"
                                role="alert"
                                class="text-sm text-red-700 dark:text-red-400"
                            >
                                {{ form.errors.privacy_accepted }}
                            </p>
                        </section>
                    </template>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row">
                        <button
                            v-if="step > 1"
                            type="button"
                            class="flex min-h-11 items-center justify-center rounded-xl border border-zinc-300 px-4 font-semibold hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-pink-600 focus-visible:ring-offset-2 focus-visible:outline-none sm:flex-1 dark:border-zinc-700 dark:hover:bg-zinc-800"
                            data-test="apply-back"
                            @click="back"
                        >
                            Back / Kembali
                        </button>
                        <button
                            v-if="step < STEP_COUNT"
                            type="button"
                            class="flex min-h-11 items-center justify-center rounded-xl bg-pink-700 px-4 font-semibold text-white hover:bg-pink-800 focus-visible:ring-2 focus-visible:ring-pink-600 focus-visible:ring-offset-2 focus-visible:outline-none sm:flex-1"
                            data-test="apply-next"
                            @click="next"
                        >
                            Next / Seterusnya
                        </button>
                        <button
                            v-else
                            type="submit"
                            class="flex min-h-11 items-center justify-center rounded-xl bg-pink-700 px-4 font-semibold text-white hover:bg-pink-800 focus-visible:ring-2 focus-visible:ring-pink-600 focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60 sm:flex-1"
                            :disabled="form.processing"
                            data-test="apply-submit"
                        >
                            {{
                                form.processing
                                    ? 'Sending… / Menghantar…'
                                    : 'Send application / Hantar permohonan'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</template>
