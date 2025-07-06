@extends('layouts.base', ['title' => __('font.font_edit')])
@section('title', __('font.font_edit'))
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
    <script>
        $(function() {
            $('#quickForm').validate({
                rules: {
                    find: {
                        required: true,
                        maxlength: 10
                    },
                    replace: {
                        required: true,
                        maxlength: 10
                    },
                },
                messages: {
                    find: {
                        required: "{{ __('font.validate_find_required') }}",
                        maxlength: "{{ __('font.validate_max_find_required') }}",
                    },
                    replace: {
                        required: "{{ __('font.validate_replace_required') }}",
                        maxlength: "{{ __('font.validate_max_replace_required') }}",
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
                                <form id="quickForm" action="{{ route('font.update', ['id' => $inForFont->id]) }}"
                                    method="post">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">{{ __('font.find') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    <input type="text" value="{{ $inForFont->find }}"
                                                        class="form-control{{ $errors->has('find') ? ' is-invalid' : '' }} col-6"
                                                        name="find" id="find" placeholder="">
                                                    @error('find')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">{{ __('font.replace') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    <input type="text" value="{{ $inForFont->replace }}"
                                                        class="form-control{{ $errors->has('replace') ? ' is-invalid' : '' }} col-6"
                                                        name="replace" id="replace" placeholder="">
                                                    @error('replace')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('font.detail', ['id' => $inForFont->id]) }}"
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
