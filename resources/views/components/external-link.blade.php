@props(['href'])

<a href="{{ $href }}" target="_blank" rel="noopener" @native @click.prevent="$wire.openExternal('{{ $href }}')" @endnative {{ $attributes }}>{{ $slot }}</a>
