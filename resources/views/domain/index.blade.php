@extends('layouts.base', ['title' => __('domain.domain_management')])
@section('title', __('domain.domain_management'))
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
                                            <a href="{{ route('domain.add') }}"
                                                class="btn btn-primary btn-block"><b>{{ __('domain.create_new_domain') }}</b></a>
                                        </div>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-danger btn-block disabled-button reload"
                                                onclick="deleteMulti()"
                                                disabled><b>{{ __('domain.delete_selected') }}</b></button>
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
                                                    <th class="text-center">{{ __('domain.domain_name') }}</th>
                                                    <th class="text-center">{{ __('domain.domain_class') }}</th>
                                                    <th class="text-center">{{ __('domain.domain_detail') }}</th>
                                            </thead>
                                            <tbody>
                                                @foreach ($listsDomain as $domain)
                                                    <tr id="id_tr_{{ $domain->id }}" data-id="{{ $domain->id }}">
                                                        <td class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                            <div
                                                                class="d-flex align-items-center justify-content-center form-check">
                                                                <input id="id_question{{ $domain->id }}" type="checkbox"
                                                                    class="check-select is-edit is-choose form-check-input"
                                                                    value="{{ $domain->id }}">
                                                                <label for="id_question{{ $domain->id }}"
                                                                    class="form-check-label form-checkbox text-box-label"></label>
                                                            </div>
                                                            <input type="text" hidden name="id[{{ $domain->id }}]" val
                                                                ue="{{ $domain->id }}">
                                                        </td>
                                                        <td class="text-center">{{ $domain->domain }}</td>
                                                        <td class="text-center">{{ $domain->key_class }}</td>
                                                        <td class="text-center">
                                                            <a
                                                                href="{{ route('domain.detail', ['id' => $domain->id]) }}">
                                                                {{ __('domain.detail') }}
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer clearfix">
                                        {{ $listsDomain->appends(request()->except('page'))->links('pagination::bootstrap-4') }}
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
        var DELETE_URL = "{{ route('domain.delete') }}";
        // get list client init
        var listIds = <?php echo json_encode($domainIds); ?>;
        listIds = listIds.map(String);
    </script>
@endsection

