<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import LittleShieldDraftPreview from '@/components/website/LittleShieldDraftPreview.vue';
import type {
    LittlePage,
    LittleContent,
    LittleRevision,
} from '@/types/website-little-shield';
const props = defineProps<{ littleShield: LittlePage }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Little Shield', href: '/website/little-shield' },
        ],
    },
});
const page = ref(props.littleShield);
const language = ref<'en' | 'bm'>('en');
const blocked = ref(false);
const selected = ref<LittleRevision | null>(null);
const galleryCandidate = ref('');
const values = () => ({
    expected_branch_id: page.value.branchId,
    item_public_id: page.value.item?.publicId ?? null,
    lock_version: page.value.item?.lockVersion ?? 0,
    idempotency_key: crypto.randomUUID(),
    language: language.value,
    content: JSON.parse(
        JSON.stringify(page.value.drafts[language.value].content),
    ) as LittleContent,
    branch_ids: [...page.value.drafts[language.value].branchIds],
});
const form = useForm(values());
const adopt = () => {
    form.defaults(values());
    form.reset();
    form.clearErrors();
    selected.value = null;
    galleryCandidate.value = '';
    blocked.value = false;
};
watch(
    () => props.littleShield,
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
    if (!blocked.value && page.value.canManage) {
        form.post('/website/little-shield', {
            preserveScroll: true,
            onSuccess: async () => {
                await nextTick();
                adopt();
            },
        });
    }
};
const preview = computed(
    () => selected.value ?? page.value.drafts[language.value],
);
const label = (key: string) =>
    key.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const move = <T,>(entries: T[], index: number, delta: number) => {
    const target = index + delta;

    if (target >= 0 && target < entries.length) {
        [entries[index], entries[target]] = [entries[target], entries[index]];
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
const addPoster = () => {
    if (
        galleryCandidate.value &&
        form.content.gallery_public_ids.length < 8 &&
        !form.content.gallery_public_ids.includes(galleryCandidate.value)
    ) {
        form.content.gallery_public_ids.push(galleryCandidate.value);
        galleryCandidate.value = '';
    }
};
const stop = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        selected.value = null;
        galleryCandidate.value = '';
        form.content = {} as LittleContent;
        form.branch_ids = [];
        form.item_public_id = null;
        form.idempotency_key = crypto.randomUUID();
        page.value = {
            ...page.value,
            item: null,
            history: [],
            branches: [],
            assets: [],
            drafts: {} as LittlePage['drafts'],
        };
    }
});
onUnmounted(stop);
const inputClass =
    'mt-1 w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm';
