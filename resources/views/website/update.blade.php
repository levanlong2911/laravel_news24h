@extends('layouts.base', ['title' => __('admin.edit_category')])
@section('title', __('website.website_update'))
@section('css')
    <link rel="stylesheet" href="/assets/css/admin.css" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
    <script>
        $(function() {
            $.validator.addMethod("validDomain", function(value, element) {
                var host = value.trim();

                // Loại bỏ "http://", "https://" và "www."
                host = host.replace(/^https?:\/\//, '').replace(/^www\./, '');

                // Regex kiểm tra domain hợp lệ
                var domainRegex = /^[a-zA-Z0-9-]+(\.[a-zA-Z]{2,})+$/;
                return this.optional(element) || domainRegex.test(host);
            }, "Vui lòng nhập tên miền hợp lệ (ví dụ: crash.net)");
            $('#quickForm').validate({
                rules: {
                    name: {
                        required: true,
                        maxlength: 100,
                        minlength: 5
                    },
                    host: {
                        required: true,
                        validDomain: true
                    },
                },
                messages: {
                    name: {
                        required: {{ __('website.input_name_required') }}",
                        maxlength: "{{ __('website.max_name') }}",
                        minlength: "{{ __('website.min_name') }}",
                    },
                    host: {
                        required: "{{ __('website.input_host_required') }}",
                        validDomain: "{{ __('website.input_url') }}",
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
                                <form id="quickForm" action="{{ route('website.update', ['id' => $inForWebsite->id]) }}"
                                    method="post">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('website.website_name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $inForWebsite->name }}"
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
                                    </div>
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('website.website_host') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $inForWebsite->host }}"
                                                        class="form-control{{ $errors->has('host') ? ' is-invalid' : '' }} col-6"
                                                        name="host" id="host" placeholder="">
                                                    @error('host')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('website.index') }}"
                                            class="btn button-center button-back">{{ __('website.back') }}</a>
                                        <button type="submit"
                                            class="btn button-right button-create-update" id="edit_form">{{ __('website.update') }}</button>
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
