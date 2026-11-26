<table>
    <thead><tr><th>#</th><th>Title</th><th>Score</th></tr></thead>
    <tbody>
        @foreach ($rows as $row)
            <tr class="{{ $loop->even ? 'even' : 'odd' }}">
                <td>{{ $row['id'] }}</td>
                <td>{{ $row['title'] }}</td>
                <td>@if ($row['score'] > 75) <strong>{{ $row['score'] }}</strong> @else {{ $row['score'] }} @endif</td>
            </tr>
        @endforeach
    </tbody>
</table>
