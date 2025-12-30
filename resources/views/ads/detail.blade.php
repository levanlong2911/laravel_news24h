@extends('layouts.base', ['title' => __('ads.ads_detail')])
@section('title', __('ads.ads_detail'))
@section('css')
    <link rel="stylesheet" href="{{ asset('assets/css/question.css') }}">
@endsection
@section('content')
    {{-- <section class="content"> --}}
    <div class="container-fluid">
        <div class="col-md-12">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-default">
                        <div class="card-body">
                            <div class="policy">
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">
                                                    {{ __('ads.name') }}<span style="color: red; ">
                                                        *</span></p>
                                            </div>
                                            <div class="col-9 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    {{ $inforAds->name }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">{{ __('ads.position') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-9 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    {{ $inforAds->position }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">{{ __('ads.position') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-9 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    {{ data_get($inforAds, 'webSite.host') }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">{{ __('ads.code') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-9 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    <textarea
                                                        class="form-control{{ $errors->has('code') ? ' is-invalid' : '' }} col-6"
                                                        name="code" id="code" rows="4" placeholder="" disabled>
                                                        {{ $inforAds->script }}
                                                    </textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('ads.index') }}"
                                        class="btn button-center button-back btn-detail-custom">{{ __('admin.back') }}</a>
                                        <a id="delete_form" class="btn button-del btn-danger btn-detail-custom">
                                            {{ __('admin.delete') }}
                                        </a>
                                        <a href="{{ route('ads.update',['id' => $inforAds->id]) }}" class="btn button-create-update w-200 btn-detail-custom">
                                            {{ __('admin.edit') }}
                                        </a>
                                    </div>
                                    @include('modal.delete', ['url' => route('ads.delete'), 'id' => $inforAds->id])
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- </section> --}}
@endsection
