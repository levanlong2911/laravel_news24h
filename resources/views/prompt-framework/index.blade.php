@extends('layouts.base', ['title' => 'Prompt Frameworks'])
@section('title', 'Prompt Frameworks')
@section('content')
<div class="container-fluid">
    <div class="card card-default">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <form method="GET" class="form-inline">
                    <input type="text" name="name" class="form-control form-control-sm mr-2"
                        placeholder="Search name..." value="{{ request('name') }}" style="min-width:200px">
                    <button class="btn btn-primary btn-sm mr-1">Search</button>
                    <a href="{{ route('prompt-framework.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                </form>
                <div class="d-flex gap-2">
                    <a href="{{ route('prompt-framework.add') }}" class="btn btn-primary btn-sm"><b>Add</b></a>
                    <button type="button" class="btn btn-danger btn-sm disabled-button reload"
                        onclick="deleteMulti()" disabled><b>Delete All</b></button>
                </div>
            </div>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show py-2">
                    {{ session('success') }}
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
            @endif

            <div class="table-responsive p-0">
                <table class="table table-bordered table-striped mb-0">
                    <thead class="bg-th-blue">
                        <tr>
                            <th class="pl-0 pr-0 text-center" style="width:40px">
                                <input type="checkbox" id="check_select_all" class="form-check-input">
                            </th>
                            <th>Name</th>
                            <th>Description</th>
                            <th class="text-center" style="width:70px">Version</th>
                            <th class="text-center" style="width:80px">Active</th>
                            <th class="text-center" style="width:100px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($list as $fw)
                            <tr id="id_tr_{{ $fw->id }}" data-id="{{ $fw->id }}">
                                <td class="text-center align-middle pl-0 pr-0">
                                    <input id="id_fw{{ $fw->id }}" type="checkbox"
                                        class="check-select is-edit is-choose form-check-input"
                                        value="{{ $fw->id }}">
                                    <input type="text" hidden name="id[{{ $fw->id }}]" value="{{ $fw->id }}">
                                </td>
                                <td class="align-middle font-weight-bold">{{ $fw->name }}</td>
                                <td class="align-middle text-muted small">{{ $fw->group_description }}</td>
                                <td class="text-center align-middle">
                                    <span class="badge badge-secondary">v{{ $fw->version }}</span>
                                </td>
                                <td class="text-center align-middle">
                                    @if($fw->is_active)
                                        <span class="badge badge-success">Active</span>
                                    @else
                                        <span class="badge badge-secondary">Off</span>
                                    @endif
                                </td>
                                <td class="text-center align-middle" style="white-space:nowrap">
                                    <a href="{{ route('prompt-framework.detail', $fw->id) }}"
                                       class="btn btn-xs btn-outline-primary" title="Detail">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('prompt-framework.update', $fw->id) }}"
                                       class="btn btn-xs btn-outline-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Chưa có framework nào.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer clearfix">
                {{ $list->appends(request()->query())->links('pagination::bootstrap-4') }}
            </div>
        </div>
    </div>
</div>
@endsection
@section('script')
    <script src="{{ asset('assets/dist/js/commonHandleList.js') }}"></script>
    <script>
        const MODAL_CONFIRM_URL = "{{ route('modal.confirm') }}";
        var STORAGE_NAME = "prompt_fw_storage";
        var DELETE_URL = "{{ route('prompt-framework.delete') }}";
        var listIds = <?php echo json_encode($ids); ?>;
        listIds = listIds.map(String);
    </script>
@endsection
