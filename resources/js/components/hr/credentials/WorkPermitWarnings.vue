<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';

/**
 * HR-21: work permits that expire soon or have expired, as one warning line each ("Work permit expires on 3 Feb 2027
 * (in 60 days)"). Warning tone, never red. With `person` each line names the staff member and links to their file.
 */
defineProps<{
    items: {
        line: string;
        person?: string;
        workerId?: string;
        type?: string;
    }[];
    headingId: string;
}>();
</script>

<template>
    <section
        v-if="items.length"
        :aria-labelledby="headingId"
        class="flex flex-col gap-2 rounded-xl border border-warning/40 bg-warning/10 p-4"
        data-test="work-permit-warnings"
    >
        <h3
            :id="headingId"
            class="flex items-center gap-2 text-sm font-semibold"
        >
            <TriangleAlert
                class="size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            Work permits to renew
        </h3>
        <ul class="flex flex-col gap-1.5 text-sm">
            <li
                v-for="(p, i) in items"
                :key="i"
                class="break-words"
                data-test="work-permit-line"
            >
                <template v-if="p.person && p.workerId">
                    <Link
                        :href="`/hr/records/${p.workerId}`"
                        class="font-medium underline underline-offset-4"
                        >{{ p.person }}</Link
                    >
                    · {{ p.type }} ·
                </template>
                {{ p.line }}
            </li>
        </ul>
    </section>
</template>
