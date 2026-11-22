<?php

namespace App\Http\Controllers;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Approvals\ApprovalInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** APPR-1: the number for the account button and menus. Read only; the TV account gets 403. */
final class ApprovalsController extends Controller
{
    public function count(Request $request, ApprovalInbox $inbox): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasRole(PermissionCatalogue::QUEUE_DISPLAY_ROLE), 403);

        return response()->json($inbox->summary($user));
    }
}
