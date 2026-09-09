<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2, ShieldCheck } from '@lucide/vue';

defineProps<{ clinicName: string; branch: { name: string } }>();
</script>

<template>
    <Head :title="`Check in at ${branch.name}`" />
    <main
        class="mx-auto grid min-h-svh max-w-3xl place-items-center px-5 py-10 sm:px-8"
    >
        <section
            class="w-full overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-xl shadow-zinc-950/5 dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="h-1.5 bg-pink-600" aria-hidden="true"></div>
            <div class="space-y-8 p-7 sm:p-10">
                <header class="flex items-center gap-3">
                    <img
                        src="/kp-mark.png"
                        :alt="clinicName"
                        class="size-11 object-contain"
                    />
                    <div>
                        <p
                            class="text-sm font-medium text-pink-700 dark:text-pink-300"
                        >
                            KPOne
                        </p>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ clinicName }}
                        </p>
                    </div>
                </header>

                <div class="space-y-4">
                    <div
                        class="grid size-12 place-items-center rounded-2xl bg-pink-50 text-pink-700 dark:bg-pink-950/40 dark:text-pink-300"
                        aria-hidden="true"
                    >
                        <Building2 class="size-6" />
                    </div>
                    <div class="space-y-2">
                        <p
                            class="text-sm font-medium text-zinc-500 dark:text-zinc-400"
                        >
                            You are checking in at
                        </p>
                        <h1
                            class="text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            {{ branch.name }}
                        </h1>
                        <p
                            class="max-w-xl text-base leading-7 text-zinc-600 dark:text-zinc-300"
                        >
                            Public self check-in is being prepared for this
                            branch. No Patient information is collected on this
                            page.
                        </p>
                    </div>
                </div>

                <div
                    class="flex items-start gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-300"
                >
                    <ShieldCheck
                        class="mt-0.5 size-5 shrink-0 text-pink-700 dark:text-pink-300"
                        aria-hidden="true"
                    />
                    <p>
                        Please speak with our clinic staff to register your
                        visit while this service is being prepared.
                    </p>
                </div>
            </div>
        </section>
    </main>
</template>
