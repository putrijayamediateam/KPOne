<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import ReleaseStructuredValue from '@/components/website/ReleaseStructuredValue.vue';
import WebsiteBuilds from '@/components/website/WebsiteBuilds.vue';
import type { WebsiteBuildPage } from '@/types/website-builds';
import type { ReleasePage } from '@/types/website-releases';
const props = defineProps<{
    releases: ReleasePage;
    builds?: WebsiteBuildPage | null;
    outputPlans?: {
        id: number;
        number: string;
        release_number: string;
        state: string;
    }[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Website releases', href: '/website/releases' }],
    },
});
const page = ref<ReleasePage | null>(props.releases);
const blocked = ref(false);
const data = () => ({
    expected_branch_id: page.value?.branchId ?? 0,
    lock_version: page.value?.detail?.lockVersion ?? 0,
    idempotency_key: crypto.randomUUID(),
    manifest_hash: page.value?.detail?.hash ?? null,
    confirmation: '',
    reason: '',
});
const form = useForm(data());
watch(
    () => props.releases,
    (value) => {
        page.value = value;
        blocked.value = false;
        form.defaults(data());
        form.reset();
    },
);
const prepare = () => {
    if (!blocked.value) {
        form.transform((value) => ({
            ...value,
            lock_version: 0,
            manifest_hash: null,
        })).post('/website/releases', { preserveScroll: true });
    }
};
const decide = (operation: 'approve' | 'refuse' | 'withdraw') => {
    if (!blocked.value && page.value?.canApprove && page.value.detail) {
        form.transform((value) => value).post(
            `/website/releases/${page.value.detail.id}/${operation}`,
            { preserveScroll: true },
        );
    }
};
const remove = router.on('before', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        page.value = null;
        form.defaults({
            expected_branch_id: 0,
            lock_version: 0,
            idempotency_key: crypto.randomUUID(),
            manifest_hash: null,
            confirmation: '',
            reason: '',
        });
        form.reset();
        form.clearErrors();
    }
});
onUnmounted(remove);
</script>
<template>
    <Head title="Website releases"
        ><meta name="robots" content="noindex,nofollow"
    /></Head>
    <main class="mx-auto w-full max-w-7xl min-w-0 space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">Website releases</h1>
            <Button
                v-if="page"
                :disabled="blocked || form.processing"
                @click="prepare"
                >Prepare release</Button
            >
        </div>
        <div class="rounded-lg border bg-muted/30 p-4 text-sm">
            Private evidence and structured preview only. Nothing is public.
            Approval covers these exact frozen contents, not future edits. KPOne
            does not verify medical wording, consent, rights or display-approval
            references.
        </div>
        <div v-if="blocked" class="rounded-lg border p-4">
            Branch changed. Loaded content and confirmations were cleared.
            <Link href="/website/releases" class="underline"
                >Reload releases</Link
            >
        </div>
        <section
            v-if="Object.keys(form.errors).length"
            aria-live="polite"
            class="rounded-lg border border-destructive p-4"
        >
            <h2 class="font-semibold">Preparation or action blocked</h2>
            <ul class="list-inside list-disc">
                <li
                    v-for="(message, key) in form.errors"
                    :key="key"
                    class="break-words"
                >
                    {{ key }}: {{ message }}
                </li>
            </ul>
        </section>
        <template v-if="page">
            <section class="space-y-3">
                <h2 class="font-semibold">Saved releases</h2>
                <Link href="/website/releases/output-plans" class="underline"
                    >Website output plans</Link
                >
                <ul>
                    <li v-for="plan in props.outputPlans ?? []" :key="plan.id">
                        <Link
                            :href="`/website/releases/output-plans/${plan.id}`"
                            class="underline"
                            >{{ plan.number }} · {{ plan.state }} ·
                            {{ plan.release_number }}</Link
                        >
                    </li>
                </ul>
                <p v-if="!page.releases.length" class="text-muted-foreground">
                    No release has been prepared. Only saved, complete EN and BM
                    content can be frozen.
                </p>
                <div class="grid min-w-0 gap-3 md:grid-cols-2">
                    <Link
                        v-for="release in page.releases"
                        :key="release.id"
                        :href="`/website/releases/${release.id}`"
                        class="min-w-0 rounded-lg border p-4"
                        ><strong>{{ release.number }}</strong> ·
                        {{ release.state }}
                        <p class="text-sm">
                            Prepared by {{ release.creator }} ·
                            {{ release.createdAt }}
                        </p>
                        <p class="text-sm">
                            Approver: {{ release.approver ?? 'Not approved' }}
                            {{ release.approvedAt ?? '' }}
                        </p></Link
                    >
                </div>
            </section>
            <template v-if="page.detail">
                <Link
                    v-if="page.detail.state === 'approved'"
                    :href="`/website/releases/${page.detail.id}/output-plans`"
                    class="inline-block underline"
                    >Prepare output plan</Link
                >
                <section class="min-w-0 space-y-2 rounded-lg border p-4">
                    <h2 class="text-xl font-semibold">
                        {{ page.detail.number }} · {{ page.detail.state }}
                    </h2>
                    <p class="font-mono text-xs break-all">
                        Manifest SHA-256: {{ page.detail.hash }}
                    </p>
                    <p
                        v-if="
                            page.canApprove &&
                            page.detail.samePreparerApprover &&
                            (page.detail.state === 'prepared' ||
                                page.detail.actions.some(
                                    (action) => action.action === 'approved',
                                ))
                        "
                        class="text-sm"
                    >
                        {{
                            page.detail.state === 'prepared'
                                ? 'You prepared this release. If you approve it, the preparer and approver are the same person.'
                                : 'The same person prepared and approved this release.'
                        }}
                    </p>
                    <p class="text-sm">
                        Times use the effective branch timezone. Stored evidence
                        uses UTC.
                    </p>
                </section>
                <section
                    v-if="page.canApprove"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <h2 class="font-semibold">Director decision</h2>
                    <template v-if="page.detail.state === 'prepared'"
                        ><label class="block text-sm"
                            >Type {{ page.detail.number }} to approve<input
                                v-model="form.confirmation"
                                maxlength="30"
                                class="mt-1 block w-full rounded border bg-background p-2" /></label
                        ><Button
                            :disabled="
                                form.processing ||
                                form.confirmation !== page.detail.number
                            "
                            @click="decide('approve')"
                            >Approve exact contents</Button
                        ></template
                    ><template
                        v-if="
                            ['prepared', 'approved'].includes(page.detail.state)
                        "
                        ><label class="block text-sm"
                            >Reason for refusal or withdrawal (required, maximum
                            300 characters)<textarea
                                v-model="form.reason"
                                maxlength="300"
                                class="mt-1 block w-full rounded border bg-background p-2"
                            /></label
                        ><Button
                            variant="outline"
                            :disabled="form.processing || !form.reason.trim()"
                            @click="
                                decide(
                                    page!.detail!.state === 'prepared'
                                        ? 'refuse'
                                        : 'withdraw',
                                )
                            "
                            >{{
                                page.detail.state === 'prepared'
                                    ? 'Refuse release'
                                    : 'Withdraw approval'
                            }}</Button
                        ></template
                    >
                    <p v-else class="text-sm">
                        This release is closed. Prepare a new release to
                        continue.
                    </p>
                </section>
                <WebsiteBuilds v-if="props.builds" :builds="props.builds" />
                <section class="space-y-3 rounded-lg border p-4">
                    <h2 class="font-semibold">
                        Change list · {{ page.detail.comparedTo }}
                    </h2>
                    <div
                        v-for="(entries, category) in page.detail.changes"
                        :key="category"
                        class="text-sm break-words"
                    >
                        <strong>{{ category }}:</strong>
                        {{ entries.length ? entries.join(', ') : 'None' }}
                    </div>
                </section>
                <section class="space-y-3">
                    <h2 class="font-semibold">
                        Frozen structured content · EN and BM
                    </h2>
                    <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                        <article
                            v-for="item in page.detail.items"
                            :key="`${item.key}-${item.language}`"
                            class="min-w-0 space-y-3 rounded-lg border p-4 wrap-anywhere"
                        >
                            <h3 class="font-semibold">
                                {{ item.type.replaceAll('_', ' ') }} ·
                                {{ item.key }} ·
                                {{ item.language.toUpperCase() }}
                            </h3>
                            <ReleaseStructuredValue :value="item.projection" />
                        </article>
                    </div>
                </section>
                <section class="space-y-3">
                    <h2 class="font-semibold">Retained media</h2>
                    <p
                        v-if="!page.detail.media.length"
                        class="text-sm text-muted-foreground"
                    >
                        No included media.
                    </p>
                    <div
                        class="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <figure
                            v-for="asset in page.detail.media"
                            :key="asset.filename"
                            class="min-w-0 rounded-lg border p-3"
                        >
                            <img
                                :src="`/website/releases/${page.detail.id}/media/${asset.filename}`"
                                :alt="asset.alt_en"
                                class="max-h-64 w-full object-contain"
                            />
                            <figcaption class="mt-2 text-sm break-words">
                                {{ asset.alt_en }} / {{ asset.alt_bm }} ·
                                {{ asset.width }} × {{ asset.height }}
                            </figcaption>
                        </figure>
                    </div>
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="font-semibold">Omissions</h2>
                    <p v-if="!page.detail.omissions.length" class="text-sm">
                        None
                    </p>
                    <ul>
                        <li
                            v-for="item in page.detail.omissions"
                            :key="item.key"
                            class="text-sm break-words"
                        >
                            {{ item.key }} · {{ item.type }} · {{ item.reason }}
                        </li>
                    </ul>
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="font-semibold">Decision history</h2>
                    <ul class="space-y-2">
                        <li
                            v-for="(action, index) in page.detail.actions"
                            :key="index"
                            class="text-sm break-words"
                        >
                            {{ action.action }} · {{ action.actor }} ·
                            {{ action.at }}
                            <p v-if="action.reason">{{ action.reason }}</p>
                        </li>
                    </ul>
                </section>
            </template>
        </template>
    </main>
</template>
