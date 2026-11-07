@php
    // MC slice 1, renderer mc-a4-v2: the layout of the clinic's printed medical certificate (owner decision 2026-11-06).
    // Rendered once at issuance into the encrypted snapshot; every value is escaped.
    $bm = $snapshot['language'] === 'bm';
    $details = $snapshot['letterhead']['details'];
    $place = implode(', ', array_filter(array_intersect_key($details, array_flip(['postcode', 'city', 'state', 'country']))));
    // One phone style on every print (owner decision 2026-11-06): "Tel: 03-2123 4567", or "Tel: A / B" with a second phone.
    $tel = implode(' / ', array_filter([
        \App\Domain\Shared\PrintedPhone::format($details['phone'] ?? null),
        \App\Domain\Shared\PrintedPhone::format($details['phone_secondary'] ?? null),
    ]));
@endphp
<style>
.mc-sheet {color:#18181b;background:#fff;max-width:180mm;min-height:240mm;margin:auto;padding:12mm;font:11pt/1.6 Arial,sans-serif;overflow-wrap:break-word;box-sizing:border-box}
.mc-header {display:flex;gap:18px;align-items:center;border-bottom:1px solid #555;padding-bottom:14px;margin-bottom:18px}
.mc-logo {width:80px;height:80px;object-fit:contain;flex:none}
.mc-clinic {font-size:10pt;line-height:1.45}
.mc-clinic-name {display:block;font-size:13pt;font-weight:bold;text-transform:uppercase}
.mc-clinic-address {text-transform:uppercase}
.mc-draft {font-weight:bold;border:2px solid #555;padding:8px;text-align:center;margin-bottom:12px}
.mc-sheet h1 {font-size:20pt;font-weight:bold;text-align:center;margin:18px 0 14px;letter-spacing:.02em}
.mc-reference {text-align:right;font-size:10.5pt;line-height:1.6}
.mc-body {white-space:pre-wrap;line-height:2.1;margin:22px 0 10px}
.mc-u {text-decoration:underline}
.mc-reason {margin:6px 0}
.mc-closing {break-inside:avoid}
.mc-notice {text-align:center;font-size:9pt;letter-spacing:.06em;margin:34px 0 0;break-after:avoid}
.mc-signature {margin:25mm 0 0 auto;break-before:avoid;width:max-content;max-width:100%;text-align:center;break-inside:avoid}
.mc-signature-line {border-top:1px solid #18181b;padding-top:6px;min-width:70mm}
.mc-evidence {font-size:9pt;margin-top:35px;border-top:1px solid #aaa;padding-top:8px}
@media(max-width:600px){.mc-sheet{padding:18px;min-height:0}.mc-header{flex-wrap:wrap}.mc-logo{width:60px;height:60px}.mc-signature-line{min-width:0}}
@page {size:A4 portrait;margin:15mm}
@media print {html,body{background:#fff!important;color:#18181b!important;margin:0}.mc-sheet{padding:0;max-width:none;min-height:0;color:#18181b!important;background:#fff!important;print-color-adjust:exact}.mc-draft{color:#18181b!important}.mc-header{break-inside:avoid}}
</style>
<article class="mc-sheet" lang="{{ $bm ? 'ms' : 'en' }}">
    <header class="mc-header">
        @if($snapshot['letterhead']['logo'])
            <img class="mc-logo" src="{{ $snapshot['letterhead']['logo']['url'] }}" alt="Clinic logo">
        @endif
        <div class="mc-clinic">
            <strong class="mc-clinic-name">{{ $details['clinic_name'] }}</strong>
            <span class="mc-clinic-address">
                @foreach(['address_line_1', 'address_line_2'] as $key)
                    @if($details[$key]){{ $details[$key] }}<br>@endif
                @endforeach
                @if($place !== ''){{ $place }}<br>@endif
            </span>
            @if($details['email'])Email: {{ $details['email'] }}<br>@endif
            @if($tel !== '')Tel: {{ $tel }}@endif
        </div>
    </header>
    <div class="mc-draft">DRAFT WORDING — awaiting doctor approval / DRAF — menunggu kelulusan doktor</div>
    <h1>{{ $snapshot['title'] }}</h1>
    <div class="mc-reference"><strong>{{ $bm ? 'No. MC:' : 'MC No:' }}</strong> {{ $snapshot['fields']['mc_number'] }}<br><strong>{{ $bm ? 'Tarikh:' : 'Date:' }}</strong> {{ $snapshot['fields']['issue_date'] }}</div>
    {{-- Each piece is escaped with e(); only the fixed <strong> and class wrappers are markup. --}}
    <p class="mc-body">{!! collect($snapshot['wordingParts'])->map(fn (array $part): string => match ($part['style']) {
        'strong-u' => '<strong class="mc-u">'.e($part['text']).'</strong>',
        'strong' => '<strong>'.e($part['text']).'</strong>',
        default => e($part['text']),
    })->implode('') !!}</p>
    @if($snapshot['reason'])<p class="mc-reason">{{ $bm ? 'Sebab (dengan persetujuan pesakit):' : 'Reason (with the patient\'s consent):' }} {{ $snapshot['reason'] }}</p>@endif
    <div class="mc-closing">
    <p class="mc-notice">{{ $bm ? '(TIDAK SAH DI MAHKAMAH)' : '(NOT VALID IN THE COURT OF LAW)' }}</p>
    <div class="mc-signature"><div class="mc-signature-line">{{ $snapshot['fields']['doctor_name'] }} (MMC No: {{ $snapshot['fields']['mmc_number'] }})</div></div>
    <p class="mc-evidence">Pre-signature content snapshot only. Not an archived signed original. Wet-sign the paper and retain a signed duplicate.<br>Rekod kandungan sebelum tandatangan sahaja. Bukan salinan asal bertandatangan.<br>Issued at: {{ $snapshot['issuedAtLocal'] ?? $snapshot['issuedAtUtc'] }}@isset($snapshot['issuedAtZone']) ({{ $snapshot['issuedAtZone'] }})@endisset · {{ strtoupper($snapshot['language']) }} revision {{ $snapshot['templateRevision'] }} · {{ $snapshot['rendererVersion'] }}</p>
    </div>
</article>
