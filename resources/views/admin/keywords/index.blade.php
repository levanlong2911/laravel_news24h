@extends('layouts.base', ['title' => 'Keywords'])
@section('title', 'Keywords')
@section('content')
<div class="container-fluid">

    {{-- ── HEADER ── --}}
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0"><i class="fas fa-key text-warning"></i> Keywords</h4>
            <small class="text-muted">Quản lý từ khóa tìm kiếm Google News</small>
        </div>
        <div class="col-auto">
            <span class="badge badge-primary badge-pill" style="font-size:.9rem">
                {{ $keywords->count() }} keywords
            </span>
        </div>
    </div>

    {{-- ── ALERTS ── --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show py-2">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    @endif

    {{-- ── ADD FORM ── --}}
    <div class="card card-outline card-warning mb-3">
        <div class="card-header py-2">
            <h6 class="card-title mb-0"><i class="fas fa-plus"></i> Thêm Keyword</h6>
        </div>
        <div class="card-body py-2">
            <form method="POST" action="{{ route('keyword.store') }}">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="small mb-1">Keyword Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               placeholder="Dallas Cowboys" required>
                    </div>
                    <div class="col-md-2">
                        <label class="small mb-1">Short Name</label>
                        <input type="text" name="short_name" class="form-control form-control-sm"
                               placeholder="cowboys">
                    </div>
                    <div class="col-md-3">
                        <label class="small mb-1">Search Query <span class="text-muted">(để trống = name + news)</span></label>
                        <input type="text" name="search_keyword" class="form-control form-control-sm"
                               placeholder="Dallas Cowboys news">
                    </div>
                    <div class="col-md-2">
                        <label class="small mb-1">Category</label>
                        <select name="category_id" class="form-control form-control-sm">
                            <option value="">-- None --</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="small mb-1">Order</label>
                        <input type="number" name="sort_order" class="form-control form-control-sm"
                               placeholder="99" min="1" max="999">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-sm btn-warning w-100">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
                @if($errors->any())
                    <div class="text-danger small mt-1">{{ $errors->first() }}</div>
                @endif
            </form>
        </div>
    </div>

    {{-- ── TABLE ── --}}
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr class="text-center small text-uppercase text-muted" style="font-size:.75rem">
                        <th width="40">Order</th>
                        <th class="text-left">Keyword Name</th>
                        <th class="text-left">Short Name</th>
                        <th class="text-left">Search Query</th>
                        <th width="130">Category</th>
                        <th width="80">Status</th>
                        <th width="140">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($keywords as $kw)
                    <tr class="{{ $kw->is_active ? '' : 'table-secondary text-muted' }}">
                        <td class="text-center align-middle small">{{ $kw->sort_order }}</td>
                        <td class="align-middle font-weight-bold">
                            {{ $kw->name }}
                        </td>
                        <td class="align-middle">
                            <code class="small">{{ $kw->short_name }}</code>
                        </td>
                        <td class="align-middle small text-muted">
                            {{ $kw->search_keyword ?: ($kw->name . ' news') }}
                            @if(!$kw->search_keyword)
                                <span class="badge badge-secondary" style="font-size:.65rem">auto</span>
                            @endif
                        </td>
                        <td class="text-center align-middle">
                            @if($kw->category)
                                <span class="badge badge-light border">{{ $kw->category->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center align-middle">
                            @if($kw->is_active)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Inactive</span>
                            @endif
                        </td>
                        <td class="text-center align-middle" style="white-space:nowrap">
                            {{-- Toggle Active --}}
                            <form method="POST" action="{{ route('keyword.toggle', $kw) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <button class="btn btn-xs {{ $kw->is_active ? 'btn-warning' : 'btn-success' }}"
                                        title="{{ $kw->is_active ? 'Tắt' : 'Bật' }}">
                                    <i class="fas {{ $kw->is_active ? 'fa-pause' : 'fa-play' }}"></i>
                                </button>
                            </form>

                            {{-- Edit --}}
                            <button class="btn btn-xs btn-info ml-1"
                                    onclick="openEdit('{{ $kw->id }}')">
                                <i class="fas fa-edit"></i>
                            </button>

                            {{-- Delete --}}
                            <form method="POST" action="{{ route('keyword.destroy', $kw) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Xóa keyword {{ $kw->name }}?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-danger ml-1">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            Chưa có keyword nào. Thêm ở trên.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

{{-- ── EDIT MODAL ── --}}
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editForm" action="">
                @csrf @method('PUT')
                <div class="modal-header py-2">
                    <h6 class="modal-title"><i class="fas fa-edit"></i> Sửa Keyword</h6>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="small">Keyword Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit-name" class="form-control form-control-sm" required>
                    </div>
                    <div class="form-group">
                        <label class="small">Short Name</label>
                        <input type="text" name="short_name" id="edit-short" class="form-control form-control-sm">
                    </div>
                    <div class="form-group">
                        <label class="small">Search Query
                            <span class="text-muted">(để trống = name + news)</span>
                        </label>
                        <input type="text" name="search_keyword" id="edit-search" class="form-control form-control-sm"
                               placeholder="e.g. aviation, airline news">
                    </div>
                    <div class="form-group">
                        <label class="small">Category</label>
                        <select name="category_id" id="edit-category" class="form-control form-control-sm">
                            <option value="">-- None --</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="small">Sort Order</label>
                        <input type="number" name="sort_order" id="edit-order"
                               class="form-control form-control-sm" min="1" max="999">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-sm btn-primary">Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEdit(id) {
    // Reset form
    $('#edit-name').val('');
    $('#edit-short').val('');
    $('#edit-search').val('');
    $('#edit-category').val('');
    $('#edit-order').val('');
    $('#editModal').modal('show');

    // Fetch keyword data by ID
    fetch('/admin/keyword/' + id, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(kw => {
        $('#editForm').attr('action', '/admin/keyword/' + kw.id);
        $('#edit-name').val(kw.name);
        $('#edit-short').val(kw.short_name);
        $('#edit-search').val(kw.search_keyword);
        $('#edit-category').val(kw.category_id);
        $('#edit-order').val(kw.sort_order);
    })
    .catch(() => alert('Không thể tải thông tin keyword.'));
}
</script>
@endsection
