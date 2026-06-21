{{-- Renders one markdown table row as a CSS grid so each cell wraps within its
     own column. Every row in a group shares the same `template`, so columns line
     up across rows regardless of cell length.

     An unchanged separator (`| --- |`) collapses to a thin header rule. A
     separator that is part of the diff carries its own cells, so the changed
     alignment markers stay visible (muted) on the same column grid instead of
     flattening to two indistinguishable rules. --}}
@props(['table'])

@if($table['separator'] && ! isset($table['cells']))
    <div class="diff-md-sep" aria-hidden="true"></div>
@else
    <div class="diff-md-table" style="grid-template-columns:{{ $table['template'] }};max-width:{{ $table['maxWidth'] }}ch">
        @foreach($table['cells'] as $cell)<div @class(['diff-md-td', 'diff-md-th' => $table['header'] ?? false, 'diff-md-sep-cell' => $table['separator']])>{{ $cell }}</div>@endforeach
    </div>
@endif
