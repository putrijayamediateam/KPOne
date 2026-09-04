<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

defineProps<{
    dispensary: {
        status: 'completed';
        patient: { name: string; patientNumber: string };
        visit: { visitNumber: string };
        completedAt: string | null;
        billingUrl?: string;
    };
}>();
</script>

<template>
    <Head title="Dispensary completed" />
    <main class="mx-auto w-full max-w-3xl px-4 py-6">
        <h1 class="text-lg font-medium">Dispensary completed</h1>
        <p class="mt-2 text-sm">{{ dispensary.patient.name }}</p>
        <p class="text-xs text-muted-foreground">
            {{ dispensary.patient.patientNumber }} ·
            {{ dispensary.visit.visitNumber }}
        </p>
        <p v-if="dispensary.completedAt" class="mt-2 text-sm">
            Completed {{ dispensary.completedAt }}
        </p>
        <p class="my-3 text-sm text-muted-foreground">
            This case is read-only. Fulfilment has finished and it cannot be
            returned to the doctor.
        </p>
        <Button as-child variant="outline" size="sm">
            <Link href="/registration">Return to Registration</Link>
        </Button>
        <Button v-if="dispensary.billingUrl" as-child size="sm" class="ml-2"
            ><Link :href="dispensary.billingUrl"
                >Continue to Billing</Link
            ></Button
        >
    </main>
</template>
