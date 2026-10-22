<script setup lang="ts">
import { profileFields, weekdays } from '@/types/website';
import type { Revision } from '@/types/website';
defineProps<{ revision: Revision }>();
</script>
<template>
    <section
        class="min-w-0 rounded-xl border bg-card p-5 text-card-foreground"
        aria-label="Saved draft preview"
    >
        <h2 class="font-semibold">
            Saved draft preview · {{ revision.language.toUpperCase() }} ·
            revision {{ revision.number }}
        </h2>
        <p class="mt-1 text-sm text-muted-foreground">
            Private draft only. Nothing is public yet. Plain text is shown
            exactly as saved.
        </p>
        <p v-if="revision.isSeedCopy" class="mt-3 rounded border p-3 text-sm">
            Unreviewed convenience copy of branch public address and map only.
        </p>
        <img
            v-if="revision.heroAssetPublicId"
            :src="`/website/media/${revision.heroAssetPublicId}/image`"
            alt="Saved hero image preview"
            class="mt-4 max-h-64 w-full rounded object-contain"
        />
        <dl class="mt-4 space-y-3 [overflow-wrap:anywhere] break-words">
            <template v-for="[key, label] in profileFields" :key="key">
                <div v-if="revision.content[key]">
                    <dt class="text-xs font-medium text-muted-foreground">
                        {{ label }}
                    </dt>
                    <dd class="whitespace-pre-wrap">
                        {{ revision.content[key] }}
                    </dd>
                </div>
            </template>
        </dl>
        <h3 class="mt-5 text-sm font-semibold">Opening hours</h3>
        <dl class="mt-2 space-y-1 text-sm">
            <div
                v-for="day in weekdays"
                :key="day"
                class="flex flex-wrap justify-between gap-2"
            >
                <dt class="uppercase">{{ day }}</dt>
                <dd>
                    {{
                        revision.content.opening_hours[day].closed
                            ? 'Closed'
                            : revision.content.opening_hours[day].open &&
                                revision.content.opening_hours[day].close
                              ? `${revision.content.opening_hours[day].open} – ${revision.content.opening_hours[day].close}`
                              : 'Not entered'
                    }}
                </dd>
            </div>
        </dl>
        <div
            v-for="(block, index) in revision.content.contact_blocks"
            :key="index"
            class="mt-4 [overflow-wrap:anywhere] break-words"
        >
            <h3 class="font-medium">{{ block.label }}</h3>
            <p>{{ block.text }}</p>
        </div>
    </section>
</template>
