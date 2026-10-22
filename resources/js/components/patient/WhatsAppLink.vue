<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
const props = withDefaults(
    defineProps<{
        canWhatsapp: boolean;
        routeKey: string;
        context?: 'patient' | 'claim';
    }>(),
    { context: 'patient' },
);
const href = computed(
    () =>
        '/patient-contact/' +
        props.context +
        '/' +
        encodeURIComponent(props.routeKey) +
        '/whatsapp',
);
const warning =
    'Open WhatsApp chat — your browser will share this saved number with WhatsApp';
</script>
<template>
    <span
        v-if="canWhatsapp && routeKey"
        class="inline-flex shrink-0 justify-self-start"
    >
        <TooltipProvider
            ><Tooltip
                ><TooltipTrigger as-child>
                    <Button
                        as-child
                        variant="ghost"
                        size="icon"
                        class="min-h-12 min-w-12"
                    >
                        <a
                            :href="href"
                            target="_blank"
                            rel="noopener noreferrer"
                            referrerpolicy="no-referrer"
                            aria-label="Open WhatsApp chat"
                            :title="warning"
                        >
                            <svg
                                viewBox="0 0 32 32"
                                class="size-8"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <circle cx="16" cy="16" r="16" fill="#25D366" />
                                <path
                                    fill="white"
                                    d="M23.3 8.7A10.2 10.2 0 0 0 7.2 21l-1.4 5.1 5.2-1.4A10.2 10.2 0 0 0 26.2 16c0-2.7-1-5.3-2.9-7.3ZM16 24.5a8.5 8.5 0 0 1-4.3-1.2l-.3-.2-3.1.8.8-3-.2-.3A8.5 8.5 0 1 1 16 24.5Zm4.7-6.4c-.3-.1-1.5-.7-1.7-.8-.3-.1-.4-.1-.6.2l-.8 1c-.1.1-.3.2-.5.1-.3-.1-1.1-.4-2.1-1.3-.8-.7-1.3-1.5-1.5-1.8-.1-.2 0-.4.1-.5l.4-.5.3-.4c.1-.2.1-.3 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.3.3-.9.9-.9 2.1s.9 2.4 1 2.6c.1.2 1.8 2.8 4.4 3.9.6.3 1.1.4 1.5.5.6.2 1.2.2 1.6.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2-.1-.1-.3-.2-.5-.3Z"
                                />
                            </svg>
                        </a>
                    </Button> </TooltipTrigger
                ><TooltipContent class="max-w-64">{{
                    warning
                }}</TooltipContent></Tooltip
            ></TooltipProvider
        >
    </span>
</template>
