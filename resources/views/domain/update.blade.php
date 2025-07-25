@extends('layouts.base', ['title' => __('admin.edit_category')])
@section('title', __('category.category_edit'))
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
                var domain = value.trim();

                // Loại bỏ "http://", "https://" và "www."
                domain = domain.replace(/^https?:\/\//, '').replace(/^www\./, '');

                // Regex kiểm tra domain hợp lệ
                var domainRegex = /^[a-zA-Z0-9-]+(\.[a-zA-Z]{2,})+$/;
                return this.optional(element) || domainRegex.test(domain);
            }, "Vui lòng nhập tên miền hợp lệ (ví dụ: crash.net)");
            $('#quickForm').validate({
                rules: {
                    domain: {
                        required: true,
                        validDomain: true
                    },
                    key_class: {
                        required: true,
                        maxlength: 500
                    },
                },
                messages: {
                    domain: {
                        required: "{{ __('domain.input_doamin_required') }}",
                        validDomain: "{{ __('domain.input_validDomain_required') }}",
                    },
                    key_class: {
                        required: "{{ __('domain.input_key_class_required') }}",
                        maxlength: "{{ __('domain.input_max_required') }}",
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
                                <form id="quickForm" action="{{ route('domain.update', ['id' => $infoDomain->id]) }}"
                                    method="post">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('domain.domain_name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $infoDomain->domain }}"
                                                        class="form-control{{ $errors->has('domain') ? ' is-invalid' : '' }} col-6"
                                                        name="domain" id="domain" placeholder="">
                                                    @error('domain')
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
                                                <p class="align-middle p-0 m-0">{{ __('domain.key_class') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $infoDomain->key_class }}"
                                                        class="form-control{{ $errors->has('key_class') ? ' is-invalid' : '' }} col-6"
                                                        name="key_class" id="key_class" placeholder="">
                                                    @error('key_class')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('domain.index') }}"
                                            class="btn button-center button-back">{{ __('domain.back') }}</a>
                                        <button type="submit"
                                            class="btn button-right button-create-update" id="edit_form">{{ __('domain.update') }}</button>
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
