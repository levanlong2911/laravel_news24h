@extends('layouts.base', ['title' => __('font.font_management')])
@section('title', __('font.font_management'))
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
                                            <a href="{{ route('font.add') }}"
                                                class="btn btn-primary btn-block"><b>{{ __('font.create_new_tag') }}</b>
                                            </a>
                                        </div>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-danger btn-block disabled-button reload"
                                                onclick="deleteMulti()"
                                                disabled><b>{{ __('font.delete_selected') }}</b></button>
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
                                                    <th class="text-center">{{ __('font.find') }}</th>
                                                    <th class="text-center">{{ __('font.replace') }}</th>
                                                    <th class="text-center">{{ __('font.date') }}</th>
                                                    <th class="text-center">{{ __('font.detail') }}</th>
                                            </thead>
                                            <tbody>
                                                @foreach ($listFont as $font)
                                                    <tr id="id_tr_{{ $font->id }}" data-id="{{ $font->id }}">
                                                        <td class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                            <div
                                                                class="d-flex align-items-center justify-content-center form-check">
                                                                <input id="id_font{{ $font->id }}" type="checkbox"
                                                                    class="check-select is-edit is-choose form-check-input"
                                                                    value="{{ $font->id }}">
                                                                <label for="id_font{{ $font->id }}"
                                                                    class="form-check-label form-checkbox text-box-label"></label>
                                                            </div>
                                                            <input type="text" hidden name="id[{{ $font->id }}]"
                                                                value="{{ $font->id }}">
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $font->find }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $font->replace }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ \Carbon\Carbon::parse($font->created_at)->format('d/m/Y') }}
                                                        </td>
                                                        <td class="text-center">
                                                            <a href="{{ route('font.detail', ['id' => $font->id]) }}">
                                                                {{ __('font.detail') }}
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer clearfix">
                                        {{ $listFont->appends(request()->except('page'))->links('pagination::bootstrap-4') }}
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
        var STORAGE_NAME = "font_selected_storage";
        var DELETE_URL = "{{ route('font.delete') }}";
        // get list client init
        var listIds = <?php echo json_encode($fontIds); ?>;
        listIds = listIds.map(String);
    </script>
@endsection
