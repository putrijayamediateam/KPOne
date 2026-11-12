<?php

namespace App\Domain\Shared;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A page to return to after a small action started from any screen (DS-12: the clock shortcut). Only an internal path
 * that is a real GET page of this application is accepted: a plain path and optional query, no host, no scheme, no
 * "//", no backslash. Anything else is null, so the caller keeps its own fixed destination. Never `back()`.
 */
final class SafeReturnPath
{
    public static function from(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 200) {
            return null;
        }
        if (preg_match('#\A/(?!/)[A-Za-z0-9_\-./]*(\?[A-Za-z0-9_\-.=&%,]*)?\z#', $value) !== 1) {
            return null;
        }
        try {
            Route::getRoutes()->match(Request::create($value, 'GET'));
        } catch (HttpException) {
            return null;
        }

        return $value;
    }
}
