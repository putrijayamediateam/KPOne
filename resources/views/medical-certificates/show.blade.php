<!doctype html>
<html lang="{{ $snapshot['language'] === 'bm' ? 'ms' : 'en' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>{{ $snapshot['fields']['mc_number'] }}</title>
<style>body{margin:0;background:#eee;color:#18181b} .copy-status{max-width:180mm;margin:12px auto;padding:8px;background:white;color:#18181b;border:2px solid #555;font:14px Arial,sans-serif;box-sizing:border-box}@media print{.copy-status{margin:0 0 12px;padding:8px;max-width:none}}</style></head><body>
<div class="copy-status">COPY — {{ $status }} · Copy of retained pre-signature content; use browser Print / Save as PDF. @if($status !== 'CURRENT') NOT VALID — {{ $status }} @endif</div>
{{-- Generated only by the fixed escaped server Blade renderer at issuance, never user HTML. --}}
{!! $snapshot['html'] !!}
</body></html>
