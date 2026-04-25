@extends('layouts.base', ['title' => 'Detail Prompt Framework'])
@section('title', 'Detail Prompt Framework')
@section('content')
<div class="container-fluid">
    <div class="card card-default">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h4 class="mb-0">Detail Prompt Framework</h4>
            <div class="d-flex gap-2">
                <a href="{{ route('prompt-framework.update', $framework->id) }}" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit mr-1"></i> Edit
                </a>
                <a href="{{ route('prompt-framework.index') }}" class="btn btn-outline-secondary btn-sm">Back</a>
            </div>
        </div>
        <div class="card-body">

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="font-weight-bold text-muted small">Name</label>
                    <div class="form-control-plaintext border rounded px-3 py-2 bg-light">{{ $framework->name }}</div>
                </div>
                <div class="col-md-3">
                    <label class="font-weight-bold text-muted small">Version</label>
                    <div class="form-control-plaintext border rounded px-3 py-2 bg-light">
                        <span class="badge badge-secondary">v{{ $framework->version }}</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="font-weight-bold text-muted small">Active</label>
                    <div class="form-control-plaintext border rounded px-3 py-2 bg-light">
                        @if($framework->is_active)
                            <span class="badge badge-success">Active</span>
                        @else
                            <span class="badge badge-secondary">Inactive</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="font-weight-bold text-muted small">Group Description</label>
                <div class="form-control-plaintext border rounded px-3 py-2 bg-light">{{ $framework->group_description }}</div>
            </div>

            <div class="form-group">
                <label class="font-weight-bold text-muted small">System Prompt</label>
                <pre class="border rounded p-3 bg-light" style="white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:.875rem">{{ $framework->system_prompt }}</pre>
            </div>

            <div class="form-group">
                <label class="font-weight-bold text-muted small">Phase 1 — Analyze</label>
                <pre class="border rounded p-3 bg-light" style="white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:.875rem">{{ $framework->phase1_analyze }}</pre>
            </div>

            <div class="form-group">
                <label class="font-weight-bold text-muted small">Phase 2 — Diagnose</label>
                <pre class="border rounded p-3 bg-light" style="white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:.875rem">{{ $framework->phase2_diagnose }}</pre>
            </div>

            <div class="form-group">
                <label class="font-weight-bold text-muted small">Phase 3 — Generate</label>
                <pre class="border rounded p-3 bg-light" style="white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:.875rem">{{ $framework->phase3_generate }}</pre>
            </div>

            <div class="text-muted small mt-2">
                Tạo lúc: {{ $framework->created_at->format('d/m/Y H:i') }}
                &nbsp;|&nbsp;
                Cập nhật: {{ $framework->updated_at->format('d/m/Y H:i') }}
            </div>

        </div>
    </div>
</div>
@endsection
