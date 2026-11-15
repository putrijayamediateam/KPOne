<?php

namespace App\Domain\Organisation\Inventory\Services;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * STK-18: a purchase request rule refusal (the requester reviewing their own request, or someone else withdrawing it).
 * The page shows it as a message; a missing permission or branch assignment stays an ordinary 403.
 */
final class PurchaseRequestRefusal extends AuthorizationException {}
