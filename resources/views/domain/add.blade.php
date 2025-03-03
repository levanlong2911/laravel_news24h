@extends('layouts.base', ['title' => __('admin.create_new_category')])
@section('title', __('domain.create_new_domain'))
@section('css')
    <link rel="stylesheet" href="/assets/css/admin.css" />
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
    <script>
        $(function() {
            $('#quickForm').validate({
                rules: {
                    domain: {
                        required: true,
                        url: true
                    },
                    key_class: {
                        required: true,
                        maxlength: 50
                    },
                },
                messages: {
                    domain: {
                        required: "{{ __('domain.input_domain_required') }}",
                        url: "{{ __('domain.input_url') }}",
                    },
                    key_class: {
                        required: "{{ __('domain.input_class_required') }}",
                        maxlength: "{{ __('domain.input_class_length') }}",
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
                                <form id="quickForm" action="{{ route('domain.add') }}" method="post">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('domain.domain_name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ old('domain') ?? old('domain') }}"
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
                                                    <input type="text" value="{{ old('key_class') ?? old('key_class') }}"
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
                                            class="btn button-center button-back">{{ __('domain.domain_back') }}</a>
                                        <button type="submit" class="btn button-right button-create-update" id="create_form">{{ __('domain.domain_add') }}</button>
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
