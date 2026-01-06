@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Costs</h2>
        <div>
            <a href="{{ route('tech.costs.create') }}" class="btn btn-sm btn-secondary">New Cost</a>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Alert message -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- If Cost -->
    <!-- ------------------------------------------------- -->
    @if($costs->count())

        <!-- ------------------------------------------------- -->
        <!-- Cost Table header -->
        <!-- ------------------------------------------------- -->
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Cost</th>
                    <th>Unit</th>
                    <th>Recurrence</th>
                    <th>Vendor</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>

                    @foreach($costs as $cost)
                        <tr>
                            <td>
                                <a href="{{ route('tech.costs.show', $cost) }}" class="text-decoration-none">{{ $cost->name }}</a>
                            </td>
                            <td>
                                <p><b class="d-sm-none">Cost: </b>{{ $cost -> cost }}</p>
                            </td>
                            <td>
                                <p><b class="d-sm-none">Unit: </b>{{ $cost -> unit }}</p>
                            </td>
                            <td>
                                <p><b class="d-sm-none">Recurrence: </b>{{ $cost -> recurrence }}</p>
                            </td>
                            <td>
                                <p><b class="d-sm-none">Vendor: </b>{{ $cost -> vendor -> name ?? '-'}}</p>
                            </td>
                            <td class="fs-6 fw-lighter text-end">
                                <a class="btn btn-sm btn-primary bi bi-door-open" href="{{ route('tech.costs.show', $cost) }}"> Open</a>
                                <a class="btn btn-sm btn-warning bi bi-pencil" href="{{ route('tech.costs.edit', $cost )}}"> Edit</a>
                                <a class="btn btn-sm btn-danger bi bi-trash" href="{{ route('tech.costs.delete', $cost )}}"> Delete</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    <!-- ------------------------------------------------- -->
    <!-- If no costs -->
    <!-- ------------------------------------------------- -->
    @else
        <div class="alert alert-warning">No costs yet.
            <a href="{{ route('tech.costs.create') }}" class="btn btn-sm btn-secondary">Create the first cost!</a>
        </div>
    @endif

@endsection

@section('sidebar')
    <x-forms.form-card title="Order" action="{{ route('tech.costs.index') }}" method="get" button-text="Sorter">
        <div class="row">
            <div class="col">
                <x-forms.select name="sort" labelName="Fields">
                    <option value="name" {{ ($sort ?? '') === 'name' ? 'selected' : '' }}>Name</option>
                    <option value="cost" {{ ($sort ?? '') === 'cost' ? 'selected' : '' }}>Cost</option>
                    <option value="recurrence" {{ ($sort ?? '') === 'recurrence' ? 'selected' : '' }}>Recurrence</option>
                    <option value="vendor" {{ ($sort ?? '') === 'vendor' ? 'selected' : '' }}>Vendor</option>
                </x-forms.select>
            </div>
        </div>
        <div class="row mt-2">
            <div class="col">
                <x-forms.select name="dir" labelName="Rirection">
                    <option value="asc" {{ ($dir ?? '') === 'asc' ? 'selected' : '' }}>Stigende</option>
                    <option value="desc" {{ ($dir ?? '') === 'desc' ? 'selected' : '' }}>Synkende</option>
                </x-forms.select>
            </div>
        </div>
    </x-forms.form-card>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
    <b>Mål:</b>
    <ul>
        <li><s>Knytt en Vendor til kostnad = check</s></li>
        <li>Sortering på kostnadene</li>
        <li>Knytt kostnader til service</li>
        <li>Vis service som bruker kostnaden på selve kostnads visningen</li>
    </ul>
@endsection
