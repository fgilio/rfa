@props(['href'])

<a href="{{ $href }}" target="_blank" rel="noopener noreferrer" @native @click.prevent="$wire.openExternal('{{ $href }}')" @endnative {{ $attributes }}>{{ $slot }}</a>
