<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\Triage\TriageZoneRules;
use PHPUnit\Framework\TestCase;

/** TRI-1 section 5.1: fixed rules, no scoring, a blank reading is never normal. */
class TriageZoneRulesTest extends TestCase
{
    /** @return list<array{reading:string, age_band:string, direction:string, limit_value:string, zone:string}> */
    private function thresholds(): array
    {
        return [
            ['reading' => 'temperature_celsius', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '39.50', 'zone' => 'red'],
            ['reading' => 'temperature_celsius', 'age_band' => 'child', 'direction' => 'very_high', 'limit_value' => '38.50', 'zone' => 'red'],
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '120', 'zone' => 'yellow'],
            ['reading' => 'spo2_percent', 'age_band' => 'adult', 'direction' => 'very_low', 'limit_value' => '92.00', 'zone' => 'red'],
        ];
    }

    public function test_green_yellow_red_and_red_beats_yellow(): void
    {
        $normal = ['temperature_celsius' => '36.8', 'pulse_bpm' => 80, 'spo2_percent' => '98'];
        $this->assertSame('green', TriageZoneRules::suggest($normal, [], [], 'adult', $this->thresholds())['zone']);
        $this->assertSame('yellow', TriageZoneRules::suggest([...$normal, 'pulse_bpm' => 121], [], [], 'adult', $this->thresholds())['zone']);
        $this->assertSame('yellow', TriageZoneRules::suggest($normal, [], ['ys_01'], 'adult', $this->thresholds())['zone']);
        $this->assertSame('red', TriageZoneRules::suggest($normal, ['rf_01'], [], 'adult', $this->thresholds())['zone']);
        $this->assertSame('red', TriageZoneRules::suggest([...$normal, 'pulse_bpm' => 130, 'spo2_percent' => '91.5'], [], ['ys_01'], 'adult', $this->thresholds())['zone']);
    }

    public function test_limits_count_at_the_limit_and_highlight_the_direction(): void
    {
        $result = TriageZoneRules::suggest(['temperature_celsius' => '39.5', 'spo2_percent' => '92'], [], [], 'adult', $this->thresholds());
        $this->assertSame('red', $result['zone']);
        $this->assertSame(['spo2_percent' => 'very_low', 'temperature_celsius' => 'very_high'], $result['highlights']);
        $this->assertSame('green', TriageZoneRules::suggest(['temperature_celsius' => '39.49'], [], [], 'adult', $this->thresholds())['zone']);
    }

    public function test_blank_readings_are_missing_never_normal_or_crossing(): void
    {
        $result = TriageZoneRules::suggest(['temperature_celsius' => null, 'pulse_bpm' => ''], [], [], 'adult', $this->thresholds());
        $this->assertSame('green', $result['zone']);
        $this->assertSame([], $result['highlights']);
        $this->assertSame(['systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_celsius', 'spo2_percent', 'weight_kg', 'height_cm'], $result['missing']);
    }

    public function test_age_bands_and_unknown_age_takes_both(): void
    {
        $fever = ['temperature_celsius' => '38.6'];
        $this->assertSame('green', TriageZoneRules::suggest($fever, [], [], 'adult', $this->thresholds())['zone']);
        $this->assertSame('red', TriageZoneRules::suggest($fever, [], [], 'child', $this->thresholds())['zone']);
        $this->assertSame('red', TriageZoneRules::suggest($fever, [], [], 'unknown', $this->thresholds())['zone']);
        $this->assertSame('yellow', TriageZoneRules::suggest(['pulse_bpm' => 125], [], [], 'unknown', $this->thresholds())['zone']);
    }

    public function test_hundredths_are_exact(): void
    {
        $this->assertSame(3800, TriageZoneRules::hundredths('38'));
        $this->assertSame(3800, TriageZoneRules::hundredths('38.0'));
        $this->assertSame(3850, TriageZoneRules::hundredths('38.5'));
        $this->assertSame(3805, TriageZoneRules::hundredths('38.05'));
        $this->assertSame(12000, TriageZoneRules::hundredths(120));
        $this->expectException(\InvalidArgumentException::class);
        TriageZoneRules::hundredths('37.995');
    }
}
