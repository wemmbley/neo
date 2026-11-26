<table>
    <thead>
        <tr>
@foreach($el->head as $i => $cell)
            <th@if($el->align[$i]) style="text-align: {{ $el->align[$i] }}"@endif>{!! $cell !!}</th>
@endforeach
        </tr>
    </thead>
    <tbody>
@foreach($el->rows as $row)
        <tr>
@foreach($row as $i => $cell)
            <td@if($el->align[$i]) style="text-align: {{ $el->align[$i] }}"@endif>{!! $cell !!}</td>
@endforeach
        </tr>
@endforeach
    </tbody>
</table>
