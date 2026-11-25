<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Assignment\AutoAssignSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AutoAssignSettingController extends Controller
{
    public function update(Request $request, AutoAssignSettingService $settings): RedirectResponse
    {
        try {
            $settings->save($request->user(), $request->all());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('registration-settings.index'));
        }

        return to_route('registration-settings.index')->with('success', 'Automatic doctor assignment saved.');
    }
}
