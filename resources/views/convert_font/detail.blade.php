@extends('layouts.base', ['title' => __('font.font_detail')])
@section('title', __('font.font_detail'))
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
                                                    {{ __('font.find') }}<span style="color: red; ">
                                                        *</span></p>
                                            </div>
                                            <div class="col-9 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    {{ $inForFont->find }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">{{ __('font.replace') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-9 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    {{ $inForFont->replace }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('font.index') }}"
                                        class="btn button-center button-back btn-detail-custom">{{ __('admin.back') }}</a>
                                        <a id="delete_form" class="btn button-del btn-danger btn-detail-custom">
                                            {{ __('admin.delete') }}
                                        </a>
                                        <a href="{{ route('font.update',['id' => $inForFont->id]) }}" class="btn button-create-update w-200 btn-detail-custom">
                                            {{ __('admin.edit') }}
                                        </a>
                                    </div>
                                    @include('modal.delete', ['url' => route('font.delete'), 'id' => $inForFont->id])
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- </section> --}}
@endsection
