@extends('layouts.default_tech')
@section('title', isset($reloadUrl) ? 'Absence changed' : 'Workday changed')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">{{ isset($reloadUrl) ? 'Absence changed' : 'Workday changed' }}</h1></div>
@endsection
@section('content')
    {{-- A stale browser form must never replace newer API or browser work. --}}
    <div class="alert alert-warning">{{ $message }}</div>
    <p>Your submitted changes were not saved. Reload the latest record and review it before trying again.</p>
    <x-buttons.back :url="$reloadUrl ?? route('tech.workdays.index')">{{ isset($reloadUrl) ? 'Reload absences' : 'Reload workdays' }}</x-buttons.back>
@endsection
