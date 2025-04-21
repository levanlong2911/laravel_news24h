@extends('layouts.base', ['title' => __('category.category_management')])
@section('title', __('category.category_management'))
@section('content')
    {{-- <section class="content"> --}}
    <div class="container-fluid">
        <div class="col-md-12">
            <div class="row">
                <div class="col-md-12">

                    <div class="card card-default">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <div class="float-right">
                                        <div class="btn-group">
                                            <a href="{{ route('admin.category.add') }}"
                                                class="btn btn-primary btn-block"><b>{{ __('category.create_new_category') }}</b></a>
                                        </div>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-danger btn-block disabled-button reload"
                                                onclick="deleteMulti()"
                                                disabled><b>{{ __('category.delete_selected') }}</b></button>
                                        </div>
                                    </div>
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
                                                    <th class="text-center">{{ __('category.category_id') }}</th>
                                                    <th class="text-center">{{ __('category.category_name') }}</th>
                                                    <th class="text-center">{{ __('category.detail') }}</th>
                                            </thead>
                                            <tbody>
                                                @foreach ($listsCate as $category)
                                                    <tr id="id_tr_{{ $category->id }}" data-id="{{ $category->id }}">
                                                        <td class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                            <div
                                                                class="d-flex align-items-center justify-content-center form-check">
                                                                <input id="id_question{{ $category->id }}" type="checkbox"
                                                                    class="check-select is-edit is-choose form-check-input"
                                                                    value="{{ $category->id }}">
                                                                <label for="id_question{{ $category->id }}"
                                                                    class="form-check-label form-checkbox text-box-label"></label>
                                                            </div>
                                                            <input type="text" hidden name="id[{{ $category->id }}]" val
                                                                ue="{{ $category->id }}">
                                                        </td>
                                                        <td class="text-center">{{ $category->id }}</td>
                                                        <td class="text-center">{{ $category->name }}</td>
                                                        <td class="text-center">
                                                            <a
                                                                href="{{ route('admin.category.detail', ['id' => $category->id]) }}">
                                                                {{ __('category.detail') }}
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer clearfix">
                                        {{ $listsCate->appends(request()->except('page'))->links('pagination::bootstrap-4') }}
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
    {{-- </section> --}}
@endsection
@section('script')
    <script src="{{ asset('assets/dist/js/commonHandleList.js') }}"></script>
    <script>
        // const
        const MODAL_CONFIRM_URL = "{{ route('modal.confirm') }}";
        var STORAGE_NAME = "level_selected_storage";
        var DELETE_URL = "{{ route('admin.category.delete') }}";
        // get list client init
        var listIds = <?php echo json_encode($listIdCate); ?>;
        listIds = listIds.map(String);
    </script>
@endsection

