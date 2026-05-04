@props(['href'])

<a href="{{ $href }}" target="_blank" rel="noopener noreferrer" @native @click.prevent="$wire.openExternal(@js($href))" @endnative {{ $attributes }}>{{ $slot }}</a>
