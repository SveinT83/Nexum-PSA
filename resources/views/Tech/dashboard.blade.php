@extends('layouts.app')

@section('pageheader')
    <h1>Page Header</h1>
@endsection

@section('sidebar')
    <p>This is the sidebar content for the Tech dashboard.</p>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <h2>Tech Dashboard</h2>
            <p>Welcome to the Tech Dashboard!</p>
        </div>
    </div>
@endsection

@section('rightbar')
    <p>This is the right sidebar content for the Tech dashboard.</p>
@endsection