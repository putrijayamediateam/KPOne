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
        class="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1.05fr)_minmax(460px,0.95fr)] lg:gap-5 lg:p-5"
    >
        <!-- Decorative brand panel (Clean Look sign-in): the one gradient element; text uses --primary-foreground (5.07:1 on --primary). -->
        <div
            class="relative hidden min-h-[calc(100svh-2.5rem)] overflow-hidden rounded-[32px] bg-linear-to-br from-primary to-primary/80 p-10 text-primary-foreground lg:flex lg:flex-col xl:p-14 dark:border dark:bg-card dark:bg-none dark:text-foreground"
        >
            <div
                class="pointer-events-none absolute -top-32 -right-28 size-96 rounded-full bg-primary-foreground/10"
                aria-hidden="true"
            />
            <Link
                href="/"
                class="relative z-20 inline-flex w-fit items-center gap-3 rounded-lg text-lg font-semibold tracking-tight focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:outline-none"
            >
                <AppLogoIcon class="size-11 text-brand" />
                {{ name }}
            </Link>
            <div class="relative z-20 my-auto max-w-lg py-16">
                <p class="text-[13px] font-medium opacity-90">
                    Klinik Putrijaya workspace
                </p>
                <h2
                    class="mt-4 text-4xl leading-[1.1] font-semibold tracking-[-0.03em] xl:text-5xl"
                >
                    Clear, connected clinic operations.
                </h2>
                <p class="mt-6 max-w-md text-base leading-7 opacity-90">
                    A focused workspace for authorised teams to coordinate care
                    and daily clinic work.
                </p>
                <div
                    class="mt-10 flex flex-wrap gap-x-8 gap-y-4 text-sm opacity-95"
                >
                    <div class="flex items-center gap-3">
                        <ShieldCheck class="size-4" />
                        Permission-aware access
                    </div>
                    <div class="flex items-center gap-3">
                        <Building2 class="size-4" />
                        Branch-aware operations
                    </div>
                </div>
            </div>
            <p class="relative z-20 text-[12.5px] opacity-90">
                Authorised Klinik Putrijaya staff only
            </p>
        </div>

        <main
            class="flex min-h-svh items-center justify-center px-4 py-8 sm:px-8 lg:min-h-0 lg:px-12"
        >
            <div
                class="w-full max-w-md rounded-[14px] border bg-card p-6 shadow-card sm:p-8"
            >
                <Link
                    href="/"
                    class="mb-8 inline-flex min-h-11 items-center gap-3 rounded-lg font-semibold tracking-tight text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:outline-none lg:hidden"
                >
                    <AppLogoIcon class="size-10 text-brand" />
                    {{ name }}
                </Link>
                <div class="space-y-1">
                    <h1
                        v-if="title"
                        class="text-[26px] font-semibold tracking-[-0.02em] text-foreground"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-sm leading-6 text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
                <div class="mt-8 space-y-6"><slot /></div>
            </div>
        </main>
    </div>
</template>
