<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { FamilyWaiting } from '@/types/clinical';

defineProps<{ family: FamilyWaiting }>();
</script>

<template>
    <section
        aria-labelledby="family-waiting-title"
        class="rounded-xl border bg-card p-4 text-sm shadow-sm"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="family-waiting-title" class="font-semibold">
                Family waiting today
            </h2>
            <Link
                v-if="family.recordUrl"
                :href="family.recordUrl"
                class="inline-flex min-h-11 items-center text-primary underline-offset-4 hover:underline lg:min-h-9"
                >Family record</Link
            >
        </div>
        <p v-if="!family.rows.length" class="mt-2 text-muted-foreground">
            No linked relatives waiting in your permitted queue today.
        </p>
        <template v-else>
            <p class="mt-1 text-xs text-muted-foreground">
                Showing up to 5 relatives waiting in your permitted queue.
            </p>
            <ul class="mt-3 divide-y">
                <li
                    v-for="row in family.rows"
                    :key="row.patientNumber"
                    class="flex flex-wrap items-center justify-between gap-2 py-3"
                >
                    <div class="min-w-0 break-words">
                        <p class="font-medium">{{ row.name }}</p>
                        <p class="text-muted-foreground">
                            {{ row.relationshipLabel }} this patient ·
                            <span class="font-mono">{{
                                row.patientNumber
                            }}</span>
                        </p>
                        <p class="text-muted-foreground">
                            {{ row.queueLabel }}
                        </p>
                    </div>
                    <Link
                        :href="row.url"
                        class="inline-flex min-h-11 items-center text-primary underline-offset-4 hover:underline lg:min-h-9"
                        :aria-label="`Patient record for ${row.name}`"
                        >Patient record</Link
                    >
                </li>
            </ul>
        </template>
    </section>
</template>
