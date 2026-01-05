@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">SLA</h2>
        <div>
            <a href="{{ route('tech.sla.create') }}" class="btn btn-sm btn-primary">New SLA policy</a>
        </div>
    </div>
    <form method="get" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" value="{{ $search ?? '' }}" class="form-control" placeholder="Search name" />
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </div>
    </form>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- If Sla -->
    <!-- ------------------------------------------------- -->
    @if($sla->count())

        <!-- ------------------------------------------------- -->
        <!-- SLA Table header -->
        <!-- ------------------------------------------------- -->
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>

                    <!-- ------------------------------------------------- -->
                    <!-- For Each SLA -->
                    <!-- ------------------------------------------------- -->
                    @foreach($sla as $slax)
                        <tr>
                            <td>
                                <a href="{{ route('tech.sla.show', $slax) }}" class="text-decoration-none">{{ $slax->name }}</a>
                            </td>
                            <td>
                                <p>
                                    <b class="d-sm-none">Description: </b>
                                    {{ \Illuminate\Support\Str::limit($slax->description, 120) }}
                                </p>
                            </td>
                            <td class="fs-6 fw-lighter text-end">
                                <a class="btn btn-sm btn-primary bi bi-door-open" href="{{ route('tech.sla.show', $slax) }}"> Open</a>
                                <a class="btn btn-sm btn-warning bi bi-pencil" href="{{ route('tech.sla.edit', $slax )}}"> Edit</a>
                                <a class="btn btn-sm btn-danger bi bi-trash" href="{{ route('tech.sla.delete', $slax )}}"> Delete</a>
                            </td>
                        </tr>
                    @endforeach

                <!-- ------------------------------------------------- -->
                <!-- END the table body -->
                <!-- ------------------------------------------------- -->
                </tbody>
            </table>
        </div>

    <!-- ------------------------------------------------- -->
    <!-- If no SLA -->
    <!-- ------------------------------------------------- -->
    @else
        <div class="alert alert-warning">No costs yet.
            <a href="{{ route('tech.sla.create') }}" class="btn btn-sm btn-secondary">Create the first SLA!</a>
        </div>
    @endif

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">SLA filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent SLA (MVP later)</div>
@endsection
