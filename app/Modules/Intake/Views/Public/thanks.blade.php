@extends('intake::layouts.public')

@section('title', 'Request received')
@section('eyebrow', 'Request received')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Public Intake Thanks -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <div class="display-6 text-success mb-3">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
            </div>
            <h1 class="h4">Request received</h1>
            <p class="text-muted mb-0">{{ $form->success_message ?: 'Thank you. Your request has been received.' }}</p>
        </div>
    </div>
@endsection
