<style>
@page{size:A4 portrait;margin:12mm}
html,body{margin:0;background:#eee;color:#18181b}
.ref-page{box-sizing:border-box;width:186mm;min-height:270mm;margin:12px auto;padding:12mm;background:#fff;color:#18181b;font:11pt/1.35 Arial,sans-serif;overflow-wrap:anywhere}
.ref-page header{border-bottom:1px solid #777;padding-bottom:6px;font-size:9pt;display:flex;gap:12px}
.ref-page img{max-width:55px;max-height:55px;object-fit:contain}
.ref-page h1{font-size:16pt;margin:8px 0}
.ref-draft{border:1px solid #555;font-weight:bold;font-size:9pt;padding:5px}
.ref-identity{font-size:10pt;margin:8px 0}
.ref-lines{font:11pt/1.35 monospace;white-space:pre-wrap;overflow-wrap:anywhere}
.ref-line{min-height:1.35em}.ref-line.heading{font-weight:bold}
.ref-sign{margin-top:12px;font-size:10pt;break-inside:avoid}.ref-foot{font-size:8pt;margin-top:12px}
@media(max-width:760px){.ref-page{width:100%;min-height:0;padding:16px}.ref-lines{white-space:pre-wrap}}
@media print{html,body{background:white!important;color:#18181b!important}.ref-page{width:100%;min-height:0;margin:0;padding:10mm 0 0;break-after:page;background:#fff!important;color:#18181b!important}.ref-page:last-child{break-after:auto}.ref-status{position:fixed;top:0;left:0;right:0;background:#fff!important;color:#18181b!important;font:9pt Arial,sans-serif}.ref-lines{white-space:pre}}
</style>
@foreach($snapshot['pages'] as $index => $lines)
<article class="ref-page" lang="{{ $snapshot['language'] === 'bm' ? 'ms' : 'en' }}">
    <header>
        @if($snapshot['letterhead']['logo'])<img src="{{ $snapshot['letterhead']['logo']['url'] }}" alt="Clinic logo">@endif
        <div><strong>{{ $snapshot['letterhead']['details']['clinic_name'] }}</strong><br>
        {{ implode(', ', array_filter(array_intersect_key($snapshot['letterhead']['details'], array_flip(['address_line_1','address_line_2','postcode','city','state','country'])))) }}<br>
        {{ implode(' / ', array_filter(array_intersect_key($snapshot['letterhead']['details'], array_flip(['phone','email'])))) }}</div>
    </header>
    <h1>{{ $snapshot['title'] }}</h1>
    <div class="ref-draft">DRAFT WORDING — awaiting doctor approval / DRAF — menunggu kelulusan doktor</div>
    <div class="ref-identity"><strong>{{ $snapshot['patient']['name'] }}</strong> · {{ $snapshot['patient']['number'] }}
        @if($snapshot['patient']['dateOfBirth']) · DOB / Tarikh lahir: {{ $snapshot['patient']['dateOfBirth'] }}@endif<br>
        {{ $snapshot['number'] }} · Visit / Lawatan: {{ $snapshot['visit'] }}<br>
        Examined / Diperiksa: {{ $snapshot['examDate'] }} · Issued / Dikeluarkan: {{ $snapshot['issueDate'] }}<br>
        COPY · Page / Halaman {{ $index + 1 }} / {{ count($snapshot['pages']) }}
    </div>
    <div class="ref-lines">@foreach($lines as $line)<div class="ref-line {{ $line['heading'] ? 'heading' : '' }}">{{ $line['text'] }}</div>@endforeach</div>
    <div class="ref-sign">________________________________<br>{{ $snapshot['doctor']['name'] }} · MMC {{ $snapshot['doctor']['mmc'] }}<br>Wet signature / Tandatangan basah</div>
    <div class="ref-foot">Pre-signature content only; not an archived signed original. Wet-sign paper and retain a signed duplicate.<br>UTC: {{ $snapshot['issuedAtUtc'] }} · {{ $snapshot['timezone'] }} · {{ strtoupper($snapshot['language']) }} revision {{ $snapshot['templateRevision'] }} · {{ $snapshot['rendererVersion'] }}</div>
</article>
@endforeach
