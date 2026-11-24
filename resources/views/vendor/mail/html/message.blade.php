@props(['layout' => null])
{{-- frame of every mail, see App\Support\Notifications\MailLayout --}}
@php
    $layout ??= \App\Support\Notifications\MailLayout::frame();
@endphp
<x-mail::layout>
{{-- Header: the print logo or the logo for light backgrounds, else the name --}}
<x-slot:header>
<x-mail::header :url="$layout['url']">
@if ($layout['logo'])
<img src="{{ $layout['logo'] }}" class="logo" alt="{{ $layout['name'] }}">
@else
{{ $layout['name'] }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: organization, how to reach it, where the settings are --}}
<x-slot:footer>
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
<p>{{ $layout['name'] }}@if ($layout['contact']) · <a href="mailto:{{ $layout['contact'] }}">{{ $layout['contact'] }}</a>@endif @if ($layout['phone']) · {{ $layout['phone'] }}@endif</p>
@if ($layout['settings'])
<p><a href="{{ $layout['settings']['url'] }}">{{ $layout['settings']['text'] }}</a></p>
@endif
</td>
</tr>
</table>
</td>
</tr>
</x-slot:footer>
</x-mail::layout>
