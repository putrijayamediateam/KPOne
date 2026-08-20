<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Welcome to KPOne',
        description: 'Sign in with your authorised staff account.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    googleEnabled: boolean;
}>();

const page = usePage();
const googleError = computed(
    () => page.props.errors?.google as string | undefined,
);
</script>

<template>
    <Head title="Staff sign in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <InputError :message="googleError" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Sign in securely
            </Button>
        </div>
    </Form>

    <div class="relative">
        <div class="absolute inset-0 flex items-center">
            <span class="w-full border-t" />
        </div>
        <div class="relative flex justify-center text-xs uppercase">
            <span
                class="bg-slate-50 px-2 text-muted-foreground dark:bg-background"
                >or</span
            >
        </div>
    </div>
    <a
        href="/auth/google/redirect"
        :aria-disabled="!googleEnabled"
        :class="[
            'flex h-10 w-full items-center justify-center gap-3 rounded-md border bg-white text-sm font-medium shadow-xs transition hover:bg-slate-50 dark:bg-card',
            !googleEnabled && 'pointer-events-none opacity-50',
        ]"
    >
        <span
            class="grid size-5 place-items-center rounded-full bg-[conic-gradient(from_-45deg,#4285f4_0_25%,#34a853_0_50%,#fbbc05_0_75%,#ea4335_0)] text-[9px] font-bold text-white"
            >G</span
        >
        Continue with Google
    </a>
    <p v-if="!googleEnabled" class="text-center text-xs text-muted-foreground">
        Google sign-in is ready for configuration.
    </p>
    <p class="text-center text-xs leading-5 text-muted-foreground">
        No public registration. Contact an authorised administrator if you need
        access.
    </p>
</template>
