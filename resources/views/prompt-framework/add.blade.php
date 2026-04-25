@extends('layouts.base', ['title' => 'Add Prompt Framework'])
@section('title', 'Add Prompt Framework')
@section('content')
<div class="container-fluid">
    <div class="card card-default">
        <div class="card-header">
            <h4 class="mb-0">Thêm Prompt Framework</h4>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger py-2">
                    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('prompt-framework.add') }}">
                @csrf
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name') }}" placeholder="team_sports, airline...">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="font-weight-bold">Version</label>
                            <input type="number" name="version" class="form-control" value="{{ old('version', 1) }}" min="1">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-group w-100">
                            <label class="font-weight-bold">Active</label>
                            <select name="is_active" class="form-control">
                                <option value="1" {{ old('is_active', 1) == 1 ? 'selected' : '' }}>Active</option>
                                <option value="0" {{ old('is_active', 1) == 0 ? 'selected' : '' }}>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">Group Description <span class="text-danger">*</span></label>
                    <input type="text" name="group_description" class="form-control @error('group_description') is-invalid @enderror"
                        value="{{ old('group_description') }}" placeholder="Mô tả ngắn về nhóm framework này">
                    @error('group_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">System Prompt <span class="text-danger">*</span></label>
                    <textarea name="system_prompt" rows="5"
                        class="form-control @error('system_prompt') is-invalid @enderror"
                        placeholder="Claude system instruction...">{{ old('system_prompt') }}</textarea>
                    @error('system_prompt')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">Phase 1 — Analyze <span class="text-danger">*</span></label>
                    <textarea name="phase1_analyze" rows="6"
                        class="form-control @error('phase1_analyze') is-invalid @enderror"
                        placeholder="Placeholders: {domain} {audience} {terminology}">{{ old('phase1_analyze') }}</textarea>
                    @error('phase1_analyze')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">Phase 2 — Diagnose <span class="text-danger">*</span></label>
                    <textarea name="phase2_diagnose" rows="6"
                        class="form-control @error('phase2_diagnose') is-invalid @enderror"
                        placeholder="Placeholder: {content_types_block}">{{ old('phase2_diagnose') }}</textarea>
                    @error('phase2_diagnose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">Phase 3 — Generate <span class="text-danger">*</span></label>
                    <textarea name="phase3_generate" rows="6"
                        class="form-control @error('phase3_generate') is-invalid @enderror"
                        placeholder="Placeholders: {tone_notes} {hook_style} {output_schema}">{{ old('phase3_generate') }}</textarea>
                    @error('phase3_generate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Lưu</button>
                    <a href="{{ route('prompt-framework.index') }}" class="btn btn-outline-secondary">Hủy</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
