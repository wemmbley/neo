<a href="{{ $el->url }}"@if($el->title) title="{{ $el->title }}"@endif@if($el->external) rel="noopener noreferrer"@endif>{!! $el->content !!}</a>
