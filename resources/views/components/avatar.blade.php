@props(['user' => null, 'size' => 'md'])
{{-- Profile photo when the user uploaded one, otherwise coloured initials. Extra attributes (e.g. data-self) pass through. --}}
@php($url = $user?->avatar_url)
<span {{ $attributes->merge(['class' => 'avatar avatar-'.$size, 'title' => $user?->name]) }} style="background:{{ $user?->avatar_color ?? '#6c757d' }}" data-initials="{{ $user?->initials ?? '?' }}">@if ($url)<img src="{{ $url }}" alt="" loading="lazy" decoding="async">@else{{ $user?->initials ?? '?' }}@endif</span>
