<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { profileFields, weekdays } from '@/types/website';
import type { Language, Profile, Revision } from '@/types/website';
import DraftPreview from './DraftPreview.vue';
const props = defineProps<{ profile: Profile }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Website drafts', href: '/website/branch-profiles' },
        ],
    },
});
const language = ref<Language>('en');
const blocked = ref(false);
const selected = ref<Revision | null>(null);
const values = () => {
    const draft = props.profile.drafts[language.value];
    const content = JSON.parse(
        JSON.stringify(draft.content),
    ) as typeof draft.content;

    // Stored international digits are displayed with + so editing uses the same strict input rules.
    if (content.whatsapp_number && !content.whatsapp_number.startsWith('60')) {
        content.whatsapp_number = `+${content.whatsapp_number}`;
    }

    return {
        expected_branch_id: props.profile.branch.id,
        lock_version: props.profile.lockVersion,
        idempotency_key: crypto.randomUUID(),
        language: language.value,
        content,
        hero_asset_public_id: draft.heroAssetPublicId,
    };
};
const form = useForm(values());
const adopt = () => {
    form.defaults(values());
    form.reset();
    form.clearErrors();
    selected.value = null;
    blocked.value = false;
};
const changeLanguage = (value: Language) => {
    if (form.isDirty || form.processing) {
        return;
    }

    language.value = value;
    adopt();
};
const preview = computed(
    () => selected.value ?? props.profile.drafts[language.value],
);
const save = () => {
    if (!blocked.value && props.profile.canManage) {
        form.post('/website/branch-profiles', {
            preserveScroll: true,
            onSuccess: async () => {
                await nextTick();
                adopt();
            },
        });
    }
};
const errors = computed(() => Object.values(form.errors));
const stop = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        selected.value = null;
        form.reset();

        for (const [key] of profileFields) {
            form.content[key] = '';
        }

        form.content.contact_blocks = [];

        for (const day of weekdays) {
            form.content.opening_hours[day] = {
                closed: false,
                open: '',
                close: '',
            };
        }

        form.hero_asset_public_id = null;
        form.idempotency_key = crypto.randomUUID();
    }
});
watch(() => props.profile.branch.id, adopt);
onUnmounted(stop);
const inputClass =
    'mt-1 w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm';
