<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import ServiceDraftPreview from '@/components/website/ServiceDraftPreview.vue';
import type {
    ServiceContent,
    ServiceRevision,
    ServicesPage,
} from '@/types/website-services';
const props = defineProps<{ services: ServicesPage }>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Website drafts', href: '/clinic-management' }],
    },
});
const page = ref(props.services);
const category = computed(() => page.value.type === 'service_category');
const title = computed(() =>
    category.value ? 'Service categories' : 'Services',
);
const base = computed(() =>
    category.value ? '/website/service-categories' : '/website/services',
);
const language = ref<'en' | 'bm'>('en');
const blocked = ref(false);
const selected = ref<ServiceRevision | null>(null);
const values = () => ({
    expected_branch_id: page.value.branchId,
    lock_version: page.value.item?.lockVersion ?? 0,
    idempotency_key: crypto.randomUUID(),
    language: language.value,
    content: JSON.parse(
        JSON.stringify(page.value.drafts[language.value].content),
    ) as ServiceContent,
    branch_ids: [...page.value.drafts[language.value].branchIds],
});
const form = useForm(values());
const filters = useForm({
    search: page.value.filters.search ?? '',
    branch_id: page.value.filters.branch_id ?? '',
    category_id: page.value.filters.category_id ?? '',
    archived: !!page.value.filters.archived,
});
const adopt = () => {
    form.defaults(values());
    form.reset();
    form.clearErrors();
    selected.value = null;
    blocked.value = false;
};
watch(
    () => props.services,
    (value) => {
        page.value = value;
        adopt();
    },
);
const changeLanguage = (value: 'en' | 'bm') => {
    if (!form.isDirty && !form.processing) {
        language.value = value;
        adopt();
    }
};
const save = () => {
    if (!blocked.value && page.value.canManage && !page.value.item?.archived) {
        form.post(
            page.value.item
                ? `${base.value}/${page.value.item.publicId}`
                : base.value,
            {
                preserveScroll: true,
                onSuccess: async () => {
                    await nextTick();
                    adopt();
                },
            },
        );
    }
};
const archive = () => {
    if (!blocked.value && page.value.canManage && page.value.item) {
        form.post(
            `${base.value}/${page.value.item.publicId}/${page.value.item.archived ? 'unarchive' : 'archive'}`,
            {
                onSuccess: async () => {
                    await nextTick();
                    adopt();
                },
            },
        );
    }
};
const preview = computed(
    () => selected.value ?? page.value.drafts[language.value],
);
const label = (key: string) =>
    key === 'seo_title'
        ? 'SEO title'
        : key === 'seo_description'
          ? 'SEO description'
          : key.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const list = (key: string) => form.content[key] as string[];
const sections = computed(() => form.content.description_sections ?? []);
const availableParents = computed(() =>
    page.value.categories.filter(
        (c) =>
            c.publicId !== page.value.item?.publicId &&
            (!category.value || c.parentIds.length === 0),
    ),
);
const galleryCandidate = ref('');
const addGallery = () => {
    if (
        galleryCandidate.value &&
        form.content.gallery_public_ids.length < 12 &&
        !form.content.gallery_public_ids.includes(galleryCandidate.value)
    ) {
        form.content.gallery_public_ids.push(galleryCandidate.value);
        galleryCandidate.value = '';
    }
};
const moveGallery = (index: number, delta: number) => {
    const target = index + delta;

    if (target >= 0 && target < form.content.gallery_public_ids.length) {
        const gallery = [...form.content.gallery_public_ids];
        [gallery[index], gallery[target]] = [gallery[target], gallery[index]];
        form.content.gallery_public_ids = gallery;
    }
};
const assetName = (id: string) => {
    const asset = page.value.assets.find((a) => a.public_id === id);

    return asset
        ? language.value === 'en'
            ? asset.alt_en
            : asset.alt_bm
        : 'Previously selected asset (unavailable for a new attachment)';
};
const stop = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        selected.value = null;
        form.content = {} as ServiceContent;
        form.branch_ids = [];
        galleryCandidate.value = '';
        form.idempotency_key = crypto.randomUUID();
        filters.reset();
        filters.category_id = '';
        filters.branch_id = '';
        page.value = {
            ...page.value,
            item: null,
            items: [],
            history: [],
            categories: [],
            branches: [],
            assets: [],
            drafts: {} as ServicesPage['drafts'],
        };
    }
});
onUnmounted(stop);
const inputClass =
    'mt-1 w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm';
