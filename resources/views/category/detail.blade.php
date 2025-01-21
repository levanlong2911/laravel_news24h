@extends('layouts.base', ['title' => __('admin.detail_category')])
@section('title', __('category.category_detail'))
@section('css')
    <link rel="stylesheet" href="/assets/css/admin.css" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
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
                                <form id="create_category">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('category.category_name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $infoCate->name }}"
                                                        class="form-control{{ $errors->has('name') ? ' is-invalid' : '' }} col-6"
                                                        name="name" id="name" placeholder="" disabled>
                                                    @error('name')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('admin.category.index') }}"
                                            class="btn button-center button-back">{{ __('category.back') }}</a>
                                            <a id="delete_form" class="btn button-del btn-danger">
                                                {{ __('category.delete') }}
                                            </a>
                                            <a href="{{ route('admin.category.update', ['id' => $infoCate->id]) }}"
                                                class="btn button-create-update w-200">
                                                {{ __('category.edit') }}
                                            </a>
                                    </div>
                                </form>
                                @include('modal.delete', ['url' => route('admin.category.delete'), 'id' => $infoCate->id])
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- </section> --}}
@endsection
