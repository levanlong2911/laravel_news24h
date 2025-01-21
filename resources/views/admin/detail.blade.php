@extends('layouts.base', ['title' => __('admin.detail_info')])
@section('title', __('admin.detail_info'))
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
                                <form id="quickForm">
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.role') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <select class="form-control {{ $errors->has('role') ? ' is-invalid' : '' }} col-6" name="role" id="role" disabled>
                                                        <option value="">{{ $roleAcc->name }}</option>
                                                    </select>
                                                    @error('name')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $infoAcc->name }}"
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
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.email') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $infoAcc->email }}"
                                                        class="form-control{{ $errors->has('email') ? ' is-invalid' : '' }} col-6"
                                                        name="email" id="email" placeholder="" disabled>
                                                    @error('email')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-right mt-4">
                                            <a href="{{ route('admin.index') }}"
                                                class="btn button-center button-back">{{ __('admin.back') }}</a>
                                            @if (auth()->id() != $infoAcc->id)
                                                <a id="delete_form" class="btn button-del btn-danger">
                                                    {{ __('admin.delete') }}
                                                </a>
                                                <a href="{{ route('admin.update',['id' => $infoAcc->id]) }}" class="btn button-create-update w-200">
                                                    {{ __('admin.edit') }}
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </form>
                                @include('modal.delete', ['url' => route('admin.delete', ['id' => $infoAcc->id]), 'id' => $infoAcc->id])
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
