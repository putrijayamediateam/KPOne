<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState } from '@/components/ui/state';

type Draft = {
    id: number;
    name: string;
    icd10Code: string | null;
    lockVersion: number;
    ownEntry: boolean;
    enteredLabel: string;
};
type PhraseDraft = {
    id: number;
    kindLabel: string;
    labelEn: string;
    labelMs: string;
    lockVersion: number;
    ownEntry: boolean;
    enteredLabel: string;
};
const props = defineProps<{
    branchId: number;
    drafts: Draft[];
    phraseDrafts: PhraseDraft[];
    draftTotal: number;
    phraseDraftTotal: number;
}>();
const page = usePage();
const busy = ref<string | null>(null);
const keys = new Map<string, string>();
const waiting = computed(() => props.draftTotal + props.phraseDraftTotal);
const error = computed(
    () =>
        page.props.errors?.preset ||
        page.props.errors?.phrase ||
        page.props.errors?.expected_branch_id ||
        page.props.errors?.idempotency_key,
);
const approve = (
    draft: { id: number; lockVersion: number; ownEntry: boolean },
    path: string,
) => {
    if (busy.value !== null || draft.ownEntry) {
        return;
    }

    busy.value = `${path}:${draft.id}`;
    const token = `${path}:${draft.id}:${draft.lockVersion}`;
    const key = keys.get(token) ?? crypto.randomUUID();
    keys.set(token, key);
    router.patch(
        `/consultation-settings/review/${path}/${draft.id}`,
        {
            expected_branch_id: props.branchId,
            lock_version: draft.lockVersion,
            decision: 'approve',
            idempotency_key: key,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
};
</script>

<template>
    <Head title="Review suggestions" />
    <div class="mx-auto w-full max-w-3xl space-y-4 p-4">
        <PageHeader title="Review suggestions">
            <template #title-extra>
                <span class="text-sm text-muted-foreground"
                    >{{ waiting }} waiting</span
                >
            </template>
            <template #description>
                Diagnoses and phrases waiting for your approval. Only approved
                items are offered to doctors while they type. You cannot approve
                an item that you entered or changed.
            </template>
        </PageHeader>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
        <EmptyState
            v-if="waiting === 0"
            title="Nothing is waiting for review."
        />
        <section v-if="drafts.length > 0" aria-labelledby="review-diagnoses">
            <h2 id="review-diagnoses" class="mb-2 text-base font-semibold">
                Diagnoses
                <span class="text-sm font-normal text-muted-foreground"
                    >{{ draftTotal }} waiting</span
                >
            </h2>
            <ul class="space-y-2">
                <li
                    v-for="draft in drafts"
                    :key="draft.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-3"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium break-words">
                            {{ draft.name }}
                        </p>
                        <p
                            v-if="draft.icd10Code"
                            class="text-[13px] text-muted-foreground"
                        >
                            ICD-10 {{ draft.icd10Code }}
                        </p>
                        <p class="text-[13px] text-muted-foreground">
                            {{ draft.enteredLabel }}
                        </p>
                        <p
                            v-if="draft.ownEntry"
                            class="text-[13px] text-muted-foreground"
                        >
                            You entered this, so someone else approves it.
                        </p>
                    </div>
                    <Button
                        v-if="!draft.ownEntry"
                        variant="outline"
                        size="sm"
                        class="min-h-9 max-lg:min-h-11"
                        :aria-label="`Approve ${draft.name}`"
                        :disabled="busy !== null"
                        @click="approve(draft, 'diagnoses')"
                        >Approve</Button
                    >
                </li>
            </ul>
        </section>
        <section
            v-if="phraseDrafts.length > 0"
            aria-labelledby="review-phrases"
        >
            <h2 id="review-phrases" class="mb-2 text-base font-semibold">
                Phrases
                <span class="text-sm font-normal text-muted-foreground"
                    >{{ phraseDraftTotal }} waiting</span
                >
            </h2>
            <ul class="space-y-2">
                <li
                    v-for="phrase in phraseDrafts"
                    :key="phrase.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-3"
                >
                    <div class="min-w-0">
                        <p class="text-[13px] text-muted-foreground">
                            {{ phrase.kindLabel }}
                        </p>
                        <p class="text-sm font-medium break-words">
                            {{ phrase.labelEn }}
                        </p>
                        <p class="text-sm break-words">{{ phrase.labelMs }}</p>
                        <p class="text-[13px] text-muted-foreground">
                            {{ phrase.enteredLabel }}
                        </p>
                        <p
                            v-if="phrase.ownEntry"
                            class="text-[13px] text-muted-foreground"
                        >
                            You entered this, so someone else approves it.
                        </p>
                    </div>
                    <Button
                        v-if="!phrase.ownEntry"
                        variant="outline"
                        size="sm"
                        class="min-h-9 max-lg:min-h-11"
                        :aria-label="`Approve ${phrase.labelEn}`"
                        :disabled="busy !== null"
                        @click="approve(phrase, 'phrases')"
                        >Approve</Button
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
