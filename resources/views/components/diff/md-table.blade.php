{{-- Renders one markdown table row as a CSS grid so each cell wraps within its
     own column. Every row in a group shares the same `template`, so columns line
     up across rows regardless of cell length. The separator row (`| --- |`)
     becomes a thin header rule. --}}
@props(['table'])

@if($table['separator'])
    <div class="diff-md-sep" aria-hidden="true"></div>
@else
    <div class="diff-md-table" style="grid-template-columns:{{ $table['template'] }};max-width:{{ $table['maxWidth'] }}ch">
        @foreach($table['cells'] as $cell)<div @class(['diff-md-td', 'diff-md-th' => $table['header']])>{{ $cell }}</div>@endforeach
    </div>
@endif
