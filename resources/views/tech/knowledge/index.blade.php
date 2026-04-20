@extends('layouts.default_tech')

@section('title', 'Knowledge')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <h1>Knowledge</h1>
        <a href="{{ route('tech.knowledge.create') }}" class="btn btn-primary">
            <i class="bi bi-plus"></i> New Article
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Owner</th>
                                <th>Visibility</th>
                                <th>Status</th>
                                <th>Views</th>
                                <th>Last Updated</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($articles as $article)
                                <tr onclick="window.location='{{ route('tech.knowledge.show', $article->id) }}'" style="cursor: pointer;">
                                    <td>
                                        <strong>{{ $article->title }}</strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">{{ $article->category->name ?? 'Uncategorized' }}</span>
                                    </td>
                                    <td>{{ $article->owner->name ?? 'Unknown' }}</td>
                                    <td>
                                        @if($article->visibility == 'internal')
                                            <span class="badge bg-info">Internal</span>
                                        @elseif($article->visibility == 'client-wide')
                                            <span class="badge bg-primary">Client</span>
                                        @elseif($article->visibility == 'public')
                                            <span class="badge bg-success">Public</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($article->status == 'published')
                                            <span class="badge bg-success">Published</span>
                                        @elseif($article->status == 'draft')
                                            <span class="badge bg-warning">Draft</span>
                                        @elseif($article->status == 'archived')
                                            <span class="badge bg-danger">Archived</span>
                                        @else
                                            <span class="badge bg-secondary">{{ ucfirst($article->status) }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $article->view_count }}</td>
                                    <td>{{ $article->updated_at->format('d.m.Y H:i') }}</td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <a href="{{ route('tech.knowledge.show', $article->id) }}" class="btn btn-sm btn-outline-primary">View</a>
                                            <a href="{{ route('tech.knowledge.edit', $article->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <p class="text-muted mb-0">No articles found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($articles->hasPages())
                    <div class="card-footer">
                        {{ $articles->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
    <h3>Knowledge Base</h3>
    <ul>
        <li><a href="{{ route('tech.knowledge.index') }}">All Articles</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Right Sidebar</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection
