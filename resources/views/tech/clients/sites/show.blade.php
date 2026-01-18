@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">{{$site->name}}
            <a href="{{ route('tech.clients.show', $client->id) }}">{{$client->name}}</a></h2>
        <div>
            <x-buttons.back url="{{ route('tech.clients.sites.index')}}"> Back</x-buttons.back>
            <x-buttons.addlink url="{{ route('tech.clients.sites.create', $client) }}">New Site</x-buttons.addlink>
            <x-buttons.editlink url="{{ route('tech.clients.sites.edit', [$site, $client]) }}">Edit</x-buttons.editlink>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Site Info -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="site info">

        <div class="row">
            <div class="col-md-2">
                <b>Street:</b>
                <p>{{$site->address ?? '-'}}</p>
            </div>

            <div class="col-md-2">
                <b>CO Street:</b>
                <p>{{$site->co_address ?? '-'}}</p>
            </div>

            <div class="col-md-1">
                <b>Zip:</b>
                <p>{{$site->zip ?? '-'}}</p>
            </div>

            <div class="col-md-2">
                <b>City:</b>
                <p>{{$site->city ?? '-'}}</p>
            </div>

            <div class="col-md-2">
                <b>County:</b>
                <p>{{$site->county ?? '-'}}</p>
            </div>

            <div class="col-md-2">
                <b>Country:</b>
                <p>{{$site->country ?? '-'}}</p>
            </div>
        </div>
    </x-card.default>

    <!-- ------------------------------------------------- -->
    <!-- USERS - Shows users of the site in an table -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Users">

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>E-mail</th>
                    <th>Phone</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>

                <!-- ------------------------------------------------- -->
                <!-- For each users -->
                <!-- ------------------------------------------------- -->
                @foreach($users as $user)
                    <tr>
                        <td>
                            <a href="{{route ('tech.clients.users.show', $user)}}">{{$user->name}}</a>
                        </td>
                        <td>{{$user->role}}</td>
                        <td>{{$user->email}}</td>
                        <td>{{$user->phone}}</td>
                        <td></td>
                    </tr>
                @endforeach

                </tbody>
            </table>
        </div>
    </x-card.default>

@endsection

@section('sidebar')

@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent clients (MVP later)</div>
@endsection

