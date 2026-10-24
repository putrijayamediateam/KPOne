<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { WebsiteBuildPage } from '@/types/website-builds';
const props = defineProps<{ builds: WebsiteBuildPage }>();
const selected = ref('');
const blocked = ref(false);
const plan = computed(() =>
    props.builds.plans.find((p) => p.id === Number(selected.value)),
);
const form = useForm({
    expected_branch_id: props.builds.branchId,
    plan_id: 0,
    release_version: props.builds.releaseVersion,
    plan_version: 0,
    release_hash: props.builds.releaseHash,
    plan_hash: '',
    idempotency_key: crypto.randomUUID(),
});
watch(
    () => props.builds,
    () => {
        selected.value = '';
        form.reset();
        form.clearErrors();
        blocked.value = false;
    },
);
const remove = router.on('before', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        selected.value = '';
        form.reset();
        form.clearErrors();
    }
});
onUnmounted(remove);
const build = () => {
    if (blocked.value || !props.builds.available || !plan.value) {
        return;
    }

    form.expected_branch_id = props.builds.branchId;
    form.release_version = props.builds.releaseVersion;
    form.release_hash = props.builds.releaseHash;
    form.plan_id = plan.value.id;
    form.plan_version = plan.value.version;
    form.plan_hash = plan.value.hash;
    form.post(`/website/releases/${props.builds.releaseId}/builds`, {
        preserveScroll: true,
        onSuccess: () => {
            form.idempotency_key = crypto.randomUUID();
        },
    });
};
</script>
<template>
    <section
        v-if="!blocked"
        class="min-w-0 space-y-4 rounded-lg border p-4 wrap-anywhere"
        aria-label="Website builds"
    >
        <h2 class="text-lg font-semibold">Website builds</h2>
        <p class="rounded border bg-muted/30 p-3">
            Review build only. Nothing is public. Preview and approval come
            later.
        </p>
        <p class="text-sm">
            Release {{ builds.releaseNumber }} · SHA-256
            <code class="break-all">{{ builds.releaseHash }}</code>
        </p>
        <p v-if="!builds.available" role="status">
            Build disabled: the release is not currently approved.
        </p>
        <p v-else-if="!builds.plans.length" role="status">
            Build disabled: approve an output plan for this release first.
        </p>
        <form class="space-y-3" @submit.prevent="build">
            <label class="block text-sm"
                >Approved output plan<select
                    v-model="selected"
                    :disabled="!builds.available || form.processing"
                    class="mt-1 block w-full min-w-0 rounded border bg-background p-2"
                >
                    <option value="">Choose an approved plan</option>
                    <option
                        v-for="p in builds.plans"
                        :key="p.id"
                        :value="String(p.id)"
                    >
                        {{ p.number }}
                    </option>
                </select></label
            >
            <p v-if="plan" class="text-xs break-all">
                Plan SHA-256: {{ plan.hash }}
            </p>
            <div
                v-if="Object.keys(form.errors).length"
                role="alert"
                class="rounded border border-destructive p-3"
            >
                <p v-for="(error, key) in form.errors" :key="key">
                    {{ error }}
                </p>
            </div>
            <Button
                type="submit"
                :disabled="!builds.available || !plan || form.processing"
                >{{ form.processing ? 'Building…' : 'Build website' }}</Button
            >
        </form>
        <p v-if="!builds.attempts.length">No build requested.</p>
        <article
            v-for="attempt in builds.attempts"
            :key="attempt.id"
            class="min-w-0 space-y-2 rounded border p-3"
        >
            <h3 class="font-semibold">
                {{ attempt.number ?? 'Build attempt' }} · {{ attempt.state }}
            </h3>
            <p>Plan {{ attempt.plan }}</p>
            <p v-if="attempt.failureCode" role="status">
                Build failed: {{ attempt.failureCode }}. No artifact is
                available.
            </p>
            <p v-if="attempt.hash">
                {{ attempt.count }} files · {{ attempt.bytes }} bytes ·
                <code class="block text-xs break-all">{{ attempt.hash }}</code>
            </p>
            <details v-if="attempt.files.length">
                <summary>File manifest (not for submission)</summary>
                <ul class="mt-2 space-y-2 text-xs">
                    <li v-for="file in attempt.files" :key="file.path">
                        <code class="break-all">{{ file.path }}</code> ·
                        {{ file.bytes }} bytes · SHA-256
                        {{ file.sha256.slice(0, 12) }}
                    </li>
                </ul>
            </details>
        </article>
    </section>
</template>
