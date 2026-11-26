<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\PanelClaimMessageService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Services\PanelSettingsService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PanelSettingsController extends Controller
{
    public function index(Request $request, PanelSettingsService $panels): Response
    {
        return Inertia::render('PanelSettings/Index', $panels->index($this->actor($request), [
            'tab' => $request->query('tab'),
            'q' => $request->query('q'),
        ]));
    }

    public function create(Request $request, PanelSettingsService $panels): Response
    {
        return Inertia::render('PanelSettings/Form', $panels->form($this->actor($request), null));
    }

    public function edit(Request $request, Panel $panel, PanelSettingsService $panels): Response
    {
        return Inertia::render('PanelSettings/Form', $panels->form($this->actor($request), $panel));
    }

    public function store(Request $request, PanelSettingsService $panels): RedirectResponse
    {
        try {
            $panels->create($this->actor($request), $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('panel-settings.create'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Panel added.']);

        return to_route('panel-settings.index');
    }

    public function update(Request $request, Panel $panel, PanelSettingsService $panels): RedirectResponse
    {
        try {
            $panels->update($this->actor($request), $panel, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('panel-settings.edit', $panel->id));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Panel saved.']);

        return to_route('panel-settings.index');
    }

    public function active(Request $request, Panel $panel, PanelSettingsService $panels): RedirectResponse
    {
        try {
            $updated = $panels->setActive($this->actor($request), $panel, $request->boolean('active'), $request->input('lock_version'));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('panel-settings.index'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $updated->is_active ? 'Panel restored.' : 'Panel archived.']);

        return to_route('panel-settings.index');
    }

    public function claimMessage(Request $request, PanelClaimMessageService $messages): RedirectResponse
    {
        try {
            $messages->save($this->actor($request), $request->input('body'), $request->input('lock_version'));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('panel-settings.index'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Claim message saved.']);

        return to_route('panel-settings.index');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
