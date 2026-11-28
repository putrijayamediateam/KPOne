<script setup lang="ts">
// FAM-1 part 1: relatives of this patient waiting today for a consultation at this branch, each with a link to their
// Patient details. Read only. Never shows a relative's conditions, allergies, diagnoses, medicines, triage, contact or
// payment details, and never counts relatives the viewer may not see.
import { Users } from '@lucide/vue';
import type { FamilyWaiting } from '@/types/clinical';

defineProps<{ family: FamilyWaiting }>();
</script>

<template>
    <section
        aria-labelledby="family-waiting-heading"
        class="rounded-lg border bg-card px-4 py-3 shadow-card"
        data-test="family-waiting-card"
    >
        <div class="flex items-center justify-between gap-3">
            <h2
                id="family-waiting-heading"
                class="inline-flex items-center gap-1.5 text-sm font-semibold"
            >
                <Users class="size-4 text-muted-foreground" /> Family
            </h2>
            <a
                :href="family.recordUrl"
                class="text-xs text-muted-foreground underline-offset-2 hover:underline"
                >Patient record</a
            >
        </div>
        <p
            v-if="family.rows.length === 0"
            class="mt-2 text-xs text-muted-foreground"
        >
            No linked relatives waiting in your queue today.
        </p>
        <ul v-else class="mt-2 divide-y divide-border/60 text-sm">
            <li
                v-for="row in family.rows"
                :key="row.patientNumber"
                class="flex items-center justify-between gap-3 py-2"
            >
                <span class="min-w-0">
                    <a
                        :href="row.url"
                        class="font-medium underline-offset-2 hover:underline"
                        >{{ row.name }}</a
                    >
                    <span class="block font-mono text-xs text-muted-foreground"
                        >{{ row.patientNumber }} · {{ row.roleLabel }}</span
                    >
                </span>
                <span class="shrink-0 text-xs text-muted-foreground"
                    >Waiting · {{ row.queueLabel }}</span
                >
            </li>
        </ul>
    </section>
</template>
