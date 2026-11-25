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
const props = defineProps<{ branchId: number; drafts: Draft[] }>();
const page = usePage();
const busy = ref<number | null>(null);
const keys = new Map<string, string>();
const error = computed(
    () =>
        page.props.errors?.preset ||
        page.props.errors?.expected_branch_id ||
        page.props.errors?.idempotency_key,
);
const approve = (draft: Draft) => {
    if (busy.value !== null || draft.ownEntry) {
        return;
    }

    busy.value = draft.id;
    const token = `${draft.id}:${draft.lockVersion}`;
    const key = keys.get(token) ?? crypto.randomUUID();
    keys.set(token, key);
    router.patch(
        `/consultation-settings/review/diagnoses/${draft.id}`,
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
                    >{{ drafts.length }} waiting</span
                >
            </template>
            <template #description>
                Diagnoses waiting for your approval. Only approved diagnoses are
                offered to doctors while they type. You cannot approve a
                diagnosis that you entered or changed.
            </template>
        </PageHeader>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
        <EmptyState
            v-if="drafts.length === 0"
            title="Nothing is waiting for review."
        />
        <ul v-else class="space-y-2">
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
                    class="max-sm:min-h-11"
                    :aria-label="`Approve ${draft.name}`"
                    :disabled="busy !== null"
                    @click="approve(draft)"
                    >Approve</Button
                >
            </li>
        </ul>
    </div>
</template>
