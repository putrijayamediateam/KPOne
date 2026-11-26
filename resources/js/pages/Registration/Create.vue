<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, MapPin } from '@lucide/vue';
import RegistrationCreateForm from '@/components/registration/RegistrationCreateForm.vue';
import { Button } from '@/components/ui/button';
import type { PatientRegistrationSummary, VisitOptions } from '@/types';
import type { RegistrationRequirements } from '@/types/registration-settings';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Registration', href: '/registration' },
            { title: 'Register Visit', href: '/registration/create' },
        ],
    },
});
defineProps<{
    options: VisitOptions & { idempotencyKey: string };
    recentPatients: PatientRegistrationSummary[];
    requirements: RegistrationRequirements;
    guardianToday?: string;
}>();
</script>

<template>
    <Head title="Register Visit" />
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4 md:p-6"
    >
        <!-- Clean Look (spec section 6): page header; the one filled button is Register at the bottom. -->
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <h1
                    class="text-[26px] font-semibold tracking-[-0.02em] text-foreground lg:text-[30px]"
                >
                    Register Visit
                </h1>
                <p
                    class="inline-flex items-center gap-1.5 text-[13px] text-muted-foreground"
                >
                    <MapPin class="size-3.5" />
                    {{ options.branch.name }} · current branch
                </p>
            </div>
            <Button variant="outline" as-child
                ><Link href="/registration"
                    ><ArrowLeft class="size-4" /> Console</Link
                ></Button
            >
        </header>

        <RegistrationCreateForm
            :options="options"
            :recent-patients="recentPatients"
            :requirements="requirements"
            :guardian-today="guardianToday"
        />
    </main>
</template>
