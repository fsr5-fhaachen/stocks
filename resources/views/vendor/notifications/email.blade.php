{{-- every notification mail of Stocks, in German; frame: App\Support\Notifications\MailLayout --}}
<x-mail::message :layout="$layout ?? null">
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
# Hallo!
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Viele Grüße<br>
{{ app(\App\Support\Settings::class)->get('organization.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Falls der Knopf „{{ $actionText }}“ nicht geht, kopiere diese Adresse in den Browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
