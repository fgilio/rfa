@props([
    'variant' => 'outline',
])

@php
$classes = Flux::classes('shrink-0')
    ->add(match($variant) {
        'outline' => '[:where(&)]:size-6',
        'solid' => '[:where(&)]:size-6',
        'mini' => '[:where(&)]:size-5',
        'micro' => '[:where(&)]:size-4',
    });
@endphp

<?php switch ($variant): case ('outline'): ?>
<svg {{ $attributes->class($classes) }} data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M8 4l4 4 4-4"/>
  <path stroke-linecap="round" stroke-linejoin="round" d="M8 20l4-4 4 4"/>
</svg>

        <?php break; ?>

    <?php case ('solid'): ?>
<svg {{ $attributes->class($classes) }} data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-slot="icon">
  <path fill-rule="evenodd" d="M11.47 8.53a.75.75 0 0 0 1.06 0l4-4a.75.75 0 1 0-1.06-1.06L12 6.94 8.53 3.47a.75.75 0 0 0-1.06 1.06l4 4ZM12.53 15.47a.75.75 0 0 0-1.06 0l-4 4a.75.75 0 1 0 1.06 1.06L12 17.06l3.47 3.47a.75.75 0 1 0 1.06-1.06l-4-4Z" clip-rule="evenodd"/>
</svg>

        <?php break; ?>

    <?php case ('mini'): ?>
<svg {{ $attributes->class($classes) }} data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
  <path fill-rule="evenodd" d="M9.47 7.28a.75.75 0 0 0 1.06 0l3.25-3.25a.75.75 0 1 0-1.06-1.06L10 5.69 7.28 2.97a.75.75 0 0 0-1.06 1.06l3.25 3.25ZM10.53 12.72a.75.75 0 0 0-1.06 0l-3.25 3.25a.75.75 0 1 0 1.06 1.06L10 14.31l2.72 2.72a.75.75 0 1 0 1.06-1.06l-3.25-3.25Z" clip-rule="evenodd"/>
</svg>

        <?php break; ?>

    <?php case ('micro'): ?>
<svg {{ $attributes->class($classes) }} data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon">
  <path fill-rule="evenodd" d="M7.47 6.28a.75.75 0 0 0 1.06 0l2.75-2.75a.75.75 0 1 0-1.06-1.06L8 4.69 5.78 2.47a.75.75 0 0 0-1.06 1.06l2.75 2.75ZM8.53 9.72a.75.75 0 0 0-1.06 0l-2.75 2.75a.75.75 0 1 0 1.06 1.06L8 11.31l2.22 2.22a.75.75 0 1 0 1.06-1.06l-2.75-2.75Z" clip-rule="evenodd"/>
</svg>

        <?php break; ?>

<?php endswitch; ?>
