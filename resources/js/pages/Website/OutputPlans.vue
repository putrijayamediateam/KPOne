<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import ReleaseStructuredValue from '@/components/website/ReleaseStructuredValue.vue';
import type { OutputPlanPage } from '@/types/website-output-plans';
const props = defineProps<{ outputPlans: OutputPlanPage }>();
defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Website output plans',
                href: '/website/releases/output-plans',
            },
        ],
    },
});
const page = ref<OutputPlanPage | null>(props.outputPlans);
const blocked = ref(false);
const detailContent = computed(() =>
    Object.fromEntries(
        Object.entries(page.value?.detail?.content ?? {}).filter(
            ([key]) => key !== 'aliases',
        ),
    ),
);
const values = () => ({
    expected_branch_id: page.value?.branchId ?? 0,
    release_id: page.value?.release?.id ?? 0,
    lock_version: page.value?.detail?.lockVersion ?? 0,
    plan_hash: page.value?.detail?.hash ?? null,
    idempotency_key: crypto.randomUUID(),
    confirmation: '',
    reason: '',
    content: {
        public_origin: '',
        aliases: Object.fromEntries(
            (page.value?.targets ?? []).map((t) => [t.key, '']),
        ) as Record<string, string>,
        pages: [] as string[],
        footer: { en: '', bm: '' },
        logo_public_id: null as string | null,
    },
});
const form = useForm(values());
watch(
    () => props.outputPlans,
    (value) => {
        page.value = value;
        blocked.value = false;
        form.defaults(values());
        form.reset();
        form.clearErrors();
    },
);
const prepare = () => {
    if (!blocked.value && page.value?.release) {
        form.post(`/website/releases/${page.value.release.id}/output-plans`, {
            preserveScroll: true,
        });
    }
};
const decide = (operation: 'approve' | 'refuse' | 'withdraw') => {
    if (!blocked.value && page.value?.canApprove && page.value.detail) {
        form.post(
            `/website/releases/output-plans/${page.value.detail.id}/${operation}`,
            { preserveScroll: true },
        );
    }
};
const remove = router.on('before', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        page.value = null;
        form.defaults(values());
        form.reset();
        form.clearErrors();
    }
});
onUnmounted(remove);
const inputClass = 'mt-1 block w-full min-w-0 rounded border bg-background p-2';
</script>
<template>
    <Head title="Website output plans"
        ><meta name="robots" content="noindex,nofollow"
    /></Head>
    <main class="mx-auto w-full max-w-7xl min-w-0 space-y-6 p-4 md:p-6">
        <Link href="/website/releases" class="underline">Website releases</Link>
        <h1 class="text-2xl font-semibold">Website output plans</h1>
        <p class="rounded-lg border bg-muted/30 p-4">
            Nothing is public. This does not build a website. A plan freezes
            site-level inputs only; no HTML output, artifact or deployment is
            created.
        </p>
        <div v-if="blocked" role="status">
            Branch changed. Loaded content and drafts were cleared.
            <Link href="/website/releases/output-plans" class="underline"
                >Reload plans</Link
            >
        </div>
        <section
            v-if="Object.keys(form.errors).length"
            role="alert"
            class="rounded border border-destructive p-4"
        >
            <h2 class="font-semibold">Plan preparation or action blocked</h2>
            <ul class="list-inside list-disc">
                <li v-for="(error, key) in form.errors" :key="key">
                    {{ key }}: {{ error }}
                </li>
            </ul>
        </section>
        <template v-if="page">
            <section aria-label="Saved output plans" class="space-y-3">
                <h2 class="font-semibold">Saved output plans</h2>
                <p v-if="!page.plans.length">
                    No output plan has been prepared.
                </p>
                <div class="grid min-w-0 gap-3 md:grid-cols-2">
                    <Link
                        v-for="plan in page.plans"
                        :key="plan.id"
                        :href="`/website/releases/output-plans/${plan.id}`"
                        class="min-w-0 rounded border p-4 wrap-anywhere"
                        ><strong>{{ plan.number }}</strong> · {{ plan.state }}
                        <p>{{ plan.releaseNumber }}</p>
                        <p>
                            Prepared by {{ plan.creator }} · Approver:
                            {{ plan.approver ?? 'Not approved' }}
                        </p></Link
                    >
                </div>
            </section>
            <section
                v-if="page.release"
                class="space-y-2 rounded border p-4 wrap-anywhere"
            >
                <h2 class="font-semibold">
                    Bound release · {{ page.release.number }} ·
                    {{ page.release.state }}
                </h2>
                <p class="font-mono text-xs break-all">
                    Manifest SHA-256: {{ page.release.hash }}
                </p>
                <p>
                    Readiness: About content is not included in this job. A logo
                    is optional; no logo remains a readiness note. Language
                    policy is fixed: English at root, Bahasa Melayu under /bm/,
                    HTML en/ms, paired hreflang and no cookie redirect.
                </p>
            </section>
            <form
                v-if="page.release && !page.detail"
                class="min-w-0 space-y-5"
                @submit.prevent="prepare"
            >
                <h2 class="text-lg font-semibold">Prepare output plan</h2>
                <label class="block"
                    >Public origin (HTTPS host only, max 100)<input
                        v-model="form.content.public_origin"
                        type="text"
                        maxlength="100"
                        :class="inputClass"
                        placeholder="https://clinic.test"
                /></label>
                <fieldset class="min-w-0 space-y-3 rounded border p-4">
                    <legend class="px-2 font-semibold">
                        Typed route aliases (3–60 characters)
                    </legend>
                    <p>
                        Every frozen branch, category and service needs an
                        alias. Lowercase letters, digits and single internal
                        hyphens only. Service detail keeps ?slug=.
                    </p>
                    <label
                        v-for="target in page.targets"
                        :key="target.key"
                        class="block wrap-anywhere"
                        >{{ target.kind }} · {{ target.name
                        }}<span class="block text-xs text-muted-foreground">{{
                            target.key
                        }}</span
                        ><input
                            v-model="form.content.aliases[target.key]"
                            type="text"
                            maxlength="60"
                            :class="inputClass"
                    /></label>
                </fieldset>
                <fieldset class="space-y-2 rounded border p-4">
                    <legend class="px-2 font-semibold">
                        Fixed pages (select at least one)
                    </legend>
                    <label
                        v-for="(labels, key) in page.labels"
                        :key="key"
                        class="flex items-center gap-2"
                        ><input
                            v-model="form.content.pages"
                            type="checkbox"
                            :value="key"
                            :disabled="!page.availablePages.includes(key)"
                        />{{ labels.en }} / {{ labels.bm
                        }}<span
                            v-if="!page.availablePages.includes(key)"
                            class="text-xs text-muted-foreground"
                            >No included content</span
                        ></label
                    >
                </fieldset>
                <label class="block"
                    >Footer line EN (optional, max 200)<input
                        v-model="form.content.footer.en"
                        maxlength="200"
                        :class="inputClass"
                /></label>
                <label class="block"
                    >Footer line BM (optional, max 200)<input
                        v-model="form.content.footer.bm"
                        maxlength="200"
                        :class="inputClass"
                /></label>
                <label class="block"
                    >Brand logo (existing sanitised media only)<select
                        v-model="form.content.logo_public_id"
                        :class="inputClass"
                    >
                        <option :value="null">No logo · readiness note</option>
                        <option
                            v-for="asset in page.assets"
                            :key="asset.public_id"
                            :value="asset.public_id"
                        >
                            {{ asset.alt_en }} / {{ asset.alt_bm }}
                        </option>
                    </select></label
                >
                <details class="rounded border p-4">
                    <summary>Fixed design profile · kp-soft-v1</summary>
                    <ReleaseStructuredValue :value="page.designProfile" />
                </details>
                <Button type="submit" :disabled="form.processing"
                    >Prepare frozen plan</Button
                >
            </form>
            <template v-if="page.detail">
                <section
                    class="min-w-0 space-y-3 rounded border p-4 wrap-anywhere"
                >
                    <h2 class="text-lg font-semibold">
                        {{ page.detail.number }} · {{ page.detail.state }}
                    </h2>
                    <p class="font-mono text-xs break-all">
                        Plan SHA-256: {{ page.detail.hash }}
                    </p>
                    <p>
                        Usable for a future build:
                        {{
                            page.usable
                                ? 'Yes · separately authorised build still required'
                                : 'No · plan and bound release must both be approved'
                        }}
                    </p>
                    <table class="w-full table-fixed text-left text-sm">
                        <caption class="mb-2 text-left font-semibold">
                            Route aliases (frozen English names)
                        </caption>
                        <thead>
                            <tr class="border-b">
                                <th scope="col" class="w-5/12 p-2">Name</th>
                                <th scope="col" class="w-1/4 p-2">Kind</th>
                                <th scope="col" class="p-2">Alias</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in page.detail.aliasRows"
                                :key="row.key"
                                class="border-b"
                            >
                                <td class="p-2 align-top wrap-anywhere">
                                    <span>{{ row.name }}</span>
                                    <small
                                        class="mt-1 block text-muted-foreground"
                                        >{{ row.key }}</small
                                    >
                                </td>
                                <td class="p-2 align-top wrap-anywhere">
                                    {{ row.kind }}
                                </td>
                                <td class="p-2 align-top wrap-anywhere">
                                    {{ row.alias }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <ReleaseStructuredValue :value="detailContent" />
                </section>
                <section
                    v-if="page.canApprove"
                    class="space-y-3 rounded border p-4"
                >
                    <h2 class="font-semibold">Director decision</h2>
                    <template
                        v-if="
                            page.detail.state === 'prepared' &&
                            page.release?.state === 'approved'
                        "
                        ><label class="block"
                            >Type {{ page.detail.number }} to approve<input
                                v-model="form.confirmation"
                                maxlength="30"
                                :class="inputClass" /></label
                        ><Button
                            :disabled="
                                form.processing ||
                                form.confirmation !== page.detail.number
                            "
                            @click="decide('approve')"
                            >Approve exact plan</Button
                        ></template
                    >
                    <template
                        v-if="
                            ['prepared', 'approved'].includes(page.detail.state)
                        "
                        ><label class="block"
                            >Reason for refusal or withdrawal (required, max
                            300)<textarea
                                v-model="form.reason"
                                maxlength="300"
                                :class="inputClass"
                            /></label
                        ><Button
                            v-if="page.detail.state === 'prepared'"
                            :disabled="form.processing || !form.reason.trim()"
                            @click="decide('refuse')"
                            >Refuse plan</Button
                        ><Button
                            v-else
                            :disabled="form.processing || !form.reason.trim()"
                            @click="decide('withdraw')"
                            >Withdraw plan</Button
                        ></template
                    >
                    <p v-else>
                        This plan is closed. Prepare a new plan to continue.
                    </p>
                </section>
                <section class="space-y-2 rounded border p-4">
                    <h2 class="font-semibold">Plan history</h2>
                    <p
                        v-for="(action, index) in page.detail.actions"
                        :key="index"
                    >
                        {{ action.action }} · {{ action.actor }} · {{ action.at
                        }}<span v-if="action.reason" class="block">{{
                            action.reason
                        }}</span>
                    </p>
                </section>
            </template>
        </template>
    </main>
</template>
