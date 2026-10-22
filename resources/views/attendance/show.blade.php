<!doctype html><html lang="{{ $snapshot['language'] === 'bm' ? 'ms' : 'en' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>{{ $snapshot['number'] }}</title></head><body>
<div class="ref-status">COPY — {{ $status }} · Retained pre-signature content. Browser Print / Save as PDF. @if($status !== 'CURRENT') NOT VALID — {{ $status }} @endif</div>
{{-- Only retained output of the fixed escaped server renderer, never input HTML. --}}
{!! $snapshot['html'] !!}
</body></html>
