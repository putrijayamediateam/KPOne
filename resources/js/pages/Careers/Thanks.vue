<script setup lang="ts">
// HR-13: shown after every application, and to robots too. It shows nothing that was sent. HR-17b: after a real
// application it also shows the private link once (HR-PLAN 19.5); it is not shown again on a reload.
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{ clinicName: string; statusLink?: string | null }>();

const copied = ref(false);
const linkBox = ref<HTMLInputElement | null>(null);
const copy = async () => {
    if (!props.statusLink) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.statusLink);
        copied.value = true;
    } catch {
        // Some browsers refuse the clipboard: select the link so it can be copied by hand.
        linkBox.value?.select();
    }
};
</script>

<template>
    <Head title="Application sent / Permohonan dihantar"
        ><meta name="referrer" content="no-referrer"
    /></Head>
    <main class="mx-auto min-h-svh max-w-xl px-4 py-5 sm:px-6 sm:py-8">
        <section
            class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-xl shadow-zinc-950/5 dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="h-1.5 bg-pink-600" aria-hidden="true"></div>
            <div class="space-y-4 p-5 sm:p-8" data-test="apply-thanks">
                <p
                    class="text-sm font-semibold text-pink-700 dark:text-pink-400"
                >
                    {{ clinicName }}
                </p>
                <h1 class="text-xl font-semibold">
                    Thank you for applying
                    <span
                        lang="ms"
                        class="block text-base font-normal text-zinc-600 dark:text-zinc-400"
                        >Terima kasih kerana memohon</span
                    >
                </h1>
                <p class="text-sm">
                    We have your application. If you are shortlisted, the
                    clinic's HR team will contact you by phone or email.
                </p>
                <p lang="ms" class="text-sm text-zinc-600 dark:text-zinc-400">
                    Permohonan anda telah diterima. Jika anda disenarai pendek,
                    pasukan HR klinik akan menghubungi anda melalui telefon atau
                    e-mel.
                </p>
                <div
                    v-if="statusLink"
                    class="space-y-3 rounded-2xl border border-pink-200 bg-pink-50 p-4 dark:border-pink-900/60 dark:bg-pink-950/30"
                    data-test="apply-status-link"
                >
                    <h2 class="text-sm font-semibold">
                        Your private link
                        <span
                            lang="ms"
                            class="block font-normal text-zinc-600 dark:text-zinc-400"
                            >Pautan peribadi anda</span
                        >
                    </h2>
                    <p class="text-sm">
                        Save this link to follow your application. It is shown
                        only now. Anyone with the link can see your application,
                        so keep it private.
                    </p>
                    <p
                        lang="ms"
                        class="text-sm text-zinc-600 dark:text-zinc-400"
                    >
                        Simpan pautan ini untuk mengikuti permohonan anda. Ia
                        ditunjukkan sekarang sahaja. Sesiapa yang mempunyai
                        pautan ini boleh melihat permohonan anda, jadi
                        rahsiakannya.
                    </p>
                    <label for="status-link" class="sr-only"
                        >Your private link</label
                    >
                    <input
                        id="status-link"
                        ref="linkBox"
                        :value="statusLink"
                        readonly
                        class="min-h-11 w-full min-w-0 rounded-lg border border-zinc-300 bg-white px-3 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-950"
                        @focus="linkBox?.select()"
                    />
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-lg bg-pink-700 px-4 text-sm font-medium text-white hover:bg-pink-800 focus-visible:ring-2 focus-visible:ring-pink-500 focus-visible:ring-offset-2 focus-visible:outline-none"
                            data-test="apply-status-copy"
                            @click="copy"
                        >
                            Copy link / Salin pautan
                        </button>
                        <a
                            :href="statusLink"
                            class="inline-flex min-h-11 items-center text-sm font-medium text-pink-700 underline underline-offset-4 dark:text-pink-400"
                            >Open it now / Buka sekarang</a
                        >
                        <span
                            v-if="copied"
                            role="status"
                            class="text-sm text-zinc-600 dark:text-zinc-400"
                            >Copied. / Disalin.</span
                        >
                    </div>
                </div>
                <a
                    href="/careers/apply"
                    class="inline-flex min-h-11 items-center text-sm font-medium text-pink-700 underline underline-offset-4 dark:text-pink-400"
                    >Apply for another position / Mohon jawatan lain</a
                >
            </div>
        </section>
    </main>
</template>
