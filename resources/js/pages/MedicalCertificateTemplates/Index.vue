<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import { PageHeader } from '@/components/ui/page-header';
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
    <PageFrame>
        <PageHeader title="MC template previews" />
        <p
            class="rounded-[12px] bg-warning-wash p-3 text-sm text-warning"
            role="status"
        >
            Draft wording for review. Not production-approved. No patient data,
            numbering, issuance or printing is available here.
        </p>
        <label class="grid max-w-xs gap-1" for="mc-language"
            >Language<select
                id="mc-language"
                v-model="language"
                class="min-h-11 rounded-[10px] border border-input bg-field-fill px-3 lg:min-h-9"
            >
                <option value="en">English</option>
                <option value="bm">Bahasa Melayu</option>
            </select></label
        >
        <div v-if="selected" class="grid gap-5 lg:grid-cols-2">
            <section
                class="min-w-0 space-y-4 rounded-[14px] border bg-card p-4 md:p-5"
            >
                <h2 class="font-semibold">
                    Fixed template · Revision {{ selected.revision }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    System-controlled layout and plain-text wording. No editor,
                    uploads or price to patient.
                </p>
                <p class="[overflow-wrap:anywhere] whitespace-pre-wrap">
                    {{ selected.wording }}
                </p>
                <h3 class="font-medium">Allowlisted fields</h3>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="field in selected.mergeFields"
                        :key="field"
                        class="rounded-lg bg-muted px-2 py-1 font-mono text-xs"
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
                class="min-w-0 space-y-6 rounded-[14px] border bg-white p-5 text-black sm:p-8"
            >
                <p class="border-b pb-4 text-sm">
                    Issuing branch letterhead and optional logo<br />Preserved
                    when a certificate is issued
                </p>
                <h2 class="text-center text-lg font-semibold">
                    {{ selected.title }}
                </h2>
                <p class="text-right text-sm">Date / Tarikh: [issue_date]</p>
                <p class="text-sm leading-7 [overflow-wrap:anywhere]">
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
    </PageFrame>
</template>
