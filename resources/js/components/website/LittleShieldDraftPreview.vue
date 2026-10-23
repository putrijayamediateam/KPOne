<script setup lang="ts">
import type { LittleRevision } from '@/types/website-little-shield';
defineProps<{ revision: LittleRevision }>();
const label = (key: string) =>
    key.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
</script>
<template>
    <section
        aria-label="Saved draft preview"
        class="min-w-0 space-y-4 rounded-xl border p-5 [overflow-wrap:anywhere]"
    >
        <h2 class="font-semibold">
            Saved draft preview · {{ revision.language.toUpperCase() }} ·
            revision {{ revision.number }}
        </h2>
        <p class="text-sm text-muted-foreground">
            Nothing is public yet. Exact saved revision; unsaved edits are not
            shown.
        </p>
        <img
            v-if="revision.content.cover_public_id"
            :src="`/website/media/${revision.content.cover_public_id}/image`"
            alt="Saved cover"
            class="max-h-64 max-w-full rounded object-contain"
        />
        <h3 class="text-xl font-semibold">{{ revision.content.title }}</h3>
        <p class="font-medium">{{ revision.content.tagline }}</p>
        <p>{{ revision.content.introduction }}</p>
        <p
            v-for="(paragraph, index) in revision.content.overview_paragraphs"
            :key="index"
        >
            {{ paragraph }}
        </p>
        <template
            v-for="key in [
                'programme_pillars',
                'programme_flow_steps',
            ] as const"
            :key="key"
        >
            <h4 class="font-semibold">{{ label(key) }}</h4>
            <ol class="list-decimal space-y-3 pl-5">
                <li v-for="(card, index) in revision.content[key]" :key="index">
                    <strong>{{ card.title }}</strong>
                    <p>{{ card.description }}</p>
                </li>
            </ol>
        </template>
        <h4 class="font-semibold">Medical safeguards</h4>
        <ul class="list-disc space-y-2 pl-5">
            <li
                v-for="(bullet, index) in revision.content.medical_safeguards"
                :key="index"
            >
                {{ bullet }}
            </li>
        </ul>
        <h4 class="font-semibold">Partnerships</h4>
        <p>{{ revision.content.partnership_introduction }}</p>
        <div
            v-for="(card, index) in revision.content.partner_types"
            :key="index"
        >
            <strong>{{ card.title }}</strong>
            <p>{{ card.description }}</p>
        </div>
        <p>{{ revision.content.how_to_take_part }}</p>
        <figure
            v-for="(id, index) in revision.content.gallery_public_ids"
            :key="id"
        >
            <img
                :src="`/website/media/${id}/image`"
                :alt="`Saved poster ${index + 1}`"
                class="max-h-64 max-w-full rounded object-contain"
            />
            <figcaption class="text-sm">Poster {{ index + 1 }}</figcaption>
        </figure>
        <p class="text-xs text-muted-foreground">
            Saved coverage branch ids:
            {{ revision.branchIds.join(', ') || 'None' }}
        </p>
        <p class="text-xs text-muted-foreground">
            Include on website:
            {{
                revision.content.include_on_website
                    ? 'Yes · draft intent only'
                    : 'No'
            }}
        </p>
    </section>
</template>
