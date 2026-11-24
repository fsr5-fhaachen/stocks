@props(['layout' => null])
@php
    $layout ??= \App\Support\Notifications\MailLayout::frame();
@endphp
{{ $layout['name'] }}

{{ $slot }}
@isset($subcopy)

{{ $subcopy }}
@endisset

--
{{ $layout['name'] }}@if ($layout['contact']) · {{ $layout['contact'] }}@endif @if ($layout['phone']) · {{ $layout['phone'] }}@endif

@if ($layout['settings'])
{{ $layout['settings']['text'] }}: {{ $layout['settings']['url'] }}
@endif
