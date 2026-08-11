@props([
    'label',
    'column',
    'currentSort' => null,
    'currentDirection' => 'asc',
    'query' => [],
    'sortParameter' => 'sort',
    'directionParameter' => 'direction',
    'align' => 'start',
    'fragment' => null,
    'defaultDirection' => 'asc',
])

@php
    // The caller owns the SQL allowlist; this component only builds a safe navigation state.
    $normalizedDirection = $currentDirection === 'desc' ? 'desc' : 'asc';
    $normalizedDefaultDirection = $defaultDirection === 'desc' ? 'desc' : 'asc';
    $isActive = $currentSort === $column;
    $nextDirection = $isActive
        ? ($normalizedDirection === 'asc' ? 'desc' : 'asc')
        : $normalizedDefaultDirection;
    $nextDirectionLabel = $nextDirection === 'desc' ? 'descending' : 'ascending';
    $sortQuery = collect($query)
        ->except(['page', $sortParameter, $directionParameter])
        ->reject(fn ($value): bool => $value === null || $value === '')
        ->all();
    $sortQuery[$sortParameter] = $column;
    $sortQuery[$directionParameter] = $nextDirection;
    $sortUrl = request()->url().'?'.http_build_query($sortQuery, '', '&', PHP_QUERY_RFC3986);

    if ($fragment !== null && $fragment !== '') {
        $sortUrl .= '#'.rawurlencode(ltrim((string) $fragment, '#'));
    }

    $linkClasses = 'text-decoration-none text-body d-inline-flex align-items-center gap-1';

    if ($align === 'end') {
        $linkClasses .= ' justify-content-end w-100';
    }

    $sortIcon = ! $isActive
        ? 'bi-arrow-down-up'
        : ($normalizedDirection === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill');
@endphp

<th
    scope="col"
    {{ $attributes->class(['text-end' => $align === 'end']) }}
    @if($isActive) aria-sort="{{ $normalizedDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a
        href="{{ $sortUrl }}"
        class="{{ $linkClasses }}"
        aria-label="Sort by {{ $label }} {{ $nextDirectionLabel }}"
        title="Sort by {{ $label }} {{ $nextDirectionLabel }}">
        {{ $label }}
        <i class="bi {{ $sortIcon }}" aria-hidden="true"></i>
    </a>
</th>