</script>
<template>
    <Head :title="title" />
    <main
        class="mx-auto w-full max-w-7xl min-w-0 space-y-5 p-4 [overflow-wrap:anywhere] sm:p-6"
    >
        <Link href="/clinic-management" class="text-sm underline"
            >Clinic management</Link
        >
        <h1 class="text-2xl font-semibold">{{ title }}</h1>
        <p
            v-if="page.sharedReferencesDiverge"
            role="alert"
            class="rounded border border-amber-500 p-3 text-sm"
        >
            Legacy language references differ. The next save will append
            revisions that converge both languages onto the saved language’s
            shared choices, keeping the other language’s text unchanged.
        </p>
        <p
            v-if="page.danglingServiceLink"
            role="alert"
            class="rounded border border-amber-500 p-3 text-sm"
        >
            This FAQ links to an inactive or unavailable service. Clear or
            change its link and save; both languages will be updated together.
        </p>
        <p
            class="rounded-lg border border-amber-400/60 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100"
        >
            Nothing is public yet. Private drafts only; no publishing or public
            URL. Manual content only, independent of clinical and pricing
            catalogues. Do not include patient data, personal testimonials
            naming patients or medical claims such as guaranteed cures. Price
            text is manual and is not verified or filled by KPOne.
        </p>
        <p v-if="blocked" role="status">
            Branch changed. Loaded drafts, parent, branch and gallery selections
            and history cleared. Reload to continue.
        </p>
        <template v-else>
            <template v-if="!page.editing">
                <form
                    class="flex min-w-0 flex-wrap items-end gap-3 rounded-xl border p-4"
                    @submit.prevent="
                        filters.get(base, { preserveState: false })
                    "
                >
                    <label class="min-w-0 flex-1 text-sm"
                        >Search<input
                            v-model="filters.search"
                            maxlength="150"
                            :class="inputClass"
                    /></label>
                    <label v-if="!category" class="min-w-0 text-sm"
                        >Branch<select
                            v-model="filters.branch_id"
                            :class="inputClass"
                        >
                            <option value="">All organisation branches</option>
                            <option
                                v-for="branch in page.branches"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }}
                            </option>
                        </select></label
                    >
                    <label class="min-w-0 text-sm"
                        >{{
                            category
                                ? 'Parent category'
                                : 'Category / subcategory'
                        }}<select
                            v-model="filters.category_id"
                            :class="inputClass"
                        >
                            <option value="">All categories</option>
                            <option
                                v-for="item in page.categories"
                                :key="item.publicId"
                                :value="item.publicId"
                            >
                                {{ item.parentIds.length ? '↳ ' : ''
                                }}{{ item.title }}
                            </option>
                        </select></label
                    >
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="filters.archived"
                            type="checkbox"
                        />Show archived</label
                    ><Button type="submit">Apply filters</Button>
                </form>
                <Link
                    v-if="page.canManage"
                    :href="`${base}/create`"
                    class="inline-block rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground"
                    >Create {{ category ? 'category' : 'service' }}</Link
                >
                <p v-if="!page.items.length" class="rounded-xl border p-6">
                    No drafts yet. Content is entered manually; no catalogue or
                    old website import.
                </p>
                <ul class="space-y-3">
                    <li
                        v-for="item in page.items"
                        :key="item.publicId"
                        class="rounded-xl border p-4"
                    >
                        <Link
                            :href="`${base}/${item.publicId}`"
                            class="font-semibold underline"
                            >{{ item.title || 'Incomplete draft' }}</Link
                        >
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ item.archived ? 'Archived' : 'Private draft' }} ·
                            version {{ item.lockVersion }}
                        </p>
                    </li>
                </ul>
                <nav v-if="page.pages > 1" class="flex flex-wrap gap-3 text-sm">
                    <Link
                        v-if="page.page > 1"
                        :href="base"
                        :data="{ ...filters.data(), page: page.page - 1 }"
                        >Previous</Link
                    ><span>{{ page.page }} / {{ page.pages }}</span
                    ><Link
                        v-if="page.page < page.pages"
                        :href="base"
                        :data="{ ...filters.data(), page: page.page + 1 }"
                        >Next</Link
                    >
                </nav>
            </template>
            <template v-else>
                <Link :href="base" class="text-sm underline">Back to list</Link>
                <p v-if="!page.canManage" class="rounded border p-3">
                    View only. No save or archive controls.
                </p>
                <p v-if="page.item?.archived" class="rounded border p-3">
                    Archived. Revisions are retained. Unarchive before editing.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="lang in ['en', 'bm'] as const"
                        :key="lang"
                        :variant="language === lang ? 'default' : 'outline'"
                        :disabled="form.isDirty || form.processing"
                        @click="changeLanguage(lang)"
                        >{{
                            lang === 'en'
                                ? 'English (EN)'
                                : 'Bahasa Melayu (BM)'
                        }}</Button
                    >
                </div>
                <p role="status">
                    {{
                        page.drafts[language].missing.length
                            ? `Incomplete · missing ${page.drafts[language].missing.map(label).join(', ')}`
                            : 'Complete · required draft content entered'
                    }}
                    · revision {{ page.drafts[language].number }}
                </p>
                <p v-if="form.isDirty" class="text-sm text-muted-foreground">
                    Save or reload before changing language. Preview shows the
                    saved revision.
                </p>
                <div
                    v-if="Object.keys(form.errors).length"
                    role="alert"
                    class="rounded border border-destructive p-3 text-destructive"
                >
                    <p v-for="(error, key) in form.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div class="grid min-w-0 gap-6 xl:grid-cols-2">
                    <form
                        class="min-w-0 space-y-4 rounded-xl border p-5"
                        @submit.prevent="save"
                    >
                        <fieldset
                            :disabled="
                                !page.canManage ||
                                form.processing ||
                                page.item?.archived
                            "
                            class="min-w-0 space-y-4"
                        >
                            <legend class="mb-3 font-semibold">
                                {{ language.toUpperCase() }} draft fields
                            </legend>
                            <label
                                v-for="(limit, key) in page.textLimits"
                                :key="key"
                                class="block text-sm"
                                >{{ label(key) }} (max {{ limit }})<textarea
                                    v-if="
                                        key === 'short_summary' ||
                                        key === 'short_description'
                                    "
                                    :name="key"
                                    :value="String(form.content[key] ?? '')"
                                    :maxlength="limit"
                                    rows="3"
                                    :class="inputClass"
                                    @input="
                                        form.content[key] = (
                                            $event.target as HTMLTextAreaElement
                                        ).value
                                    " /><input
                                    v-else
                                    :name="key"
                                    :value="String(form.content[key] ?? '')"
                                    :maxlength="limit"
                                    :class="inputClass"
                                    @input="
                                        form.content[key] = (
                                            $event.target as HTMLInputElement
                                        ).value
                                    "
                            /></label>
                            <p class="text-xs text-muted-foreground">
                                Plain text only; no markup, line breaks or
                                hidden characters. Price text is optional;
                                entering it requires a display-approval
                                reference. Incomplete private drafts may be
                                saved.
                            </p>
                            <label class="block text-sm"
                                >{{
                                    category
                                        ? 'Parent category (optional for a root)'
                                        : 'Category / subcategory'
                                }}<select
                                    v-model="form.content.parent_public_id"
                                    :class="inputClass"
                                >
                                    <option :value="null">
                                        {{
                                            category
                                                ? 'Root category'
                                                : 'Not selected · incomplete draft'
                                        }}
                                    </option>
                                    <option
                                        v-for="item in availableParents"
                                        :key="item.publicId"
                                        :value="item.publicId"
                                    >
                                        {{ item.parentIds.length ? '↳ ' : ''
                                        }}{{ item.title }}
                                    </option>
                                </select></label
                            >
                            <template v-if="!category">
                                <fieldset class="min-w-0 space-y-3">
                                    <legend>
                                        Description sections (up to 8)
                                    </legend>
                                    <div
                                        v-for="(section, index) in sections"
                                        :key="index"
                                        class="min-w-0 rounded border p-3"
                                    >
                                        <label class="block text-sm"
                                            >Heading {{ index + 1 }} (max
                                            100)<input
                                                v-model="section.heading"
                                                maxlength="100"
                                                :class="inputClass" /></label
                                        ><label class="block text-sm"
                                            >Body {{ index + 1 }} (max
                                            1500)<textarea
                                                v-model="section.body"
                                                maxlength="1500"
                                                rows="5"
                                                :class="inputClass"
                                            /></label
                                        ><Button
                                            type="button"
                                            variant="outline"
                                            @click="
                                                form.content.description_sections?.splice(
                                                    index,
                                                    1,
                                                )
                                            "
                                            >Remove section</Button
                                        >
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :disabled="sections.length >= 8"
                                        @click="
                                            form.content.description_sections?.push(
                                                { heading: '', body: '' },
                                            )
                                        "
                                        >Add description section</Button
                                    >
                                </fieldset>
                                <fieldset
                                    v-for="(
                                        [count, limit], key
                                    ) in page.listLimits"
                                    :key="key"
                                    class="min-w-0 space-y-2"
                                >
                                    <legend>
                                        {{ label(key) }} (up to {{ count }}, max
                                        {{ limit }} each)
                                    </legend>
                                    <div
                                        v-for="(_, index) in list(key)"
                                        :key="index"
                                        class="flex min-w-0 gap-2"
                                    >
                                        <input
                                            v-model="list(key)[index]"
                                            :aria-label="`${label(key)} ${index + 1}`"
                                            :maxlength="limit"
                                            :class="inputClass"
                                        /><Button
                                            type="button"
                                            variant="outline"
                                            @click="list(key).splice(index, 1)"
                                            >Remove</Button
                                        >
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :disabled="list(key).length >= count"
                                        @click="list(key).push('')"
                                        >Add
                                        {{ label(key).toLowerCase() }}
                                        entry</Button
                                    >
                                </fieldset>
                                <fieldset class="space-y-2">
                                    <legend>
                                        Branch availability (one or more for
                                        completeness)
                                    </legend>
                                    <label
                                        v-for="branch in page.branches"
                                        :key="branch.id"
                                        class="flex items-center gap-2 text-sm"
                                        ><input
                                            v-model="form.branch_ids"
                                            type="checkbox"
                                            :value="branch.id"
                                        />{{ branch.name }}</label
                                    >
                                </fieldset>
                            </template>
                            <label
                                v-if="page.canSelectMedia"
                                class="block text-sm"
                                >Cover image (optional)<select
                                    v-model="form.content.cover_public_id"
                                    :class="inputClass"
                                >
                                    <option :value="null">No cover</option>
                                    <option
                                        v-for="asset in page.assets"
                                        :key="asset.public_id"
                                        :value="asset.public_id"
                                    >
                                        {{
                                            language === 'en'
                                                ? asset.alt_en
                                                : asset.alt_bm
                                        }}
                                    </option>
                                </select></label
                            >
                            <fieldset
                                v-if="!category && page.canSelectMedia"
                                class="min-w-0 space-y-2"
                            >
                                <legend>
                                    Gallery (up to 12, saved in this order)
                                </legend>
                                <label class="block text-sm"
                                    >Choose gallery image<select
                                        v-model="galleryCandidate"
                                        :class="inputClass"
                                    >
                                        <option value="">
                                            Select active media
                                        </option>
                                        <option
                                            v-for="asset in page.assets"
                                            :key="asset.public_id"
                                            :value="asset.public_id"
                                        >
                                            {{
                                                language === 'en'
                                                    ? asset.alt_en
                                                    : asset.alt_bm
                                            }}
                                        </option>
                                    </select></label
                                ><Button
                                    type="button"
                                    variant="outline"
                                    :disabled="
                                        !galleryCandidate ||
                                        form.content.gallery_public_ids
                                            .length >= 12
                                    "
                                    @click="addGallery"
                                    >Add gallery image</Button
                                >
                                <div
                                    v-for="(id, index) in form.content
                                        .gallery_public_ids"
                                    :key="id"
                                    class="min-w-0 space-y-2 rounded border p-2"
                                >
                                    <p class="text-sm">
                                        {{ index + 1 }}. {{ assetName(id) }}
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            :disabled="index === 0"
                                            @click="moveGallery(index, -1)"
                                            >Move up</Button
                                        ><Button
                                            type="button"
                                            variant="outline"
                                            :disabled="
                                                index ===
                                                form.content.gallery_public_ids
                                                    .length -
                                                    1
                                            "
                                            @click="moveGallery(index, 1)"
                                            >Move down</Button
                                        ><Button
                                            type="button"
                                            variant="outline"
                                            @click="
                                                form.content.gallery_public_ids.splice(
                                                    index,
                                                    1,
                                                )
                                            "
                                            >Remove image</Button
                                        >
                                    </div>
                                </div>
                            </fieldset>
                            <Link
                                v-if="page.canSelectMedia"
                                href="/website/media"
                                class="inline-block text-sm underline"
                                >Open media library · uploads stay there</Link
                            >
                            <label class="block text-sm"
                                >Sort order (-100000 to 100000)<input
                                    v-model.number="form.content.sort_order"
                                    type="number"
                                    min="-100000"
                                    max="100000"
                                    :class="inputClass"
                            /></label>
                            <label class="flex items-center gap-2 text-sm"
                                ><input
                                    v-model="form.content.include_on_website"
                                    type="checkbox"
                                />Include on website · draft intent only</label
                            >
                            <Button
                                v-if="page.canManage"
                                type="submit"
                                :disabled="form.processing"
                                >Save {{ language.toUpperCase() }} draft</Button
                            >
                        </fieldset>
                    </form>
                    <div class="min-w-0 space-y-5">
                        <ServiceDraftPreview
                            :revision="preview"
                            :category="category"
                        />
                        <section class="space-y-3 rounded-xl border p-5">
                            <h2 class="font-semibold">
                                Revision history · read only
                            </h2>
                            <button
                                v-for="revision in page.history"
                                :key="revision.id ?? 0"
                                class="block w-full rounded border p-3 text-left text-sm"
                                @click="selected = revision"
                            >
                                {{ revision.language.toUpperCase() }} · revision
                                {{ revision.number }} ·
                                {{ revision.createdAt }} UTC</button
                            ><Button
                                v-if="selected"
                                variant="outline"
                                @click="selected = null"
                                >Show current saved draft</Button
                            >
                            <nav
                                v-if="page.historyPages > 1"
                                class="flex flex-wrap gap-3 text-sm"
                            >
                                <Link
                                    v-if="page.historyPage > 1"
                                    :href="`${base}/${page.item?.publicId}`"
                                    :data="{
                                        history_page: page.historyPage - 1,
                                    }"
                                    >Previous revisions</Link
                                ><span
                                    >{{ page.historyPage }} /
                                    {{ page.historyPages }}</span
                                ><Link
                                    v-if="page.historyPage < page.historyPages"
                                    :href="`${base}/${page.item?.publicId}`"
                                    :data="{
                                        history_page: page.historyPage + 1,
                                    }"
                                    >Next revisions</Link
                                >
                            </nav>
                        </section>
                        <section
                            v-if="page.item"
                            class="space-y-3 rounded-xl border p-5"
                        >
                            <h2 class="font-semibold">Retained draft</h2>
                            <p class="text-sm">
                                Internal slug (not a public URL):
                                {{ page.item.slug }}
                            </p>
                            <Button
                                v-if="page.canManage"
                                type="button"
                                variant="outline"
                                :disabled="form.processing || form.isDirty"
                                @click="archive"
                                >{{
                                    page.item.archived ? 'Unarchive' : 'Archive'
                                }}</Button
                            >
                        </section>
                    </div>
                </div>
            </template>
        </template>
    </main>
</template>