</script>
<template>
    <Head title="Branch public profiles" />
    <main class="mx-auto w-full max-w-7xl space-y-5 p-4 sm:p-6">
        <Link href="/clinic-management" class="text-sm underline"
            >Clinic management</Link
        >
        <header>
            <h1 class="text-2xl font-semibold">Branch public profiles</h1>
            <p class="mt-1 text-muted-foreground">
                {{ profile.branch.name }} · private website drafts
            </p>
        </header>
        <p
            class="rounded-lg border border-amber-400/60 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100"
        >
            Nothing is public yet. Saving creates an immutable draft revision.
            This screen cannot publish.
        </p>
        <p v-if="blocked" role="status">
            Branch changed. Content and drafts cleared. Reload this page to
            continue.
        </p>
        <template v-else>
            <div class="flex flex-wrap gap-2" aria-label="Draft language">
                <Button
                    v-for="lang in ['en', 'bm'] as const"
                    :key="lang"
                    :variant="language === lang ? 'default' : 'outline'"
                    :disabled="form.isDirty || form.processing"
                    @click="changeLanguage(lang)"
                    >{{
                        lang === 'en' ? 'English (EN)' : 'Bahasa Melayu (BM)'
                    }}</Button
                >
            </div>
            <p v-if="form.isDirty" class="text-sm text-muted-foreground">
                Save or reload before changing language. The preview shows the
                saved revision.
            </p>
            <p class="text-sm" role="status">
                {{
                    profile.drafts[language].missing.length
                        ? `Incomplete · ${profile.drafts[language].missing.length} required fields missing`
                        : 'Complete · all required fields entered'
                }}
                · revision {{ profile.drafts[language].number }}
            </p>
            <p v-if="!profile.canManage" class="rounded border p-3 text-sm">
                View only. You can read drafts and revision history.
            </p>
            <div
                v-if="errors.length"
                role="alert"
                class="rounded border border-destructive p-3 text-destructive"
            >
                <p v-for="error in errors" :key="error">{{ error }}</p>
            </div>
            <div class="grid min-w-0 gap-6 xl:grid-cols-2">
                <form
                    class="min-w-0 space-y-5 rounded-xl border p-5"
                    @submit.prevent="save"
                >
                    <fieldset
                        :disabled="!profile.canManage || form.processing"
                        class="min-w-0 space-y-4"
                    >
                        <legend class="mb-3 font-semibold">
                            {{ language.toUpperCase() }} draft fields
                        </legend>
                        <label
                            v-for="[key, label] in profileFields"
                            :key="key"
                            class="block text-sm font-medium"
                            >{{ label }}
                            <span class="font-normal text-muted-foreground"
                                >(max {{ profile.limits[key] }})</span
                            ><input
                                v-model="form.content[key]"
                                :name="key"
                                :maxlength="profile.limits[key]"
                                :class="inputClass"
                        /></label>
                        <p class="text-xs text-muted-foreground">
                            Phone: public contact only. WhatsApp: Malaysian 01…
                            or an explicit +international number. Map links must
                            use HTTPS. No HTML or hidden control characters.
                        </p>
                        <fieldset class="min-w-0 space-y-3">
                            <legend class="font-semibold">Opening hours</legend>
                            <div
                                v-for="day in weekdays"
                                :key="day"
                                class="rounded-lg border p-3"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <span
                                        class="text-sm font-medium uppercase"
                                        >{{ day }}</span
                                    ><label
                                        class="flex items-center gap-2 text-sm"
                                        ><input
                                            v-model="
                                                form.content.opening_hours[day]
                                                    .closed
                                            "
                                            type="checkbox"
                                            @change="
                                                () => {
                                                    if (
                                                        form.content
                                                            .opening_hours[day]
                                                            .closed
                                                    ) {
                                                        form.content.opening_hours[
                                                            day
                                                        ].open = '';
                                                        form.content.opening_hours[
                                                            day
                                                        ].close = '';
                                                    }
                                                }
                                            "
                                        />Closed</label
                                    >
                                </div>
                                <div
                                    v-if="
                                        !form.content.opening_hours[day].closed
                                    "
                                    class="mt-2 grid min-w-0 grid-cols-2 gap-2"
                                >
                                    <label class="min-w-0 text-xs"
                                        >Open<input
                                            v-model="
                                                form.content.opening_hours[day]
                                                    .open
                                            "
                                            type="time"
                                            :aria-label="`${day} open`"
                                            :class="inputClass" /></label
                                    ><label class="min-w-0 text-xs"
                                        >Close<input
                                            v-model="
                                                form.content.opening_hours[day]
                                                    .close
                                            "
                                            type="time"
                                            :aria-label="`${day} close`"
                                            :class="inputClass"
                                    /></label>
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Same-day hours only. Both times may be empty in
                                an incomplete draft.
                            </p>
                        </fieldset>
                        <label class="block text-sm font-medium"
                            >Hero image (optional)<select
                                v-model="form.hero_asset_public_id"
                                :class="inputClass"
                            >
                                <option :value="null">No image</option>
                                <option
                                    v-for="asset in profile.assets"
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
                        <fieldset class="space-y-3">
                            <legend class="font-semibold">
                                Contact blocks (up to 3)
                            </legend>
                            <div
                                v-for="(block, index) in form.content
                                    .contact_blocks"
                                :key="index"
                                class="space-y-2 rounded border p-3"
                            >
                                <label class="block text-sm"
                                    >Label (max 60)<input
                                        v-model="block.label"
                                        maxlength="60"
                                        :class="inputClass" /></label
                                ><label class="block text-sm"
                                    >Text (max 300)<input
                                        v-model="block.text"
                                        maxlength="300"
                                        :class="inputClass" /></label
                                ><Button
                                    type="button"
                                    variant="outline"
                                    @click="
                                        form.content.contact_blocks.splice(
                                            index,
                                            1,
                                        )
                                    "
                                    >Remove block</Button
                                >
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    form.content.contact_blocks.length >= 3
                                "
                                @click="
                                    form.content.contact_blocks.push({
                                        label: '',
                                        text: '',
                                    })
                                "
                                >Add contact block</Button
                            >
                        </fieldset>
                        <Button
                            v-if="profile.canManage"
                            type="submit"
                            :disabled="form.processing"
                            >Save {{ language.toUpperCase() }} draft</Button
                        >
                    </fieldset>
                </form>
                <div class="min-w-0 space-y-5">
                    <DraftPreview :revision="preview" />
                    <section class="rounded-xl border p-5">
                        <h2 class="font-semibold">
                            Revision history · read only
                        </h2>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="revision in profile.history.data"
                                :key="revision.id ?? 0"
                            >
                                <button
                                    class="w-full rounded border px-3 py-2 text-left text-sm hover:bg-muted"
                                    @click="selected = revision"
                                >
                                    {{ revision.language.toUpperCase() }} ·
                                    revision {{ revision.number
                                    }}{{
                                        revision.isSeedCopy
                                            ? ' · unreviewed seed'
                                            : ''
                                    }}
                                    · {{ revision.createdAt }} UTC
                                </button>
                            </li>
                        </ul>
                        <Button
                            v-if="selected"
                            class="mt-3"
                            variant="outline"
                            @click="selected = null"
                            >Show current saved draft</Button
                        >
                        <nav
                            v-if="profile.history.pages > 1"
                            class="mt-3 flex flex-wrap gap-3 text-sm"
                        >
                            <Link
                                v-if="profile.history.page > 1"
                                :href="`/website/branch-profiles?page=${profile.history.page - 1}`"
                                >Previous</Link
                            ><span
                                >{{ profile.history.page }} /
                                {{ profile.history.pages }}</span
                            ><Link
                                v-if="
                                    profile.history.page < profile.history.pages
                                "
                                :href="`/website/branch-profiles?page=${profile.history.page + 1}`"
                                >Next</Link
                            >
                        </nav>
                    </section>
                </div>
            </div>
        </template>
    </main>
</template>
