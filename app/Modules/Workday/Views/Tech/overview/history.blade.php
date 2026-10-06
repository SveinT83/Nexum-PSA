@extends('layouts.default_tech')
@section('title', 'Confirmed revision history')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Confirmed revision history</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.workdays.overview.show', $id)" class="mb-0">Confirmed workday</x-buttons.back></div>
@endsection
@section('content')
    {{-- Each row is an immutable employee confirmation; private revision states are excluded by the query. --}}
    @foreach($result['data'] as $day)
        @include('workday::Tech.overview.snapshot', ['day' => $day])
    @endforeach
    @include('workday::Tech.overview.pagination', ['meta' => $result['meta']])
@endsection
