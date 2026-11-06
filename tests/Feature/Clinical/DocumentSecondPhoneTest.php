<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Documents\DocumentLetterheadCapture;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

/**
 * CAT-9 (B.E.R.N.I.S.S for the owner, 2026-11-06): referral and attendance copies print the second clinic phone
 * as "Tel: A / B"; a snapshot without the key (every one issued before CAT-9) keeps the phone / email shape. CAT-13
 * (B.E.R.N.I.S.S for the owner): phones print through PrintedPhone; issued letters keep the HTML stored at issue.
 */
class DocumentSecondPhoneTest extends VisitTestCase
{
    /** @param array<string, mixed> $details */
    private function snapshot(array $details): array
    {
        return [
            'pages' => [[['heading' => false, 'text' => 'Synthetic line']]], 'language' => 'en', 'title' => 'Synthetic title',
            'letterhead' => ['logo' => null, 'details' => [
                'clinic_name' => 'Synthetic Clinic', 'address_line_1' => '1 Example Street', 'address_line_2' => null,
                'postcode' => '50000', 'city' => 'Example City', 'state' => 'Example State', 'country' => 'Malaysia', ...$details,
            ]],
            'patient' => ['name' => 'Synthetic Patient', 'number' => 'KP-000001', 'dateOfBirth' => null],
            'number' => 'REF/KP/CHR/2026/000001', 'visit' => 'KPV-00000001', 'examDate' => '06/11/2026', 'issueDate' => '06/11/2026',
            'doctor' => ['name' => 'Dr Synthetic', 'mmc' => '00000'], 'issuedAtUtc' => '2026-11-06T00:00:00Z',
            'timezone' => 'Asia/Kuala_Lumpur', 'templateRevision' => 1, 'rendererVersion' => 'v2',
        ];
    }

    private function contactLine(string $html): string
    {
        preg_match('/<br>\s*([^<]*)<\/div>\s*<\/header>/', $html, $m);

        return trim(html_entity_decode($m[1] ?? '', ENT_QUOTES));
    }

    public function test_a_snapshot_without_the_key_keeps_the_old_phone_and_email_line(): void
    {
        foreach (['referrals.content', 'attendance.content'] as $view) {
            $html = view($view, ['snapshot' => $this->snapshot(['phone' => '+60320000001', 'email' => 'clinic@doc.test'])])->render();
            $this->assertSame('03-2000 0001 / clinic@doc.test', $this->contactLine($html), $view);
            $this->assertStringNotContainsString('Tel:', $html, $view);

            // An explicit null renders the same as a missing key.
            $same = view($view, ['snapshot' => $this->snapshot(['phone' => '+60320000001', 'phone_secondary' => null, 'email' => 'clinic@doc.test'])])->render();
            $this->assertSame($html, $same, $view);
        }
    }

    public function test_a_second_phone_prints_as_tel_a_slash_b_with_the_email_after_it(): void
    {
        foreach (['referrals.content', 'attendance.content'] as $view) {
            $html = view($view, ['snapshot' => $this->snapshot(['phone' => '+60320000001', 'phone_secondary' => '+60123456789', 'email' => 'clinic@doc.test'])])->render();
            $this->assertSame('Tel: 03-2000 0001 / 012-345 6789 · clinic@doc.test', $this->contactLine($html), $view);

            $noEmail = view($view, ['snapshot' => $this->snapshot(['phone' => '+60320000001', 'phone_secondary' => '+60123456789', 'email' => null])])->render();
            $this->assertSame('Tel: 03-2000 0001 / 012-345 6789', $this->contactLine($noEmail), $view);
        }
    }

    public function test_a_second_phone_with_no_first_prints_tel_b_with_no_empty_part(): void
    {
        foreach (['referrals.content', 'attendance.content'] as $view) {
            $html = view($view, ['snapshot' => $this->snapshot(['phone' => null, 'phone_secondary' => '+60123456789', 'email' => 'clinic@doc.test'])])->render();
            $this->assertSame('Tel: 012-345 6789 · clinic@doc.test', $this->contactLine($html), $view);
        }
    }

    public function test_new_document_snapshots_capture_the_second_phone(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        app(BusinessDetailsService::class)->save($director, [
            'expected_branch_id' => $this->branch->id, 'organisation_lock_version' => (int) OrganisationBusinessProfile::query()->value('lock_version'),
            'branch_lock_version' => (int) DB::table('branch_business_profiles')->where('branch_id', $this->branch->id)->value('lock_version'),
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Clinic',
            'address_line_1' => '1 Example Street', 'postcode' => '50000', 'city' => 'Example City', 'state' => 'Example State',
            'country' => 'MY', 'phone' => '03-2000 0001', 'phone_secondary' => '012-345 6789', 'email' => 'clinic@doc.test',
            'registration_type' => 'BRN', 'registration_number' => 'SYN-1',
        ], null);

        $captured = DB::transaction(fn () => app(DocumentLetterheadCapture::class)->capture(Branch::query()->findOrFail($this->branch->id)));

        $this->assertSame('+60320000001', $captured['details']['phone']);
        $this->assertSame('+60123456789', $captured['details']['phone_secondary']);
    }
}
