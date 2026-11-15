<style>
.mc-sheet {color:#18181b;background:#fff;max-width:180mm;min-height:240mm;margin:auto;padding:12mm;font:11pt/1.65 Arial,sans-serif;overflow-wrap:anywhere;box-sizing:border-box}
.mc-header {display:flex;gap:16px;align-items:flex-start;border-bottom:1px solid #555;padding-bottom:16px;margin-bottom:28px}
.mc-logo {max-width:90px;max-height:75px;object-fit:contain}
.mc-sheet h1 {font-size:18pt;text-align:center;margin:22px 0}
.mc-sheet p {white-space:pre-wrap}
.mc-draft {font-weight:bold;border:2px solid #555;padding:8px;text-align:center}
.mc-reference {text-align:right;font-size:10pt}
.mc-signature {margin-top:65px;break-inside:avoid}
.mc-evidence {font-size:9pt;margin-top:35px;border-top:1px solid #aaa;padding-top:8px}
@media(max-width:600px){.mc-sheet{padding:18px;min-height:0}.mc-header{flex-wrap:wrap}.mc-logo{max-width:65px}}
@page {size:A4 portrait;margin:12mm}
@media print {html,body{background:#fff!important;color:#18181b!important;margin:0}.mc-sheet{padding:0;max-width:none;min-height:0;color:#18181b!important;background:#fff!important;print-color-adjust:exact}.mc-draft{color:#18181b!important}.mc-header{break-inside:avoid}}
</style>
<article class="mc-sheet" lang="{{ $snapshot['language'] === 'bm' ? 'ms' : 'en' }}">
    <header class="mc-header">
        @if($snapshot['letterhead']['logo'])
            <img class="mc-logo" src="{{ $snapshot['letterhead']['logo']['url'] }}" alt="Clinic logo">
        @endif
        <div>
            <strong>{{ $snapshot['letterhead']['details']['clinic_name'] }}</strong><br>
            @foreach(['address_line_1','address_line_2'] as $key)
                @if($snapshot['letterhead']['details'][$key]){{ $snapshot['letterhead']['details'][$key] }}<br>@endif
            @endforeach
            {{ implode(', ', array_filter(array_intersect_key($snapshot['letterhead']['details'], array_flip(['postcode','city','state','country'])))) }}<br>
            @if($snapshot['letterhead']['details']['phone']){{ $snapshot['letterhead']['details']['phone'] }}<br>@endif
            @if($snapshot['letterhead']['details']['email']){{ $snapshot['letterhead']['details']['email'] }}@endif
        </div>
    </header>
    <div class="mc-draft">DRAFT WORDING — awaiting doctor approval / DRAF — menunggu kelulusan doktor</div>
    <h1>{{ $snapshot['title'] }}</h1>
    <div class="mc-reference">MC No: {{ $snapshot['fields']['mc_number'] }}<br>Date / Tarikh: {{ $snapshot['fields']['issue_date'] }}</div>
    <p>{{ $snapshot['wording'] }}</p>
    @if($snapshot['reason'])<p>Reason / Sebab (with consent / dengan persetujuan): {{ $snapshot['reason'] }}</p>@endif
    <div class="mc-signature">________________________________<br>{{ $snapshot['fields']['doctor_name'] }}<br>MMC: {{ $snapshot['fields']['mmc_number'] }}<br>Wet signature / Tandatangan basah</div>
    <p class="mc-evidence">Pre-signature content snapshot only. Not an archived signed original. Wet-sign the paper and retain a signed duplicate.<br>Rekod kandungan sebelum tandatangan sahaja. Bukan salinan asal bertandatangan.<br>Issued at (UTC): {{ $snapshot['issuedAtUtc'] }} · {{ strtoupper($snapshot['language']) }} revision {{ $snapshot['templateRevision'] }} · {{ $snapshot['rendererVersion'] }}</p>
</article>
