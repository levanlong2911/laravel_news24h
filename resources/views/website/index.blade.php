@extends('layouts.base', ['title' => __('website.website_management')])
@section('title', __('website.website_management'))
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
                                            <a href="{{ route('website.add') }}"
                                                class="btn btn-primary btn-block"><b>{{ __('website.create_new_domain') }}</b></a>
                                        </div>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-danger btn-block disabled-button reload"
                                                onclick="deleteMulti()"
                                                disabled><b>{{ __('website.delete_selected') }}</b></button>
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
                                                    <th class="text-center">{{ __('website.website_website') }}</th>
                                                    <th class="text-center">{{ __('website.website_host') }}</th>
                                                    <th class="text-center">{{ __('website.website_admin') }}</th>
                                                    <th class="text-center">{{ __('website.website_detail') }}</th>
                                            </thead>
                                            <tbody>
                                                @foreach ($listWeb as $website)
                                                    <tr id="id_tr_{{ $website->id }}" data-id="{{ $website->id }}">
                                                        <td class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                            <div
                                                                class="d-flex align-items-center justify-content-center form-check">
                                                                <input id="id_question{{ $website->id }}" type="checkbox"
                                                                    class="check-select is-edit is-choose form-check-input"
                                                                    value="{{ $website->id }}">
                                                                <label for="id_question{{ $website->id }}"
                                                                    class="form-check-label form-checkbox text-box-label"></label>
                                                            </div>
                                                            <input type="text" hidden name="id[{{ $website->id }}]" val
                                                                ue="{{ $website->id }}">
                                                        </td>
                                                        <td class="text-center">{{ $website->name }}</td>
                                                        <td class="text-center">{{ $website->host }}</td>
                                                        <td class="text-center">
                                                            <a
                                                                href="{{ route('website.detail', ['id' => $website->id]) }}">
                                                                {{ __('website.detail') }}
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer clearfix">
                                        {{ $listWeb->appends(request()->except('page'))->links('pagination::bootstrap-4') }}
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

