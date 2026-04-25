@extends('layouts.base', ['title' => __('admin.newsWeb_management')])
@section('title', __('admin.newsWeb_management'))
@section('content')
    {{-- <section class="content"> --}}
    <div class="container-fluid">
        <div class="col-md-12">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-default">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <form method="GET" class="form-inline flex-wrap gap-2">
                                    <input type="text" name="domain" class="form-control form-control-sm mr-2"
                                        placeholder="Tìm theo domain..." value="{{ request('domain') }}" style="min-width:180px">
                                    <select name="category_id" class="form-control form-control-sm mr-2">
                                        <option value="">All category</option>
                                        @foreach ($categories as $cat)
                                            <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>
                                                {{ $cat->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-primary btn-sm mr-1">Search</button>
                                    <a href="{{ route('news-web.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                                </form>
                                <div class="d-flex gap-2">
                                    <a href="{{ route('news-web.add') }}" class="btn btn-primary btn-sm"><b>{{ __('tag.create_new_tag') }}</b></a>
                                    <button type="button" class="btn btn-danger btn-sm disabled-button reload"
                                        onclick="deleteMulti()" disabled><b>{{ __('tag.delete_selected') }}</b></button>
                                </div>
                            </div>
                            <div class="policy">
                                <div>
                                    <div class="card-body table-responsive p-0">
                                        <table class="table box-list table-bordered table-striped">
                                            <thead class="bg-th-blue">
                                                <tr class="background-title-table">
                                                    <th scope="col" class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                        <div
                                                            class="d-flex align-items-center justify-content-center form-check">
                                                            <input type="checkbox" id="check_select_all"
                                                                class="form-check-input">
                                                            <label for="check_select_all"
                                                                class="form-check-label form-checkbox text-box-label"></label>
                                                        </div>
                                                    </th>
                                                    <th class="text-center">{{ __('tag.tag') }}</th>
                                                    <th class="text-center">Domain</th>
                                                    <th class="text-center">Base url</th>
                                                    <th class="text-center">{{ __('tag.name_category') }}</th>
                                                    <th class="text-center">{{ __('tag.detail') }}</th>
                                            </thead>
                                            <tbody>
                                                @foreach ($listNewsWeb as $Web)
                                                    <tr id="id_tr_{{ $Web->id }}" data-id="{{ $Web->id }}">
                                                        <td class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                            <div
                                                                class="d-flex align-items-center justify-content-center form-check">
                                                                <input id="id_tag{{ $Web->id }}" type="checkbox"
                                                                    class="check-select is-edit is-choose form-check-input"
                                                                    value="{{ $Web->id }}">
                                                                <label for="id_tag{{ $Web->id }}"
                                                                    class="form-check-label form-checkbox text-box-label"></label>
                                                            </div>
                                                            <input type="text" hidden name="id[{{ $Web->id }}]"
                                                                value="{{ $Web->id }}">
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $Web->id }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $Web->domain }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $Web->base_url }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ data_get($Web, 'category.name') }}
                                                        </td>
                                                        <td class="text-center" style="white-space:nowrap">
                                                            <a href="{{ route('news-web.detail', ['id' => $Web->id]) }}"
                                                               class="btn btn-xs btn-outline-primary" title="{{ __('admin.detail') }}">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="{{ route('news-web.update', ['id' => $Web->id]) }}"
                                                               class="btn btn-xs btn-outline-warning" title="{{ __('admin.update') }}">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer clearfix">
                                        {{ $listNewsWeb->appends(request()->except('page'))->links('pagination::bootstrap-4') }}
                                    </div>
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="float-right">
                                                <div class="btn-group">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- The Modal -->

    {{-- </section> --}}
@endsection
@section('script')
    <script src="{{ asset('assets/dist/js/commonHandleList.js') }}"></script>
    <script>
        // const
        const MODAL_CONFIRM_URL = "{{ route('modal.confirm') }}";
        var STORAGE_NAME = "web_selected_storage";
        var DELETE_URL = "{{ route('news-web.delete') }}";
        // get list client init
        var listIds = <?php echo json_encode($webIds); ?>;
        listIds = listIds.map(String);
    </script>
@endsection

