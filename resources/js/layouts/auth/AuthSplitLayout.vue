<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, ShieldCheck } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="grid min-h-svh bg-slate-100 lg:grid-cols-[minmax(0,1.05fr)_minmax(460px,0.95fr)] dark:bg-slate-950"
    >
        <div
            class="relative hidden min-h-svh overflow-hidden bg-slate-950 p-10 text-white lg:flex lg:flex-col xl:p-14 dark:border-r dark:border-white/10"
        >
            <div
                class="pointer-events-none absolute -top-32 -left-28 size-96 rounded-full border border-pink-400/10 bg-pink-400/[0.04]"
            />
            <div
                class="pointer-events-none absolute right-[-8rem] bottom-[-10rem] size-[30rem] rounded-full border border-white/[0.05]"
            />
            <Link
                href="/"
                class="relative z-20 inline-flex w-fit items-center gap-3 rounded-lg text-lg font-semibold tracking-tight focus-visible:ring-2 focus-visible:ring-pink-400/70 focus-visible:ring-offset-4 focus-visible:ring-offset-slate-950 focus-visible:outline-none"
            >
                <AppLogoIcon class="size-11 text-pink-500" />
                {{ name }}
            </Link>
            <div class="relative z-20 my-auto max-w-lg py-16">
                <div class="mb-8 h-px w-14 bg-pink-500" aria-hidden="true" />
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-pink-300 uppercase"
                >
                    Klinik Putrijaya workspace
                </p>
                <h2
                    class="mt-5 text-4xl leading-[1.12] font-semibold tracking-[-0.035em] text-white xl:text-5xl"
                >
                    Clear, connected clinic operations.
                </h2>
                <p class="mt-6 max-w-md text-base leading-7 text-slate-300">
                    A focused workspace for authorised teams to coordinate care
                    and daily clinic work.
                </p>
                <div
                    class="mt-10 flex flex-wrap gap-x-8 gap-y-4 text-sm text-slate-300"
                >
                    <div class="flex items-center gap-3">
                        <ShieldCheck class="size-4 text-pink-300" />
                        Permission-aware access
                    </div>
                    <div class="flex items-center gap-3">
                        <Building2 class="size-4 text-pink-300" />
                        Branch-aware operations
                    </div>
                </div>
            </div>
            <p class="relative z-20 text-xs text-slate-500">
                Authorised Klinik Putrijaya staff only
            </p>
        </div>

        <main
            class="flex min-h-svh items-center justify-center px-5 py-8 sm:px-8 lg:px-12"
        >
            <div
                class="w-full max-w-md rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xl shadow-slate-950/[0.06] sm:p-9 dark:border-white/10 dark:bg-slate-900 dark:shadow-black/20"
            >
                <Link
                    href="/"
                    class="mb-8 inline-flex items-center gap-3 rounded-lg font-semibold tracking-tight text-slate-950 focus-visible:ring-2 focus-visible:ring-pink-500/60 focus-visible:ring-offset-4 focus-visible:outline-none lg:hidden dark:text-white dark:focus-visible:ring-offset-slate-900"
                >
                    <AppLogoIcon
                        class="size-10 text-pink-600 dark:text-pink-500"
                    />
                    {{ name }}
                </Link>
                <div class="space-y-2">
                    <h1
                        v-if="title"
                        class="text-2xl font-semibold tracking-[-0.025em] text-slate-950 dark:text-white"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-sm leading-6 text-slate-600 dark:text-slate-300"
                    >
                        {{ description }}
                    </p>
                </div>
                <div class="mt-8 space-y-6"><slot /></div>
            </div>
        </main>
    </div>
</template>
