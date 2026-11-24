<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-DEFAULTS (owner's draft defaults, 2026-11-15): a leave type or entitlement row that KPOne's "Use KPOne defaults"
 * wrote carries a mark, so the screen can say "Set by KPOne defaults at the owner's instruction; not professional
 * advice". A type HR later changes loses the mark; an entitlement HR enters later is its own row without it.
 * Adding a column with a constant default fires no row trigger, so the append-only entitlement guard is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $t): void {
            $t->boolean('set_by_defaults')->default(false);
        });
        Schema::table('hr_leave_entitlements', function (Blueprint $t): void {
            $t->boolean('set_by_defaults')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_entitlements', function (Blueprint $t): void {
            $t->dropColumn('set_by_defaults');
        });
        Schema::table('hr_leave_types', function (Blueprint $t): void {
            $t->dropColumn('set_by_defaults');
        });
    }
};
