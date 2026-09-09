<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Services\PublicCheckInLinkService;
use Inertia\Inertia;
use Inertia\Response;

class PublicCheckInController extends Controller
{
    public function __invoke(string $token, PublicCheckInLinkService $service): Response
    {
        $link = $service->resolve($token);

        return Inertia::render('PublicCheckIn/Show', [
            'clinicName' => 'Klinik Putrijaya',
            'branch' => ['name' => $link->branch->name],
        ]);
    }
}
