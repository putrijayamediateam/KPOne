<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

// Shared long-text disclosure (R1-07): a one-line or two-line clamp with a
// tooltip on desktop hover/focus, and a tap-to-expand panel on mobile. The
// full text is never placed in an HTML attribute (never `title`), only in
// element content, so it cannot leak into a native browser tooltip, page
// markup dumps or link previews.
withDefaults(
    defineProps<{
        text?: string | null;
        variant?: 'truncate' | 'clamp-2';
        fallback?: string;
    }>(),
    { text: null, variant: 'truncate', fallback: '—' },
);
</script>

<template>
    <template v-if="text">
        <TooltipProvider :delay-duration="300">
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        :class="[
                            'hidden w-full rounded text-left text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:block',
                            variant === 'clamp-2' ? 'line-clamp-2' : 'truncate',
                        ]"
                    >
                        {{ text }}
                    </button>
                </TooltipTrigger>
                <TooltipContent
                    side="top"
                    class="max-w-sm break-words whitespace-normal"
                >
                    {{ text }}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
        <details class="group md:hidden">
            <summary
                class="flex cursor-pointer list-none items-center gap-1 rounded focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span
                    :class="[
                        'min-w-0 flex-1 text-muted-foreground',
                        variant === 'clamp-2' ? 'line-clamp-2' : 'truncate',
                    ]"
                    >{{ text }}</span
                >
                <ChevronDown class="size-3.5 shrink-0 group-open:rotate-180" />
            </summary>
            <p class="mt-1 max-w-full break-words whitespace-normal">
                {{ text }}
            </p>
        </details>
    </template>
    <span v-else class="text-muted-foreground">{{ fallback }}</span>
</template>
