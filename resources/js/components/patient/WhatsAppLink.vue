<script setup lang="ts">
import { MessageCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { whatsAppNumber } from '@/lib/whatsapp-link';

const props = defineProps<{ phone: string | null | undefined }>();
const number = computed(() => whatsAppNumber(props.phone));
const warning = 'Nombor ini akan dikongsi dengan WhatsApp apabila dibuka';
</script>

<template>
    <span v-if="number" class="inline-flex justify-self-start"
        ><TooltipProvider>
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        as-child
                        variant="outline"
                        size="sm"
                        class="min-h-12 min-w-12"
                    >
                        <a
                            :href="`https://wa.me/${number}`"
                            target="_blank"
                            rel="noopener noreferrer"
                            referrerpolicy="no-referrer"
                            aria-label="Hubungi melalui WhatsApp"
                            :title="warning"
                        >
                            <MessageCircle
                                class="size-4"
                                aria-hidden="true"
                            />WhatsApp
                        </a>
                    </Button>
                </TooltipTrigger>
                <TooltipContent class="max-w-64">{{ warning }}</TooltipContent>
            </Tooltip>
        </TooltipProvider></span
    >
</template>
