@extends('layouts.default_tech')
@section('title', 'Confirmed workday')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">{{ $day['worker']['name'] }} · {{ $day['work_date'] }}</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.workdays.overview')" class="mb-0">Confirmed workdays</x-buttons.back></div>
@endsection
@section('content')
    {{-- This surface has no editing, draft, confirmation or absence actions. --}}
    <p><a href="{{ route('tech.workdays.overview.history', $day['id']) }}">Confirmed revision history</a></p>
    @include('workday::Tech.overview.snapshot', ['day' => $day])
@endsection
