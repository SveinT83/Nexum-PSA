@extends('layouts.default_tech')

@section('pageHeader')
	<div class="d-flex justify-content-between align-items-center py-3">
		<h2 class="h4 mb-0">Client: {{ $client->name }}</h2>
		<div>
			<a href="{{ route('tech.clients.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
		</div>
	</div>
@endsection

@section('content')
	<div class="col-12 col-lg-10">
		<div class="card mb-4">
			<div class="card-header">Summary</div>
			<div class="card-body">
				<dl class="row mb-0">
					<dt class="col-sm-3">Name</dt><dd class="col-sm-9">{{ $client->name }}</dd>
					<dt class="col-sm-3">Org No</dt><dd class="col-sm-9">{{ $client->org_no ?? '—' }}</dd>
					<dt class="col-sm-3">Billing Email</dt><dd class="col-sm-9">{{ $client->billing_email ?? '—' }}</dd>
					<dt class="col-sm-3">Status</dt><dd class="col-sm-9">@if($client->active)<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Inactive</span>@endif</dd>
					<dt class="col-sm-3">Notes</dt><dd class="col-sm-9">{{ $client->notes ?? '—' }}</dd>
				</dl>
			</div>
		</div>
		<p class="text-muted small">Embedded tickets, tasks, sites, users kommer senere.</p>
	</div>
@endsection

@section('sidebar')
	<div class="p-3 small text-muted">Client nav (later)</div>
@endsection

@section('rightbar')
	<div class="p-3 small text-muted">Widgets (later)</div>
@endsection

