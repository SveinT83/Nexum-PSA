@extends('layouts.default_tech')

@section('title', 'Mail')

@section('pageHeader')
    <h1>Mail</h1>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Email Mail Workspace -->
    <!-- ------------------------------------------------- -->
    @livewire('tech.mail.workspace')
@endsection

@section('sidebar')
    <x-nav.work-menu />
    @livewire('tech.mail.sidebar')
@endsection

@section('rightbar_after_context_ai', '1')

@section('rightbar')
    <!-- MailWorkspace teleports its stateful operation controls into this sibling shell slot. -->
    <div id="mailbox-operations-rightbar-slot"></div>
    @include('email::Tech.partials.signature-rightbar')
    @include('email::Tech.partials.ai-rightbar')
@endsection
