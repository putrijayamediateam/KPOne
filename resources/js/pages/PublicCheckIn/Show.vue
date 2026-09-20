<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    CheckCircle2,
    LoaderCircle,
    ShieldCheck,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps<{
    clinicName: string;
    branch: { name: string } | null;
    intakeSession: { expiresAt: string } | null;
    statusAvailable: boolean;
    privacyNoticeVersion: string;
    minorAge: number;
}>();
declare global {
    interface Window {
        __KPOnePublicIntakeExchangeToken?: string;
        __KPOnePublicIntakeExchangeAttempted?: boolean;
    }
}
type IntakeSession = { expiresAt: string };

const step = ref(0);
const loading = ref(!props.intakeSession);
const submitting = ref(false);
const session = ref<IntakeSession | null>(props.intakeSession);
const branch = ref<{ name: string } | null>(props.branch);
const errors = ref<Record<string, string[]>>({});
const generalError = ref('');
const form = reactive({
    submission_type: 'patient',
    full_name: '',
    date_of_birth: '',
    sex: 'unknown',
    nationality_code: 'MY',
    mobile_phone: '',
    phone_country: 'MY',
    identifier_type: 'nric',
    identifier_value: '',
    identifier_issuing_country_code: 'MY',
    visit_purpose: '',
    chief_complaint: '',
    complaint_duration: '',
    guardian_name: '',
    guardian_relationship: '',
    guardian_contact_number: '',
    guardian_attestation: false,
    consent_confirmed: false,
    privacy_notice_version: props.privacyNoticeVersion,
});
const csrfToken = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const branchName = computed(() => branch.value?.name ?? 'Klinik Putrijaya');
const errorFor = (field: string) => errors.value[field]?.[0] ?? '';
const validationSummary = computed(() =>
    Object.values(errors.value).flat().filter(Boolean),
);
const request = async (url: string, body?: object) => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = (await response.json()) as Record<string, unknown>;

    if (!response.ok) {
        throw data;
    }

    return data;
};
const exchangeFragment = async () => {
    let rawToken = window.__KPOnePublicIntakeExchangeToken ?? '';
    const exchangeAttempted =
        window.__KPOnePublicIntakeExchangeAttempted === true;
    delete window.__KPOnePublicIntakeExchangeToken;
    delete window.__KPOnePublicIntakeExchangeAttempted;

    if (exchangeAttempted) {
        session.value = null;
        branch.value = null;
    }

    if (exchangeAttempted && rawToken === '') {
        loading.value = false;
        generalError.value =
            'Pautan pendaftaran tidak sah atau telah tamat. Sila imbas semula kod QR atau hadir ke kaunter.';

        return;
    }

    if (rawToken === '') {
        if (session.value) {
            return;
        }

        if (props.statusAvailable) {
            window.location.replace('/check-in/status');

            return;
        }
    }

    loading.value = true;
    generalError.value = '';

    try {
        if (!/^[A-Za-z0-9_-]{43}$/.test(rawToken)) {
            throw new Error('invalid public link token');
        }

        const exchanged = (await request('/check-in/exchange', {
            link_token: rawToken,
        })) as {
            branch: { name: string };
            intakeSession: IntakeSession;
        };
        branch.value = exchanged.branch;
        session.value = exchanged.intakeSession;
    } catch {
        generalError.value =
            'Pautan pendaftaran tidak sah atau telah tamat. Sila imbas semula kod QR atau hadir ke kaunter.';
    } finally {
        rawToken = '';
        loading.value = false;
    }
};
const begin = () => {
    if (!session.value || loading.value) {
        generalError.value =
            'Pendaftaran tidak dapat dimulakan. Sila imbas semula kod QR atau hadir ke kaunter.';

        return;
    }

    generalError.value = '';
    step.value = 1;
};
const next = () => {
    errors.value = {};
    generalError.value = '';
    step.value = Math.min(4, step.value + 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
const back = () => {
    errors.value = {};
    step.value = Math.max(1, step.value - 1);
};
const submit = async () => {
    if (!session.value || submitting.value) {
        return;
    }

    submitting.value = true;
    errors.value = {};
    generalError.value = '';

    try {
        const result = await request('/check-in/intakes', { ...form });
        window.location.assign(result.statusUrl as string);
    } catch (error) {
        const response = error as { errors?: Record<string, string[]> };
        errors.value = response.errors ?? {};
        generalError.value = response.errors
            ? 'Sila semak maklumat yang ditandakan.'
            : 'Maklumat tidak dapat dihantar. Jangan tutup halaman ini; cuba semula dengan maklumat yang sama.';

        if (response.errors) {
            step.value = 2;
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
        submitting.value = false;
    }
};
onMounted(exchangeFragment);
</script>

<template>
    <Head :title="`Daftar di ${branchName}`"
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
                        <p class="text-sm font-semibold text-pink-700">
                            KPOne · Klinik Putrijaya
                        </p>
                        <p
                            class="truncate text-sm text-zinc-600 dark:text-zinc-300"
                        >
                            {{ branchName }}
                        </p>
                    </div>
                </header>
                <div
                    v-if="generalError || validationSummary.length"
                    tabindex="-1"
                    role="alert"
                    aria-live="assertive"
                    class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                >
                    <p class="font-semibold">
                        {{ generalError || 'Sila semak maklumat berikut.' }}
                    </p>
                    <ul
                        v-if="validationSummary.length"
                        class="mt-2 list-disc pl-5"
                    >
                        <li v-for="message in validationSummary" :key="message">
                            {{ message }}
                        </li>
                    </ul>
                </div>

                <template v-if="step === 0">
                    <div class="space-y-4">
                        <div
                            class="grid size-12 place-items-center rounded-2xl bg-pink-50 text-pink-700"
                        >
                            <Building2 class="size-6" aria-hidden="true" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-zinc-500">
                                Pendaftaran mudah alih
                            </p>
                            <h1
                                class="mt-1 text-3xl font-semibold tracking-tight"
                            >
                                Daftar untuk lawatan anda
                            </h1>
                            <p
                                class="mt-3 leading-7 text-zinc-600 dark:text-zinc-300"
                            >
                                Isi maklumat pesakit atau penjaga. Nombor queue
                                hanya akan diberi selepas staf klinik membuat
                                semakan.
                            </p>
                        </div>
                    </div>
                    <div
                        class="flex items-start gap-3 rounded-2xl border bg-zinc-50 p-4 text-sm text-zinc-700 dark:bg-zinc-800"
                    >
                        <ShieldCheck
                            class="mt-0.5 size-5 shrink-0 text-pink-700"
                        />
                        <p>
                            Maklumat disimpan secara terlindung selama 30 hari.
                            <a href="#privacy" class="font-medium underline"
                                >Baca notis privasi</a
                            >.
                        </p>
                    </div>
                    <p
                        class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm font-medium text-amber-950"
                    >
                        Jika anda mengalami sesak nafas teruk, sakit dada,
                        pengsan atau pendarahan banyak, sila terus maklumkan
                        staff di kaunter.
                    </p>
                    <button
                        type="button"
                        class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-pink-700 px-5 py-3 font-semibold text-white focus-visible:ring-4 focus-visible:ring-pink-200 disabled:opacity-60"
                        :disabled="loading"
                        @click="begin"
                    >
                        <LoaderCircle
                            v-if="loading"
                            class="size-5 animate-spin"
                        />{{ loading ? 'Memulakan…' : 'Mula Daftar'
                        }}<ArrowRight v-if="!loading" class="size-5" />
                    </button>
                    <div
                        id="privacy"
                        class="rounded-2xl bg-zinc-50 p-4 text-xs leading-5 text-zinc-600 dark:bg-zinc-800"
                    >
                        <p class="font-semibold">Notis privasi — draf UAT</p>
                        <p class="mt-1">
                            Versi {{ privacyNoticeVersion }} belum merupakan
                            teks undang-undang muktamad. Maklumat digunakan
                            untuk semakan pendaftaran di cawangan ini sahaja.
                        </p>
                    </div>
                </template>

                <template v-else
                    ><div
                        class="flex items-center justify-between text-xs font-medium text-zinc-500"
                    >
                        <span>Langkah {{ step }} daripada 4</span
                        ><span>{{ branchName }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-zinc-100">
                        <div
                            class="h-full rounded-full bg-pink-600 transition-all"
                            :style="{ width: `${step * 25}%` }"
                        ></div></div
                ></template>

                <section v-if="step === 1" class="space-y-5">
                    <div>
                        <h1 class="text-2xl font-semibold">
                            Siapa yang mengisi borang?
                        </h1>
                        <p class="mt-1 text-sm text-zinc-600">
                            Pesakit bawah {{ minorAge }} tahun mesti didaftarkan
                            oleh penjaga.
                        </p>
                    </div>
                    <div class="grid gap-3">
                        <button
                            type="button"
                            :aria-pressed="form.submission_type === 'patient'"
                            class="flex min-h-20 items-center gap-4 rounded-2xl border p-4 text-left focus-visible:ring-4 focus-visible:ring-pink-200"
                            :class="
                                form.submission_type === 'patient'
                                    ? 'border-pink-600 bg-pink-50'
                                    : ''
                            "
                            @click="form.submission_type = 'patient'"
                        >
                            <UserRound class="size-7 text-pink-700" /><span
                                ><strong>Saya pesakit</strong><br /><span
                                    class="text-sm text-zinc-600"
                                    >Saya mengisi maklumat sendiri</span
                                ></span
                            >
                        </button>
                        <button
                            type="button"
                            :aria-pressed="form.submission_type === 'guardian'"
                            class="flex min-h-20 items-center gap-4 rounded-2xl border p-4 text-left focus-visible:ring-4 focus-visible:ring-pink-200"
                            :class="
                                form.submission_type === 'guardian'
                                    ? 'border-pink-600 bg-pink-50'
                                    : ''
                            "
                            @click="form.submission_type = 'guardian'"
                        >
                            <UsersRound class="size-7 text-pink-700" /><span
                                ><strong>Saya penjaga</strong><br /><span
                                    class="text-sm text-zinc-600"
                                    >Saya diberi kuasa untuk membantu
                                    pesakit</span
                                ></span
                            >
                        </button>
                    </div>
                    <p
                        v-if="errorFor('submission_type')"
                        class="text-sm text-red-700"
                        role="alert"
                    >
                        {{ errorFor('submission_type') }}
                    </p>
                    <button
                        type="button"
                        class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-pink-700 px-5 font-semibold text-white"
                        @click="next"
                    >
                        Seterusnya <ArrowRight class="size-5" />
                    </button>
                </section>

                <section v-if="step === 2" class="space-y-5">
                    <div>
                        <h1 class="text-2xl font-semibold">Maklumat pesakit</h1>
                        <p class="mt-1 text-sm text-zinc-600">
                            Semua medan bertanda * diperlukan.
                        </p>
                    </div>
                    <div class="grid gap-4">
                        <label class="grid gap-1.5" for="full-name"
                            ><span class="text-sm font-medium"
                                >Nama penuh *</span
                            ><input
                                id="full-name"
                                v-model="form.full_name"
                                autocomplete="name"
                                class="min-h-12 rounded-xl border px-3"
                                :aria-invalid="!!errorFor('full_name')"
                            /><span
                                v-if="errorFor('full_name')"
                                class="text-sm text-red-700"
                                role="alert"
                                >{{ errorFor('full_name') }}</span
                            ></label
                        >
                        <div class="grid grid-cols-2 gap-3">
                            <label class="grid gap-1.5" for="dob"
                                ><span class="text-sm font-medium"
                                    >Tarikh lahir *</span
                                ><input
                                    id="dob"
                                    v-model="form.date_of_birth"
                                    type="date"
                                    class="min-h-12 rounded-xl border px-3"
                                    :aria-invalid="!!errorFor('date_of_birth')"
                                /><span
                                    v-if="errorFor('date_of_birth')"
                                    class="text-sm text-red-700"
                                    role="alert"
                                    >{{ errorFor('date_of_birth') }}</span
                                ></label
                            ><label class="grid gap-1.5" for="sex"
                                ><span class="text-sm font-medium"
                                    >Jantina *</span
                                ><select
                                    id="sex"
                                    v-model="form.sex"
                                    class="min-h-12 rounded-xl border px-3"
                                >
                                    <option value="female">Perempuan</option>
                                    <option value="male">Lelaki</option>
                                    <option value="indeterminate">
                                        Tidak ditentukan
                                    </option>
                                    <option value="unknown">Tidak pasti</option>
                                </select></label
                            >
                        </div>
                        <label class="grid gap-1.5" for="phone"
                            ><span class="text-sm font-medium"
                                >Nombor telefon *</span
                            ><input
                                id="phone"
                                v-model="form.mobile_phone"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel"
                                class="min-h-12 rounded-xl border px-3"
                                :aria-invalid="!!errorFor('mobile_phone')"
                            /><span
                                v-if="errorFor('mobile_phone')"
                                class="text-sm text-red-700"
                                role="alert"
                                >{{ errorFor('mobile_phone') }}</span
                            ></label
                        >
                        <fieldset class="grid gap-2">
                            <legend class="text-sm font-medium">
                                Pengenalan *
                            </legend>
                            <div class="grid grid-cols-[8rem_1fr] gap-2">
                                <select
                                    v-model="form.identifier_type"
                                    aria-label="Jenis pengenalan"
                                    class="min-h-12 rounded-xl border px-3"
                                >
                                    <option value="nric">No. IC</option>
                                    <option value="passport">
                                        Passport
                                    </option></select
                                ><input
                                    v-model="form.identifier_value"
                                    autocomplete="off"
                                    class="min-h-12 min-w-0 rounded-xl border px-3"
                                    aria-label="Nombor pengenalan"
                                    :aria-invalid="
                                        !!errorFor('identifier_value')
                                    "
                                />
                            </div>
                            <input
                                v-if="form.identifier_type === 'passport'"
                                v-model="form.identifier_issuing_country_code"
                                maxlength="2"
                                aria-label="Kod negara pengeluar passport"
                                class="min-h-12 rounded-xl border px-3 uppercase"
                                placeholder="Kod negara, contoh MY"
                            /><span
                                v-if="errorFor('identifier_value')"
                                class="text-sm text-red-700"
                                role="alert"
                                >{{ errorFor('identifier_value') }}</span
                            >
                        </fieldset>
                        <label class="grid gap-1.5" for="visit-purpose"
                            ><span class="text-sm font-medium"
                                >Tujuan lawatan *</span
                            ><select
                                id="visit-purpose"
                                v-model="form.visit_purpose"
                                class="min-h-12 rounded-xl border px-3"
                                :aria-invalid="!!errorFor('visit_purpose')"
                            >
                                <option value="" disabled>Pilih tujuan</option>
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
                                <option value="other">Lain-lain</option></select
                            ><span
                                v-if="errorFor('visit_purpose')"
                                class="text-sm text-red-700"
                                role="alert"
                                >{{ errorFor('visit_purpose') }}</span
                            ></label
                        >
                        <label class="grid gap-1.5" for="chief-complaint"
                            ><span class="text-sm font-medium"
                                >Apa masalah atau tujuan utama anda datang hari
                                ini? *</span
                            ><textarea
                                id="chief-complaint"
                                v-model="form.chief_complaint"
                                rows="3"
                                maxlength="500"
                                class="rounded-xl border px-3 py-3"
                                placeholder="Contoh: Demam dan batuk sejak semalam."
                                :aria-invalid="!!errorFor('chief_complaint')"
                            ></textarea
                            ><span
                                v-if="errorFor('chief_complaint')"
                                class="text-sm text-red-700"
                                role="alert"
                                >{{ errorFor('chief_complaint') }}</span
                            ></label
                        >
                        <label class="grid gap-1.5" for="complaint-duration"
                            ><span class="text-sm font-medium"
                                >Sejak bila? (pilihan)</span
                            ><input
                                id="complaint-duration"
                                v-model="form.complaint_duration"
                                maxlength="120"
                                class="min-h-12 rounded-xl border px-3"
                                placeholder="Contoh: Sejak semalam"
                        /></label>
                    </div>
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 rounded-xl border px-4"
                            @click="back"
                        >
                            <ArrowLeft
                                class="size-5"
                                aria-label="Kembali"
                            /></button
                        ><button
                            type="button"
                            class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-pink-700 px-5 font-semibold text-white"
                            @click="next"
                        >
                            Seterusnya <ArrowRight class="size-5" />
                        </button>
                    </div>
                </section>

                <section v-if="step === 3" class="space-y-5">
                    <div>
                        <h1 class="text-2xl font-semibold">Maklumat penjaga</h1>
                        <p class="mt-1 text-sm text-zinc-600">
                            {{
                                form.submission_type === 'guardian'
                                    ? 'Lengkapkan pengesahan penjaga.'
                                    : 'Tiada maklumat penjaga diperlukan.'
                            }}
                        </p>
                    </div>
                    <div
                        v-if="form.submission_type === 'guardian'"
                        class="grid gap-4"
                    >
                        <label class="grid gap-1.5"
                            ><span class="text-sm font-medium"
                                >Nama penjaga *</span
                            ><input
                                v-model="form.guardian_name"
                                autocomplete="name"
                                class="min-h-12 rounded-xl border px-3"
                            /><span
                                v-if="errorFor('guardian_name')"
                                class="text-sm text-red-700"
                                >{{ errorFor('guardian_name') }}</span
                            ></label
                        >
                        <label class="grid gap-1.5"
                            ><span class="text-sm font-medium">Hubungan *</span
                            ><select
                                v-model="form.guardian_relationship"
                                class="min-h-12 rounded-xl border px-3"
                            >
                                <option value="" disabled>
                                    Pilih hubungan
                                </option>
                                <option value="parent">Ibu / Bapa</option>
                                <option value="legal_guardian">
                                    Penjaga sah
                                </option>
                                <option value="spouse">Suami / Isteri</option>
                                <option value="adult_child">Anak dewasa</option>
                                <option value="sibling">Adik-beradik</option>
                                <option value="other">Lain-lain</option>
                            </select></label
                        >
                        <label class="grid gap-1.5"
                            ><span class="text-sm font-medium"
                                >Nombor telefon penjaga *</span
                            ><input
                                v-model="form.guardian_contact_number"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel"
                                class="min-h-12 rounded-xl border px-3"
                        /></label>
                        <label
                            class="flex items-start gap-3 rounded-2xl border p-4 text-sm"
                            ><input
                                v-model="form.guardian_attestation"
                                type="checkbox"
                                class="mt-1 size-5"
                            /><span
                                >Saya mengesahkan bahawa saya diberi kuasa untuk
                                menghantar maklumat pesakit ini.</span
                            ></label
                        >
                    </div>
                    <div
                        v-else
                        class="flex items-center gap-3 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-800"
                    >
                        <CheckCircle2 class="size-5" />Teruskan ke persetujuan.
                    </div>
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 rounded-xl border px-4"
                            @click="back"
                        >
                            <ArrowLeft
                                class="size-5"
                                aria-label="Kembali"
                            /></button
                        ><button
                            type="button"
                            class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-pink-700 px-5 font-semibold text-white"
                            @click="next"
                        >
                            Semak persetujuan <ArrowRight class="size-5" />
                        </button>
                    </div>
                </section>

                <form
                    v-if="step === 4"
                    class="space-y-5"
                    @submit.prevent="submit"
                >
                    <div>
                        <h1 class="text-2xl font-semibold">
                            Persetujuan & hantar
                        </h1>
                        <p class="mt-1 text-sm text-zinc-600">
                            Staf akan menyemak maklumat sekali sebelum
                            pendaftaran dan queue dicipta.
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border bg-zinc-50 p-4 text-sm leading-6 dark:bg-zinc-800"
                    >
                        <p class="font-semibold">Notis privasi — draf UAT</p>
                        <p>
                            Versi {{ privacyNoticeVersion }}. Teks ini masih
                            memerlukan kelulusan privasi/undang-undang.
                        </p>
                    </div>
                    <label
                        class="flex items-start gap-3 rounded-2xl border p-4 text-sm"
                        ><input
                            v-model="form.consent_confirmed"
                            type="checkbox"
                            class="mt-1 size-5"
                        /><span
                            >Saya bersetuju maklumat ini digunakan untuk
                            pendaftaran lawatan di {{ branchName }}.</span
                        ></label
                    >
                    <span
                        v-if="errorFor('consent_confirmed')"
                        class="text-sm text-red-700"
                        role="alert"
                        >{{ errorFor('consent_confirmed') }}</span
                    >
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 rounded-xl border px-4"
                            @click="back"
                        >
                            <ArrowLeft
                                class="size-5"
                                aria-label="Kembali"
                            /></button
                        ><button
                            type="submit"
                            class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-pink-700 px-5 font-semibold text-white disabled:opacity-60"
                            :disabled="submitting"
                        >
                            <LoaderCircle
                                v-if="submitting"
                                class="size-5 animate-spin"
                            />{{
                                submitting ? 'Menghantar…' : 'Hantar Maklumat'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</template>
