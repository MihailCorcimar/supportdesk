@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
@if (trim(strip_tags($slot)) === 'SuportDesk' || trim(strip_tags($slot)) === config('app.name'))
<span style="display:inline-flex;align-items:center;gap:10px;color:#0f172a;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:700;line-height:1;">
<span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:#0f766e;color:#ffffff;font-size:16px;font-weight:800;">SD</span>
<span>{{ config('app.name') }}</span>
</span>
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
