<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    branchId: number;
    branchName: string;
    viewLabel: string;
    isQr: boolean;
    qrAvailable: boolean;
}>();
const page = usePage();
const permissions = computed(() => page.props.auth?.permissions ?? []);
const canSearchPatients = computed(() =>
    permissions.value.includes('patients.search.organisation'),
);
const canSeeMergeRequests = computed(() =>
    permissions.value.some((permission) =>
        [
            'patients.merge.propose.organisation',
            'patients.merge.approve.organisation',
        ].includes(permission),
    ),
);
const canReviewQr = computed(
    () =>
        props.qrAvailable &&
        page.props.branchContext?.active?.id === props.branchId &&
        permissions.value.includes('visits.view.branch') &&
        permissions.value.includes('public_intakes.review.branch'),
);
const hasRelatedPages = computed(
    () =>
        canSearchPatients.value ||
        canSeeMergeRequests.value ||
        (!props.isQr && canReviewQr.value),
);
</script>

<template>
    <aside
        id="registration-helpful-info"
        aria-label="Helpful registration information"
        class="min-w-0 space-y-3"
    >
        <details open class="rounded-lg border bg-card shadow-card">
            <summary
                class="min-h-11 cursor-pointer px-4 py-2 text-sm font-semibold lg:min-h-9"
            >
                About this view
            </summary>
            <div
                class="space-y-2 border-t px-4 py-3 text-sm text-muted-foreground"
            >
                <p class="font-medium text-foreground">
                    {{ branchName }} · {{ viewLabel }}
                </p>
                <p v-if="isQr">
                    Review submitted check-ins before creating a visit.
                    Patient-reported Panel details still need staff
                    confirmation.
                </p>
                <p v-else>
                    Find visits in the selected branch. Apply your filters, then
                    choose an available action for each visit.
                </p>
            </div>
        </details>
        <details
            v-if="hasRelatedPages"
            class="rounded-lg border bg-card shadow-card"
        >
            <summary
                class="min-h-11 cursor-pointer px-4 py-2 text-sm font-semibold lg:min-h-9"
            >
                Related pages
            </summary>
            <nav
                aria-label="Related registration pages"
                class="grid gap-1 border-t p-2"
            >
                <Link
                    v-if="canSearchPatients"
                    href="/patients"
                    class="flex min-h-11 items-center rounded-md px-2 text-sm text-primary hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring lg:min-h-9"
                    >Patient directory</Link
                >
                <Link
                    v-if="!isQr && canReviewQr"
                    href="/registration?tab=qr-intake"
                    class="flex min-h-11 items-center rounded-md px-2 text-sm text-primary hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring lg:min-h-9"
                    >QR check-ins</Link
                >
                <Link
                    v-if="canSeeMergeRequests"
                    href="/patient-merges"
                    class="flex min-h-11 items-center rounded-md px-2 text-sm text-primary hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring lg:min-h-9"
                    >Merge requests</Link
                >
            </nav>
        </details>
        <details class="rounded-lg border bg-card shadow-card">
            <summary
                class="min-h-11 cursor-pointer px-4 py-2 text-sm font-semibold lg:min-h-9"
            >
                Good to know
            </summary>
            <div
                class="space-y-2 border-t px-4 py-3 text-sm text-muted-foreground"
            >
                <p v-if="isQr">
                    A submitted check-in is not a registered visit until it is
                    accepted.
                </p>
                <p v-else>
                    Search for the patient before creating a new record.
                </p>
                <p>
                    Patient records are shared across the organisation. Visits
                    and queue numbers belong to the selected branch.
                </p>
            </div>
        </details>
    </aside>
</template>
