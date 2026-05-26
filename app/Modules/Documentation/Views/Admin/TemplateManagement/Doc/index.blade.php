@extends('layouts.default_tech')

@section('title', 'TemplatesManagement Management')

@section('pageHeader')
        <div class="col-auto">
            <h1>Documentations Templates</h1>
        </div>

        <div class="col-auto">
            <x-buttons.back url="{{ route('tech.admin.system.templatesManagement.index') }}"> Back</x-buttons.back>
            <x-buttons.addlink url="{{ route('tech.admin.system.templatesManagement.doc.create') }}"> Create New Template</x-buttons.addlink>
        </div>
@endsection

@section('content')
    <h2>Templates list</h2>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- List of all themplates -->
    <!-- -------------------------------------------------------------------------------------------------- -->

    <!-- ---------------------------------------- -->
    <!-- Start Table view of all templates -->
    <!-- ---------------------------------------- -->
    <table class="table">
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Category of use</th>
            </tr>
        </thead>


        <tbody>

            @forelse($templates as $template)

                <tr>
                    <th scope="row">
                        <a href="{{ route('tech.admin.system.templatesManagement.doc.edit', $template->id) }}">{{ $template->name }}</a>
                    </th>

                    <th scope="col"><p>{{ $template->category->name }}</p></th>
                </tr>

            @empty

                <div class="alert alert-info">
                    No templates found.
                </div>

            @endforelse

        </tbody>
    </table>

@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection

@section('rightbar')
@endsection

