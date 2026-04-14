@extends('layouts.base', ['title' => 'News Sources'])
@section('title', 'News Sources')
@section('content')
<div class="container-fluid">

    {{-- ── HEADER ── --}}
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                <i class="fas fa-globe text-primary"></i> News Sources
            </h4>
            <small class="text-muted">Manage trusted & blocked domains used for article scoring and filtering.</small>
        </div>
        <div class="col-auto">
            <a href="{{ route('news-source.index', ['type' => 'trusted']) }}"
               class="btn btn-sm {{ $type === 'trusted' ? 'btn-success' : 'btn-outline-success' }}">
                <i class="fas fa-check-circle"></i> Trusted
            </a>
            <a href="{{ route('news-source.index', ['type' => 'blocked']) }}"
               class="btn btn-sm {{ $type === 'blocked' ? 'btn-danger' : 'btn-outline-danger' }} ml-1">
                <i class="fas fa-ban"></i> Blocked
            </a>
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
    <div class="card card-outline {{ $type === 'trusted' ? 'card-success' : 'card-danger' }} mb-3">
        <div class="card-header py-2">
            <h6 class="card-title mb-0">
                <i class="fas fa-plus"></i>
                Add {{ ucfirst($type) }} Source
            </h6>
        </div>
        <div class="card-body py-2">
            <form method="POST" action="{{ route('news-source.store') }}">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="small mb-1">Domain <span class="text-danger">*</span></label>
                        <input type="text" name="domain" class="form-control form-control-sm"
                               placeholder="espn.com" required>
                    </div>
                    <div class="col-md-3">
                        <label class="small mb-1">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               placeholder="ESPN" required>
                    </div>
                    <div class="col-md-3">
                        <label class="small mb-1">Category <span class="text-danger">*</span></label>
                        <input type="text" name="category" class="form-control form-control-sm"
                               placeholder="Sports" list="category-list" required>
                        <datalist id="category-list">
                            @foreach($categories as $cat)
                                <option value="{{ $cat }}">
                            @endforeach
                            <option value="Sports">
                            <option value="News">
                            <option value="Tech">
                            <option value="Entertainment">
                            <option value="Finance">
                            <option value="Health">
                            <option value="Local">
                            <option value="UGC">
                            <option value="Aggregator">
                            <option value="Clickbait">
                            <option value="Satire">
                        </datalist>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">
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
    @php
        $grouped = $sources->groupBy('category');
    @endphp

    @foreach($grouped as $category => $items)
    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="font-weight-bold">{{ $category }}</span>
            <span class="badge badge-secondary">{{ $items->count() }}</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width:30%">Domain</th>
                        <th style="width:25%">Name</th>
                        <th style="width:15%">Status</th>
                        <th style="width:30%" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $source)
                    <tr class="{{ $source->is_active ? '' : 'table-secondary text-muted' }}">
                        <td>
                            <code class="small">{{ $source->domain }}</code>
                        </td>
                        <td class="small">{{ $source->name }}</td>
                        <td>
                            @if($source->is_active)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Disabled</span>
                            @endif
                        </td>
                        <td class="text-right">
                            {{-- Toggle Active --}}
                            <form method="POST" action="{{ route('news-source.toggle', $source) }}"
                                  class="d-inline">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn-xs {{ $source->is_active ? 'btn-warning' : 'btn-success' }}"
                                        title="{{ $source->is_active ? 'Disable' : 'Enable' }}">
                                    <i class="fas {{ $source->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                </button>
                            </form>

                            {{-- Edit --}}
                            <button class="btn btn-xs btn-info ml-1"
                                    onclick="openEdit('{{ $source->id }}')">
                                <i class="fas fa-edit"></i>
                            </button>

                            {{-- Delete --}}
                            <form method="POST" action="{{ route('news-source.destroy', $source) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete {{ $source->domain }}?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-xs btn-danger ml-1">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    @if($sources->isEmpty())
        <div class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-3x mb-2"></i>
            <p>No {{ $type }} sources yet. Add one above.</p>
        </div>
    @endif
</div>

{{-- ── EDIT MODAL ── --}}
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editForm" action="">
                @csrf
                @method('PUT')
                <div class="modal-header py-2">
                    <h6 class="modal-title">Edit Source</h6>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="small">Domain</label>
                        <input type="text" id="edit-domain" class="form-control form-control-sm" readonly>
                    </div>
                    <div class="form-group">
                        <label class="small">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit-name" class="form-control form-control-sm" required>
                    </div>
                    <div class="form-group">
                        <label class="small">Type</label>
                        <select name="type" id="edit-type" class="form-control form-control-sm">
                            <option value="trusted">Trusted</option>
                            <option value="blocked">Blocked</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="small">Category <span class="text-danger">*</span></label>
                        <input type="text" name="category" id="edit-category" class="form-control form-control-sm" required list="category-list">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEdit(id) {
    $('#edit-domain').val('');
    $('#edit-name').val('');
    $('#edit-type').val('trusted');
    $('#edit-category').val('');
    $('#editModal').modal('show');

    fetch('/admin/news-source/' + id, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(s => {
        $('#editForm').attr('action', '/admin/news-source/' + s.id);
        $('#edit-domain').val(s.domain);
        $('#edit-name').val(s.name);
        $('#edit-type').val(s.type);
        $('#edit-category').val(s.category);
    })
    .catch(() => alert('Không thể tải thông tin source.'));
}
</script>
@endsection
