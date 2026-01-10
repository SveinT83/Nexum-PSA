<!-- ------------------------------------------------- -->
<!-- Units form and main view -->
<!-- ------------------------------------------------- -->

@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Units</h2>
        <div>
            <a href="{{ route('tech.admin.settings.economy.units.store') }}" class="btn btn-sm btn-primary bi bi-plus"> New</a>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- If Units exist, show them -->
    <!-- ------------------------------------------------- -->
    @if($units->count())

        <!-- ------------------------------------------------- -->
        <!-- UNITS Table header -->
        <!-- ------------------------------------------------- -->
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Short</th>
                    <th>Common code</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>

                    <!-- ------------------------------------------------- -->
                    <!-- Fore each Unit -->
                    <!-- ------------------------------------------------- -->
                    @foreach($units as $unit)

                        <!-- Form = update. Delete action is in same method in controller -->
                        <form action="{{ route('tech.admin.settings.economy.units.update', $unit->id) }}" method="post">
                            @csrf

                            <tr style="height: 80px;">

                                <!-- NAME -->
                                <td class="align-bottom">
                                    <x-forms.input_text name="name" labelName="" value="{{ $unit->name === 'xxx' ? '' : $unit->name }}"></x-forms.input_text>
                                </td>

                                <!-- SHORT -->
                                <td class="align-bottom">
                                    <x-forms.input_text name="short" labelName="" value="{{ $unit->short }}"></x-forms.input_text>
                                </td>

                                <!-- CODE -->
                                <td class="align-bottom">
                                    <x-forms.input_text name="common_code" labelName="" value="{{ $unit->code }}"></x-forms.input_text>
                                </td>

                                <!-- BUTTONS -->
                                <td class="align-bottom">
                                    <div class="d-flex justify-content-evenly">
                                        <button type="submit" name="action" value="update" class="btn btn-sm btn-primary">Update</button>
                                        <button type="submit" name="action" value="delete" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </form>
                    @endforeach

                    <!-- ------------------------------------------------- -->
                    <!-- END the table body -->
                    <!-- ------------------------------------------------- -->
                </tbody>
            </table>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- If no Units yet, show a message -->
        <!-- ------------------------------------------------- -->
    @else
        <div class="alert alert-warning">No units exists yet.
            <a href="{{ route('tech.admin.settings.economy.units.store') }}" class="btn btn-sm btn-secondary">New unit</a>
        </div>
    @endif

@endsection

@section('sidebar')

    <!-- ------------------------------------------------- -->
    <!-- Show sidebar menu if there are any items -->
    <!-- ------------------------------------------------- -->
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif

@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent Packages (MVP later)</div>
@endsection


