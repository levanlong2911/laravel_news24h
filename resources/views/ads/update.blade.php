@extends('layouts.base', ['title' => __('ads.ads_edit')])
@section('title', __('ads.ads_edit'))
@section('css')
    <link rel="stylesheet" href="{{ asset('assets/css/question.css') }}">
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
    <script>
        $(function() {
            $('#quickForm').validate({
                rules: {
                    name: {
                        required: true,
                    },
                    position: {
                        required: true,
                    },
                    code: {
                        required: true,
                    },
                },
                messages: {
                    name: {
                        required: "{{ __('ads.validate_name_required') }}",
                    },
                    position: {
                        required: "{{ __('ads.validate_position_required') }}",
                    },
                    code: {
                        required: "{{ __('ads.validate_code_required') }}",
                    },
                },
                errorElement: 'span',
                errorPlacement: function(error, element) {
                    error.addClass('invalid-feedback');
                    element.closest('.inputMessage').append(error);
                },
                highlight: function(element, errorClass, validClass) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element, errorClass, validClass) {
                    $(element).removeClass('is-invalid');
                }
            });
        })
    </script>
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
                                {{-- <form id="quickForm" action="{{ route('ads.update', ['id' => $infoTag->id]) }}" --}}
                                <form id="quickForm" action=""
                                    method="post">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row mb-3">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('ads.ads_name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $inforAds->name }}"
                                                        class="form-control{{ $errors->has('name') ? ' is-invalid' : '' }} col-6"
                                                        name="name" id="name" placeholder="">
                                                    @error('name')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">
                                                    {{ __('ads.position') }}<span style="color: red; ">
                                                        *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <select
                                                        class="form-control {{ $errors->has('position') ? ' is-invalid' : '' }} col-6"
                                                        name="position" id="position">
                                                        <option value>--</option>
                                                        @foreach ($positions as $key => $value)
                                                            <option value="{{ $value }}" {{ $inforAds->position === $value ? 'selected' : '' }}>
                                                                {{ __($key) }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('position')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('ads.code') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <textarea
                                                        class="form-control{{ $errors->has('code') ? ' is-invalid' : '' }} col-6"
                                                        name="code" id="code" rows="4" placeholder="">
                                                        {{ $inforAds->script }}
                                                    </textarea>
                                                    @error('code')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('ads.detail', ['id' => $inforAds->id]) }}"
                                            class="btn button-center button-back btn-detail-custom">{{ __('admin.back') }}</a>
                                        <button id="edit_form"
                                            class="btn button-right button-create-update btn-detail-custom">{{ __('admin.update') }}</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- </section> --}}
@endsection
