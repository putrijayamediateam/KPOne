<?php

use App\Domain\Audit\AuditRecorder;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use App\Http\Middleware\RequireAnyPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureActiveUser::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permission' => RequireAnyPermission::class,
            'sensitive.no-store' => PreventSensitiveResponseCaching::class,
        ]);

        $middleware->prependToPriorityList(SubstituteBindings::class, RequireAnyPermission::class);
        $middleware->prependToPriorityList(RequireAnyPermission::class, EnsureActiveUser::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'current_password',
            'patient_query',
            'query',
            'value',
            'identifiers',
            'full_name',
            'date_of_birth',
            'sex',
            'nationality_code',
            'mobile_phone',
            'email',
            'address_line_1',
            'address_line_2',
            'postcode',
            'city',
            'state',
            'country_code',
            'visit_reason',
            'cancellation_reason',
            'coverage_member_reference',
            'patient_number',
            'quick_patient',
            'queue_query',
            'clinical_note',
            'vitals',
            'diagnoses',
            'diagnosis_text',
            'diagnosis_code',
            'code_system',
            'allergies',
            'allergy_profile',
            'allergen_text',
            'category',
            'reaction_text',
            'severity',
            'no_known_allergies',
            'problems',
            'condition_text',
            'condition_code',
            'onset_date',
            'resolved_date',
        ]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
            ], $request->user());

            return null;
        });
    })->create();
