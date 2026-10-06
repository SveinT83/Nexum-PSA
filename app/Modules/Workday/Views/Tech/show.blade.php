@extends('layouts.default_tech')
@section('title', $day['id'] ? 'Workday '.$day['work_date'] : 'My workdays')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">{{ $day['id'] ? 'Workday '.$day['work_date'] : 'My workdays' }}</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.workdays.index')" class="mb-0">My workdays</x-buttons.back></div>
@endsection
@section('content')
    @include('workday::Tech.date-navigation')
    @include('workday::Tech.day-content')
@endsection
