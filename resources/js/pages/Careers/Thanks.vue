<script setup lang="ts">
// HR-13: shown after every application, and to robots too. It shows nothing that was sent.
import { Head } from '@inertiajs/vue3';

defineProps<{ clinicName: string }>();
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
                <a
                    href="/careers/apply"
                    class="inline-flex min-h-11 items-center text-sm font-medium text-pink-700 underline underline-offset-4 dark:text-pink-400"
                    >Apply for another position / Mohon jawatan lain</a
                >
            </div>
        </section>
    </main>
</template>
