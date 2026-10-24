{{-- Month switcher. Expects $report; keeps other query parameters. --}}
<div class="months">
    <a href="{{ request()->fullUrlWithQuery(['month' => $report->start->subMonth()->format('Y-m'), 'page' => null]) }}">&larr; {{ $report->start->subMonth()->format('M Y') }}</a>
    <strong>{{ $report->start->format('F Y') }}</strong>
    @if ($report->end->isPast())
        <a href="{{ request()->fullUrlWithQuery(['month' => $report->end->format('Y-m'), 'page' => null]) }}">{{ $report->end->format('M Y') }} &rarr;</a>
    @endif
</div>
