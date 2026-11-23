<?php

namespace App\Http\Controllers;

use App\Domain\Agents\Connector\ConnectedApps;
use App\Domain\Agents\Connector\ConnectorSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** CN-4: the Connected apps page. Each person sees and disconnects only their own connections. */
class ConnectedAppsController extends Controller
{
    public function index(Request $request, ConnectedApps $apps): Response
    {
        return Inertia::render('Agents/ConnectedApps', [
            'apps' => $apps->for($request->user()),
            'questions' => $apps->questions(),
            'connectorEnabled' => ConnectorSwitch::enabled(),
        ]);
    }

    public function destroy(Request $request, ConnectedApps $apps, string $client): RedirectResponse
    {
        $ended = $apps->disconnect($request->user(), $client);

        Inertia::flash('toast', ['type' => 'success', 'message' => $ended > 0 ? 'Claude disconnected.' : 'Nothing was connected.']);

        return to_route('agents.connected-apps');
    }
}
