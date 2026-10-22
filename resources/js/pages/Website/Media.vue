<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { Asset } from '@/types/website';
const props = defineProps<{
    library: {
        branchId: number;
        limits: { bytes: number; dimension: number; pixels: number };
        assets: Asset[];
        page: number;
        pages: number;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Website media', href: '/website/media' }],
    },
});
const blocked = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const values = () => ({
    expected_branch_id: props.library.branchId,
    lock_version: 0,
    idempotency_key: crypto.randomUUID(),
    image: null as File | null,
    alt_en: '',
    alt_bm: '',
    rights_reference: '',
    identifiable_person: false,
    consent_reference: '',
});
const form = useForm(values());
const retirement = useForm({
    expected_branch_id: props.library.branchId,
    lock_version: 1,
    idempotency_key: crypto.randomUUID(),
});
const errors = computed(() => [
    ...Object.values(form.errors),
    ...Object.values(retirement.errors),
]);
const clear = () => {
    form.defaults(values());
    form.reset();
    form.clearErrors();
    retirement.reset();
    retirement.clearErrors();

    if (fileInput.value) {
        fileInput.value.value = '';
    }
};
const upload = () => {
    if (!blocked.value) {
        form.post('/website/media', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: async () => {
                await nextTick();
                clear();
            },
        });
    }
};
const retire = (asset: Asset) => {
    if (blocked.value || asset.retired) {
        return;
    }

    retirement.expected_branch_id = props.library.branchId;
    retirement.lock_version = asset.lockVersion;
    retirement.idempotency_key = crypto.randomUUID();
    retirement.post(`/website/media/${asset.publicId}/retire`, {
        preserveScroll: true,
    });
};
const stop = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        clear();
    }
});
watch(
    () => props.library.branchId,
    () => {
        clear();
        blocked.value = false;
    },
);
onUnmounted(stop);
const inputClass =
    'mt-1 w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm';
</script>
<template>
    <Head title="Website media library" />
    <main class="mx-auto w-full max-w-7xl space-y-5 p-4 sm:p-6">
        <Link href="/clinic-management" class="text-sm underline"
            >Clinic management</Link
        >
        <header>
            <h1 class="text-2xl font-semibold">Media library</h1>
            <p class="mt-1 text-muted-foreground">
                Private website media only. Nothing is public yet.
            </p>
        </header>
        <p v-if="blocked" role="status">
            Branch changed. Loaded assets and upload drafts cleared. Reload to
            continue.
        </p>
        <template v-else>
            <div
                v-if="errors.length"
                role="alert"
                class="rounded border border-destructive p-3 text-destructive"
            >
                <p v-for="error in errors" :key="error">{{ error }}</p>
            </div>
            <form
                class="grid min-w-0 gap-4 rounded-xl border p-5 md:grid-cols-2"
                @submit.prevent="upload"
            >
                <h2 class="font-semibold md:col-span-2">
                    Upload a sanitised image
                </h2>
                <p class="text-sm text-muted-foreground md:col-span-2">
                    JPG, PNG or still WebP only. Maximum 5 MB,
                    {{ library.limits.dimension }} pixels per side and 12
                    million pixels. The image is decoded and re-encoded without
                    metadata; the original is not kept.
                </p>
                <label class="min-w-0 text-sm md:col-span-2"
                    >Image<input
                        ref="fileInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        :class="inputClass"
                        @change="
                            form.image =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                /></label>
                <label class="min-w-0 text-sm"
                    >Alt text · EN (required, max 200)<input
                        v-model="form.alt_en"
                        maxlength="200"
                        required
                        :class="inputClass"
                /></label>
                <label class="min-w-0 text-sm"
                    >Alt text · BM (required, max 200)<input
                        v-model="form.alt_bm"
                        maxlength="200"
                        required
                        :class="inputClass"
                /></label>
                <label class="min-w-0 text-sm md:col-span-2"
                    >Rights reference (required, max 200)<input
                        v-model="form.rights_reference"
                        maxlength="200"
                        required
                        :class="inputClass"
                /></label>
                <label class="flex items-center gap-2 text-sm md:col-span-2"
                    ><input
                        v-model="form.identifiable_person"
                        type="checkbox"
                    />This image includes an identifiable person</label
                >
                <label class="min-w-0 text-sm md:col-span-2"
                    >Consent reference (max 200; required for an identifiable
                    person)<input
                        v-model="form.consent_reference"
                        maxlength="200"
                        :required="form.identifiable_person"
                        :class="inputClass"
                /></label>
                <div>
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.image"
                        >Upload private image</Button
                    >
                </div>
            </form>
            <p class="text-sm text-muted-foreground">
                Retiring an asset prevents new attachments. Existing draft
                revisions keep their image. There is no hard-delete action.
            </p>
            <div class="grid min-w-0 gap-5 md:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="asset in library.assets"
                    :key="asset.publicId"
                    class="min-w-0 space-y-3 rounded-xl border p-4"
                >
                    <img
                        :src="`/website/media/${asset.publicId}/image`"
                        :alt="asset.altEn"
                        class="h-40 w-full rounded bg-muted object-contain"
                    />
                    <p v-if="asset.retired" class="font-semibold">
                        Retired · cannot be attached
                    </p>
                    <dl
                        class="space-y-2 text-sm [overflow-wrap:anywhere] break-words"
                    >
                        <div>
                            <dt class="text-muted-foreground">Alt · EN</dt>
                            <dd>{{ asset.altEn }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Alt · BM</dt>
                            <dd>{{ asset.altBm }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Rights</dt>
                            <dd>{{ asset.rightsReference }}</dd>
                        </div>
                        <div v-if="asset.identifiablePerson">
                            <dt class="text-muted-foreground">
                                Identifiable person · consent reference
                            </dt>
                            <dd>{{ asset.consentReference }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Sanitised file
                            </dt>
                            <dd>
                                {{ asset.mime }} · {{ asset.width }} ×
                                {{ asset.height }} · {{ asset.bytes }} bytes
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">SHA-256</dt>
                            <dd class="font-mono text-xs">
                                {{ asset.sha256 }}
                            </dd>
                        </div>
                    </dl>
                    <Button
                        variant="outline"
                        :disabled="asset.retired || retirement.processing"
                        @click="retire(asset)"
                        >Retire asset</Button
                    >
                </article>
            </div>
            <nav v-if="library.pages > 1" class="flex flex-wrap gap-3 text-sm">
                <Link
                    v-if="library.page > 1"
                    :href="`/website/media?page=${library.page - 1}`"
                    >Previous</Link
                ><span>{{ library.page }} / {{ library.pages }}</span
                ><Link
                    v-if="library.page < library.pages"
                    :href="`/website/media?page=${library.page + 1}`"
                    >Next</Link
                >
            </nav>
        </template>
    </main>
</template>
