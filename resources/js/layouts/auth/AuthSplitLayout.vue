<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { HeartPulse, LockKeyhole, Network } from '@lucide/vue';
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
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div
            class="relative hidden h-full flex-col bg-muted p-10 text-white lg:flex dark:border-r"
        >
            <div
                class="absolute inset-0 bg-[radial-gradient(circle_at_18%_15%,rgba(16,185,129,0.2),transparent_32%),linear-gradient(145deg,#061a18_0%,#0b2423_45%,#111827_100%)]"
            />
            <Link
                href="/"
                class="relative z-20 flex items-center text-lg font-medium"
            >
                <AppLogoIcon class="mr-3 size-10 text-emerald-400" />
                {{ name }}
            </Link>
            <div class="relative z-20 my-auto max-w-xl">
                <div
                    class="mb-7 inline-flex items-center gap-2 rounded-full border border-emerald-300/20 bg-emerald-300/10 px-3 py-1 text-xs font-medium text-emerald-200"
                >
                    <HeartPulse class="size-3.5" /> Klinik Putrijaya Digital
                    Operating System
                </div>
                <h2
                    class="text-4xl leading-tight font-semibold tracking-tight text-white"
                >
                    One secure foundation for clinic operations.
                </h2>
                <p class="mt-5 max-w-lg text-base leading-7 text-slate-300">
                    Staff identity, branch context and responsible access—built
                    for the way Klinik Putrijaya works.
                </p>
                <div
                    class="mt-10 grid gap-4 text-sm text-slate-300 sm:grid-cols-2"
                >
                    <div class="flex items-center gap-3">
                        <LockKeyhole class="size-5 text-emerald-400" />
                        Server-enforced access
                    </div>
                    <div class="flex items-center gap-3">
                        <Network class="size-5 text-emerald-400" /> Multi-branch
                        ready
                    </div>
                </div>
            </div>
            <p class="relative z-20 text-xs text-slate-500">
                Authorised Klinik Putrijaya staff only
            </p>
        </div>
        <div class="bg-slate-50 px-6 py-10 lg:p-8 dark:bg-background">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-7 sm:w-[390px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
