@extends('layouts.base', ['title' => __('ads.ads_management')])
@section('title', __('ads.ads_management'))
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
                                            <a href="{{ route('ads.add') }}"
                                                class="btn btn-primary btn-block"><b>{{ __('ads.create_new_tag') }}</b></a>
                                        </div>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-danger btn-block disabled-button reload"
                                                onclick="deleteMulti()"
                                                disabled><b>{{ __('ads.delete_selected') }}</b></button>
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
                                                    <th class="text-center">{{ __('ads.ads_name') }}</th>
                                                    <th class="text-center">{{ __('ads.position') }}</th>
                                                    <th class="text-center">{{ __('ads.date') }}</th>
                                                    <th class="text-center">{{ __('ads.detail') }}</th>
                                            </thead>
                                            <tbody>
                                                @foreach ($listAds as $ads)
                                                    <tr id="id_tr_{{ $ads->id }}" data-id="{{ $ads->id }}">
                                                        <td class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                            <div
                                                                class="d-flex align-items-center justify-content-center form-check">
                                                                <input id="id_ads{{ $ads->id }}" type="checkbox"
                                                                    class="check-select is-edit is-choose form-check-input"
                                                                    value="{{ $ads->id }}">
                                                                <label for="id_ads{{ $ads->id }}"
                                                                    class="form-check-label form-checkbox text-box-label"></label>
                                                            </div>
                                                            <input type="text" hidden name="id[{{ $ads->id }}]"
                                                                value="{{ $ads->id }}">
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $ads->name }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $ads->position }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ \Carbon\Carbon::parse($ads->created_at)->format('d/m/Y') }}
                                                        </td>
                                                        <td class="text-center">
                                                            <a href="{{ route('ads.detail', ['id' => $ads->id]) }}">
                                                                {{ __('ads.detail') }}
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer clearfix">
                                        {{ $listAds->appends(request()->except('page'))->links('pagination::bootstrap-4') }}
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
        var STORAGE_NAME = "ads_selected_storage";
        var DELETE_URL = "{{ route('ads.delete') }}";
        // get list client init
        var listIds = <?php echo json_encode($adsIds); ?>;
        listIds = listIds.map(String);
    </script>
@endsection
