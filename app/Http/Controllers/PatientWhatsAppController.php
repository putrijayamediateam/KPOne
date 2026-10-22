<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Services\PatientWhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PatientWhatsAppController extends Controller
{
    public function __invoke(Request $request, string $context, string $key, PatientWhatsAppService $contacts): RedirectResponse
    {
        return redirect()->away($contacts->destination($request->user(), $context, $key), 302);
    }
}
