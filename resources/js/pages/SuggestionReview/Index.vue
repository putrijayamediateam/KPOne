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
            <template #description>
                Approve the diagnoses that doctors may pick from while they
                type. Only approved items are offered. You cannot approve an
                item that you entered or changed.
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
                    <p class="text-xs text-muted-foreground">
                        {{
                            draft.icd10Code
                                ? `ICD-10 ${draft.icd10Code}`
                                : 'No ICD-10 code'
                        }}
                        <span v-if="draft.ownEntry">
                            · You entered or changed this, so someone else must
                            review it.</span
                        >
                    </p>
                </div>
                <Button
                    size="sm"
                    class="max-sm:min-h-11"
                    :disabled="busy !== null || draft.ownEntry"
                    @click="approve(draft)"
                    >Approve</Button
                >
            </li>
        </ul>
    </div>
</template>