</script>
<template>
    <Head title="Little Shield" />
    <main
        class="mx-auto w-full max-w-7xl min-w-0 space-y-5 p-4 [overflow-wrap:anywhere] sm:p-6"
    >
        <Link href="/clinic-management" class="text-sm underline"
            >Clinic management</Link
        >
        <h1 class="text-2xl font-semibold">Little Shield</h1>
        <p
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-sm"
        >
            Nothing is public yet. Private manual drafts only. Do not include
            child, parent or school personal data, photos of children without
            recorded consent, or any claim that guarantees outcomes. No form,
            sign-up, data collection, publishing or public URL. Include on
            website is draft intent only.
        </p>
        <p v-if="!page.item && !blocked" class="text-sm text-muted-foreground">
            No saved Little Shield page yet. The organisation's single page is
            created on its first save. Incomplete private drafts may be saved.
        </p>
        <p v-if="!page.canManage" class="text-sm font-medium">
            Read-only · you can review saved drafts and history.
        </p>
        <section
            v-if="blocked"
            role="status"
            class="space-y-3 rounded-xl border p-4 text-sm"
        >
            <p>
                Branch changed. Loaded drafts, coverage and poster selections
                and history cleared. Reload to continue.
            </p>
            <Button
                type="button"
                variant="outline"
                @click="
                    router.get(
                        '/website/little-shield',
                        {},
                        { preserveState: false },
                    )
                "
                >Reload saved drafts</Button
            >
        </section>
        <template v-if="!blocked">
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    :disabled="form.isDirty || form.processing"
                    :aria-pressed="language === 'en'"
                    @click="changeLanguage('en')"
                    >English (EN)</Button
                ><Button
                    variant="outline"
                    :disabled="form.isDirty || form.processing"
                    :aria-pressed="language === 'bm'"
                    @click="changeLanguage('bm')"
                    >Bahasa Melayu (BM)</Button
                >
            </div>
            <p role="status" class="text-sm">
                {{
                    page.drafts[language].missing.length
                        ? 'Incomplete · missing ' +
                          page.drafts[language].missing.map(label).join(', ')
                        : 'Complete · required draft content entered'
                }}
                · revision {{ page.drafts[language].number }}
            </p>
            <div
                v-if="Object.keys(form.errors).length"
                role="alert"
                class="rounded border border-destructive p-4 text-sm text-destructive"
            >
                <p v-for="(error, key) in form.errors" :key="key">
                    {{ error }}
                </p>
            </div>
            <div class="grid min-w-0 items-start gap-6 xl:grid-cols-2">
                <form class="min-w-0 space-y-5" @submit.prevent="save">
                    <fieldset
                        :disabled="!page.canManage || form.processing"
                        class="min-w-0 space-y-5 rounded-xl border p-5"
                    >
                        <legend class="px-2 font-semibold">
                            {{ language.toUpperCase() }} draft fields
                        </legend>
                        <label
                            v-for="(limit, key) in page.textLimits"
                            :key="key"
                            class="block text-sm"
                            >{{ label(key) }} (max {{ limit }})<textarea
                                v-model="form.content[key]"
                                :maxlength="limit"
                                :class="inputClass"
                                :rows="
                                    key === 'title' || key === 'tagline' ? 2 : 3
                                "
                            />
                        </label>
                        <p class="text-xs text-muted-foreground">
                            Plain text only; no markup or hidden characters. How
                            to take part is text only, never a link or form.
                            Saving coverage branches coordinates the other
                            language's saved references and preserves its text.
                        </p>
                        <fieldset
                            v-for="(limits, key) in page.listLimits"
                            :key="key"
                            class="min-w-0 space-y-3 rounded border p-3"
                        >
                            <legend>
                                {{ label(key) }} (up to {{ limits[0] }}, max
                                {{ limits[1] }} each)
                            </legend>
                            <div
                                v-for="(_, index) in form.content[key]"
                                :key="index"
                                class="min-w-0 space-y-2"
                            >
                                <label class="block text-sm"
                                    >{{ label(key) }} {{ index + 1
                                    }}<textarea
                                        v-model="form.content[key][index]"
                                        :maxlength="limits[1]"
                                        :class="inputClass"
                                        rows="3"
                                    />
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Move ${key} ${index + 1} up`"
                                        :disabled="index === 0"
                                        @click="
                                            move(form.content[key], index, -1)
                                        "
                                        >Move up</Button
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Move ${key} ${index + 1} down`"
                                        :disabled="
                                            index ===
                                            form.content[key].length - 1
                                        "
                                        @click="
                                            move(form.content[key], index, 1)
                                        "
                                        >Move down</Button
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Remove ${key} ${index + 1}`"
                                        @click="
                                            form.content[key].splice(index, 1)
                                        "
                                        >Remove</Button
                                    >
                                </div>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    form.content[key].length >= limits[0]
                                "
                                @click="form.content[key].push('')"
                                >Add {{ label(key).toLowerCase() }}</Button
                            >
                        </fieldset>
                        <fieldset
                            v-for="(limits, key) in page.cardLimits"
                            :key="key"
                            class="min-w-0 space-y-3 rounded border p-3"
                        >
                            <legend>
                                {{ label(key) }} (up to {{ limits[0] }})
                            </legend>
                            <div
                                v-for="(card, index) in form.content[key]"
                                :key="index"
                                class="min-w-0 space-y-2 rounded border p-3"
                            >
                                <label class="block text-sm"
                                    >{{ label(key) }} {{ index + 1 }} title (max
                                    {{ limits[1] }})<input
                                        v-model="card.title"
                                        :maxlength="limits[1]"
                                        :class="inputClass"
                                /></label>
                                <label class="block text-sm"
                                    >{{ label(key) }}
                                    {{ index + 1 }} description (max
                                    {{ limits[2] }})<textarea
                                        v-model="card.description"
                                        :maxlength="limits[2]"
                                        :class="inputClass"
                                        rows="3"
                                    />
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Move ${key} ${index + 1} up`"
                                        :disabled="index === 0"
                                        @click="
                                            move(form.content[key], index, -1)
                                        "
                                        >Move up</Button
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Move ${key} ${index + 1} down`"
                                        :disabled="
                                            index ===
                                            form.content[key].length - 1
                                        "
                                        @click="
                                            move(form.content[key], index, 1)
                                        "
                                        >Move down</Button
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Remove ${key} ${index + 1}`"
                                        @click="
                                            form.content[key].splice(index, 1)
                                        "
                                        >Remove</Button
                                    >
                                </div>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    form.content[key].length >= limits[0]
                                "
                                @click="
                                    form.content[key].push({
                                        title: '',
                                        description: '',
                                    })
                                "
                                >Add {{ label(key).toLowerCase() }}</Button
                            >
                        </fieldset>
                        <fieldset class="min-w-0 space-y-2">
                            <legend>
                                Coverage branches (one or more for completeness)
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
                        <label class="block text-sm"
                            >Cover image (optional)<select
                                v-model="form.content.cover_public_id"
                                :disabled="!page.canSelectMedia"
                                :class="inputClass"
                            >
                                <option :value="null">No cover</option>
                                <option
                                    v-if="
                                        form.content.cover_public_id &&
                                        !page.assets.some(
                                            (a) =>
                                                a.public_id ===
                                                form.content.cover_public_id,
                                        )
                                    "
                                    :value="form.content.cover_public_id"
                                >
                                    Previously selected asset (remove before
                                    saving)
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
                        >
                        <fieldset class="min-w-0 space-y-3 rounded border p-3">
                            <legend>
                                Poster gallery (up to 8, saved in this order)
                            </legend>
                            <div
                                v-for="(id, index) in form.content
                                    .gallery_public_ids"
                                :key="id"
                                class="min-w-0 space-y-2"
                            >
                                <p class="text-sm">
                                    {{ index + 1 }}. {{ assetName(id) }}
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Move poster ${index + 1} up`"
                                        :disabled="index === 0"
                                        @click="
                                            move(
                                                form.content.gallery_public_ids,
                                                index,
                                                -1,
                                            )
                                        "
                                        >Move up</Button
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Move poster ${index + 1} down`"
                                        :disabled="
                                            index ===
                                            form.content.gallery_public_ids
                                                .length -
                                                1
                                        "
                                        @click="
                                            move(
                                                form.content.gallery_public_ids,
                                                index,
                                                1,
                                            )
                                        "
                                        >Move down</Button
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :aria-label="`Remove poster ${index + 1}`"
                                        @click="
                                            form.content.gallery_public_ids.splice(
                                                index,
                                                1,
                                            )
                                        "
                                        >Remove</Button
                                    >
                                </div>
                            </div>
                            <label class="block text-sm"
                                >Choose poster image<select
                                    v-model="galleryCandidate"
                                    :disabled="!page.canSelectMedia"
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
                                    form.content.gallery_public_ids.length >= 8
                                "
                                @click="addPoster"
                                >Add poster</Button
                            >
                        </fieldset>
                        <Link
                            v-if="page.canSelectMedia"
                            href="/website/media"
                            class="block text-sm underline"
                            >Open media library · uploads stay there</Link
                        >
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
                <LittleShieldDraftPreview :revision="preview" />
            </div>
            <section
                aria-label="Revision history"
                class="min-w-0 space-y-3 rounded-xl border p-5"
            >
                <h2 class="font-semibold">Revision history · read only</h2>
                <p
                    v-if="!page.history.length"
                    class="text-sm text-muted-foreground"
                >
                    No saved revisions yet.
                </p>
                <Button
                    v-for="revision in page.history"
                    :key="revision.id!"
                    variant="outline"
                    class="h-auto w-full justify-start text-left whitespace-normal"
                    @click="selected = revision"
                    >{{ revision.language.toUpperCase() }} · revision
                    {{ revision.number }} · {{ revision.createdAt }} UTC</Button
                >
                <nav class="flex flex-wrap gap-3 text-sm">
                    <Link
                        v-if="page.historyPage > 1"
                        :href="`/website/little-shield?history_page=${page.historyPage - 1}`"
                        >Previous revisions</Link
                    ><span
                        >{{ page.historyPage }} / {{ page.historyPages }}</span
                    ><Link
                        v-if="page.historyPage < page.historyPages"
                        :href="`/website/little-shield?history_page=${page.historyPage + 1}`"
                        >Next revisions</Link
                    >
                </nav>
            </section>
        </template>
    </main>
</template>
