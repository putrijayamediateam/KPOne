<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
const props = defineProps<{
    revisions: {
        language: string;
        revision: number;
        title: string;
        wording: string;
        mergeFields: string[];
        rendererVersion: string;
        productionApproved: boolean;
    }[];
}>();
const language = ref('en');
const selected = computed(() =>
    props.revisions.find((revision) => revision.language === language.value),
);
</script>

<template>
    <Head title="MC template previews" />
    <main class="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-6">
        <Link href="/clinic-management" class="text-sm underline"
            >Clinic management</Link
        >
        <h1 class="text-2xl font-semibold">MC template previews</h1>
        <p class="rounded-lg border p-3 text-sm" role="status">
            Draft wording for review. Not production-approved. No patient data,
            numbering, issuance or printing is available here.
        </p>
        <label class="grid max-w-xs gap-1" for="mc-language"
            >Language<select
                id="mc-language"
                v-model="language"
                class="min-h-10 rounded border bg-background px-3"
            >
                <option value="en">English</option>
                <option value="bm">Bahasa Melayu</option>
            </select></label
        >
        <div v-if="selected" class="grid gap-5 lg:grid-cols-2">
            <section class="space-y-4 rounded-lg border bg-card p-4">
                <h2 class="font-semibold">
                    Fixed template · Revision {{ selected.revision }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    System-controlled layout and plain-text wording. No editor,
                    uploads or price to patient.
                </p>
                <p class="break-words whitespace-pre-wrap">
                    {{ selected.wording }}
                </p>
                <h3 class="font-medium">Allowlisted fields</h3>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="field in selected.mergeFields"
                        :key="field"
                        class="rounded border px-2 py-1 font-mono text-xs"
                    >
                        {{ field }}
                    </li>
                </ul>
                <p class="text-sm">
                    Diagnosis is not a merge field. No diagnosis is inserted
                    automatically.
                </p>
            </section>
            <section
                aria-label="MC layout preview"
                class="space-y-6 rounded-lg border bg-white p-5 text-black sm:p-8"
            >
                <p class="border-b pb-4 text-sm">
                    Issuing branch letterhead and optional logo<br />Preserved
                    when a certificate is issued
                </p>
                <h2 class="text-center text-lg font-semibold">
                    {{ selected.title }}
                </h2>
                <p class="text-right text-sm">Date / Tarikh: [issue_date]</p>
                <p class="text-sm leading-7 break-words">
                    {{ selected.wording }}
                </p>
                <div class="space-y-2 pt-6 text-sm">
                    <p>MC: [mc_number]</p>
                    <p>[doctor_name] · MMC: [mmc_number]</p>
                    <p class="pt-6">
                        Signature / Tandatangan: ____________________
                    </p>
                </div>
                <p class="border-t pt-4 text-xs">
                    Template layout preview only. Not an issued Medical
                    Certificate.
                </p>
            </section>
        </div>
    </main>
</template>
