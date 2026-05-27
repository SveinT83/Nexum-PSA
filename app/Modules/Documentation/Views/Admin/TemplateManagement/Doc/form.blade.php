@extends('layouts.default_tech')

@section('title', 'TemplatesManagement Management')

@section('pageHeader')

    <div class="col-auto">
        <h1>Documentations Form</h1>
    </div>

    <div class="col-auto">
        <x-buttons.back url="{{route('tech.admin.system.templatesManagement.doc.index') }}"> Back</x-buttons.back>
    </div>
@endsection

@section('content')

    @livewire('tech.admin.system.templates-management.doc.template-form', ['templateId' => $templateId ?? null])

@endsection

@section('sidebar')
    <x-nav.admin-menu group="templates" />
@endsection

@section('rightbar')
@endsection

