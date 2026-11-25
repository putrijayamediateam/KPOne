<?php

namespace Tests\Feature\Clinical;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** REL-PR: the deliberate release of approved wording (owner-confirmed, 25 Nov 2026). */
class ApprovedWordingReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_document_keeps_its_unapproved_history_and_adds_one_approved_revision_with_a_record(): void
    {
        foreach (['mc_template_revisions' => 3, 'referral_template_revisions' => 2, 'attendance_template_revisions' => 2] as $table => $newest) {
            $this->assertSame($newest, (int) DB::table($table)->max('revision'), $table);
            $approved = DB::table($table)->where('production_approved', true)->get();
            $languages = DB::table($table)->distinct()->count('language');
            $this->assertCount($languages, $approved, $table);
            foreach ($approved as $row) {
                $this->assertSame($newest, (int) $row->revision);
                $this->assertNotEmpty($row->approved_by_label);
                $this->assertNotNull($row->approved_on);
            }
            $this->assertSame(0, DB::table($table)->where('revision', '<', $newest)->where('production_approved', true)->count());
        }
    }

    public function test_template_rows_cannot_be_changed_or_removed(): void
    {
        foreach (['mc_template_revisions', 'referral_template_revisions', 'attendance_template_revisions'] as $table) {
            $id = DB::table($table)->where('production_approved', true)->value('id');
            foreach ([
                fn () => DB::table($table)->where('id', $id)->update(['production_approved' => false]),
                fn () => DB::table($table)->where('id', $id)->delete(),
            ] as $attempt) {
                try {
                    DB::transaction($attempt);
                    $this->fail("{$table} row was changed");
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_an_approved_row_without_an_approval_record_is_refused(): void
    {
        foreach (['referral_template_revisions', 'attendance_template_revisions'] as $table) {
            $row = (array) DB::table($table)->where('production_approved', true)->first();
            unset($row['id']);
            $row['revision'] = 3;
            $row['approved_by_label'] = null;
            $row['approved_on'] = null;
            try {
                DB::transaction(fn () => DB::table($table)->insert($row));
                $this->fail("{$table} accepted approval without a record");
            } catch (QueryException $e) {
                $this->assertStringContainsString('_template_shape', $e->getMessage());
            }
        }
    }

    public function test_new_qr_sessions_use_the_approved_privacy_notice_version(): void
    {
        $this->assertSame('approved-2026-11-25-v1', config('public-intake.privacy_notice_version'));
    }
}
