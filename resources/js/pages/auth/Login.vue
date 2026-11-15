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
        description: 'Sign in to continue to your Klinik Putrijaya workspace.',
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
        role="status"
        class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm font-medium text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
    >
        {{ status }}
    </div>

    <InputError :message="googleError" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    autocomplete="email"
                    inputmode="email"
                    class="h-11"
                    placeholder="name@kliniputrajaya.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    class="h-11"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" />
                    <span>Remember me</span>
                </Label>
                <TextLink
                    v-if="canResetPassword"
                    :href="request()"
                    class="text-sm"
                >
                    Forgot password?
                </TextLink>
            </div>

            <Button
                type="submit"
                class="mt-1 h-11 w-full"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Sign in
            </Button>
        </div>
    </Form>

    <div class="relative">
        <div class="absolute inset-0 flex items-center">
            <span class="w-full border-t" />
        </div>
        <div class="relative flex justify-center text-xs uppercase">
            <span class="bg-white px-3 text-muted-foreground dark:bg-slate-900"
                >or continue with</span
            >
        </div>
    </div>
    <a
        href="/auth/google/redirect"
        :aria-disabled="!googleEnabled"
        :class="[
            'flex h-11 w-full cursor-pointer items-center justify-center gap-3 rounded-md border border-input bg-white text-sm font-medium text-slate-800 shadow-xs transition-colors hover:border-slate-300 hover:bg-slate-50 focus-visible:ring-3 focus-visible:ring-ring/40 focus-visible:outline-none dark:bg-slate-950/40 dark:text-slate-100 dark:hover:bg-slate-800',
            !googleEnabled &&
                'pointer-events-none cursor-not-allowed opacity-50',
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
        KPOne access is limited to authorised Klinik Putrijaya staff.
    </p>
</template>
