<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 MC template previews (Classic is `pages/MedicalCertificateTemplates/Index.vue`, untouched). Same props and the
 * same language choice; read only: no patient data, numbering, issuing or printing happens here.
 */
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
usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'MC template previews' },
]);
</script>

<template>
    <Head title="MC template previews" />
    <PageFrame>
        <V2WorkLayout title="MC template previews">
            <template #main>
                <div
                    class="flex items-start gap-3 rounded-[14px] border border-warning/30 bg-warning/10 p-4 text-sm"
                    role="status"
                    data-testid="draft-notice"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    <p>
                        Draft wording for review. Not production-approved. No
                        patient data, numbering, issuance or printing is
                        available here.
                    </p>
                </div>
                <V2OptionGroup
                    v-model="language"
                    label="Language"
                    :options="[
                        { value: 'en', label: 'English' },
                        { value: 'bm', label: 'Bahasa Melayu' },
                    ]"
                />
                <div v-if="selected" class="grid min-w-0 gap-4 xl:grid-cols-2">
                    <V2FormCard
                        :title="`Fixed template · Revision ${selected.revision}`"
                        description="System-controlled layout and plain-text wording. No editor, uploads or price to patient."
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <V2Chip
                                :tone="
                                    selected.productionApproved
                                        ? 'success'
                                        : 'warning'
                                "
                                :label="
                                    selected.productionApproved
                                        ? 'Production-approved'
                                        : 'Draft'
                                "
                            />
                        </div>
                        <p class="break-words whitespace-pre-wrap">
                            {{ selected.wording }}
                        </p>
                        <h3 class="font-medium">Allowlisted fields</h3>
                        <ul class="flex flex-wrap gap-2">
                            <li
                                v-for="field in selected.mergeFields"
                                :key="field"
                                class="rounded-[8px] border bg-muted/40 px-2 py-1 font-mono text-xs break-all"
                            >
                                {{ field }}
                            </li>
                        </ul>
                        <p class="text-sm">
                            Diagnosis is not a merge field. No diagnosis is
                            inserted automatically.
                        </p>
                    </V2FormCard>
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
                        <p class="text-right text-sm">
                            Date / Tarikh: [issue_date]
                        </p>
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
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
