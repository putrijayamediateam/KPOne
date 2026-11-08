<script setup lang="ts">
// HR-13 public job-application form (HR-PLAN 15.2). The server is the only judge: it checks the signed form token,
// the honeypot, the time taken, every field and the CV by its content. Nothing typed here is kept in the browser.
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';

type Position = { id: string; title: string; branch: string | null };

const props = defineProps<{
    clinicName: string;
    positions: Position[];
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
    privacy_accepted: false,
    website: '',
    cv: null as File | null,
});

const formError = computed(
    () => (form.errors as Record<string, string | undefined>).form,
);
const cvPicker = ref<InstanceType<typeof FileChooseButton> | null>(null);
const onCv = (event: Event) => {
    form.cv = (event.target as HTMLInputElement).files?.[0] ?? null;
};
const submit = () => {
    form.post('/careers/apply', {
        forceFormData: true,
        preserveScroll: true,
        // The CV is never kept after a refusal: the applicant chooses it again.
        onError: () => {
            form.reset('cv');
            cvPicker.value?.clear();
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
                    <p
                        v-if="formError"
                        role="alert"
                        class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                        data-test="apply-form-error"
                    >
                        {{ formError }}
                    </p>

                    <div class="space-y-1.5">
                        <label for="apply-position" class="text-sm font-medium"
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

                    <div class="space-y-1.5">
                        <label for="apply-message" class="text-sm font-medium"
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
                            Up to 1,000 characters. Please do not include your
                            IC number or health details.
                            <span lang="ms" class="block"
                                >Sehingga 1,000 aksara. Jangan sertakan nombor
                                kad pengenalan atau maklumat kesihatan
                                anda.</span
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

                    <div class="space-y-1.5">
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
                            :aria-invalid="form.errors.cv ? 'true' : undefined"
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
                            consider your application for this position. Only
                            the clinic's HR team can see it. It is never used
                            for marketing. Your details and CV are deleted after
                            the period the clinic sets.
                        </p>
                        <p lang="ms" class="text-zinc-600 dark:text-zinc-400">
                            {{ clinicName }} menggunakan maklumat yang anda
                            hantar di sini hanya untuk menimbang permohonan anda
                            bagi jawatan ini. Hanya pasukan HR klinik boleh
                            melihatnya. Ia tidak sekali-kali digunakan untuk
                            pemasaran. Maklumat dan CV anda dipadam selepas
                            tempoh yang ditetapkan oleh klinik.
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
                                >I have read the privacy notice and agree that
                                the clinic may use my details to consider my
                                application.
                                <span
                                    lang="ms"
                                    class="block text-zinc-600 dark:text-zinc-400"
                                    >Saya telah membaca notis privasi dan
                                    bersetuju klinik menggunakan maklumat saya
                                    untuk menimbang permohonan saya.</span
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

                    <button
                        type="submit"
                        class="flex min-h-11 w-full items-center justify-center rounded-xl bg-pink-700 px-4 font-semibold text-white hover:bg-pink-800 focus-visible:ring-2 focus-visible:ring-pink-600 focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                        :disabled="form.processing"
                        data-test="apply-submit"
                    >
                        {{
                            form.processing
                                ? 'Sending… / Menghantar…'
                                : 'Send application / Hantar permohonan'
                        }}
                    </button>
                </form>
            </div>
        </section>
    </main>
</template>
