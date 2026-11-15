<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Services\RegistrationSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RegistrationSettingsController extends Controller
{
    public function index(Request $request, RegistrationSettingsService $settings): Response
    {
        return Inertia::render('RegistrationSettings/Index', $settings->index($request->user()));
    }

    public function store(Request $request, RegistrationSettingsService $settings): RedirectResponse
    {
        try {
            $settings->save($request->user(), $request->all());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('registration-settings.index'));
        }

        return to_route('registration-settings.index')->with('success', 'Registration settings saved.');
    }
}
