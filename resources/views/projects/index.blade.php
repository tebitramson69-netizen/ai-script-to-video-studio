@extends('layouts.app')
@section('title', 'Projects')

@section('content')
    <div class="head-row">
        <h1>Projects</h1>
        <a class="btn primary" href="{{ route('projects.create') }}">New project</a>
    </div>

    @if ($projects->isEmpty())
        <div class="card">
            <p class="muted">No projects yet. Paste a script to make your first video.</p>
        </div>
    @else
        <table class="table">
            <thead>
            <tr>
                <th>Title</th>
                <th>Stage</th>
                <th>Ratio</th>
                <th>Spent</th>
                <th>Updated</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($projects as $project)
                <tr>
                    <td><a href="{{ route('projects.show', $project) }}">{{ $project->title }}</a></td>
                    <td><span class="pill">{{ $project->status->label() }}</span></td>
                    <td>{{ $project->aspect_ratio->value }}</td>
                    <td>${{ number_format($project->spentUsd(), 2) }} / ${{ number_format((float) $project->budget_cap_usd, 2) }}</td>
                    <td class="muted">{{ $project->updated_at->diffForHumans() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{ $projects->links() }}
    @endif
@endsection
