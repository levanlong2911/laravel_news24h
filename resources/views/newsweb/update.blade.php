@extends('layouts.base', ['title' => __('tag.tag_edit')])
@section('title', __('tag.tag_edit'))
{{-- @section('css')
    <link rel="stylesheet" href="{{ asset('assets/css/question.css') }}">
@endsection --}}
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
            }, "Vui lòng nhập tên miền hợp lệ (ví dụ: domain.com)");
            $('#quickForm').validate({
                rules: {
                    category_id: {
                        required: true,
                    },
                    domain: {
                        required: true,
                        validDomain: true
                    },
                    url: {
                        required: true,
                        maxlength: 250
                    },
                },
                messages: {
                    category_id: {
                        required: "{{ __('category.validate_tag_required') }}",
                    },
                    domain: {
                        required: "{{ __('tag.validate_tag_required') }}",
                        validDomain: "{{ __('domain.input_validDomain_required')}}"
                    },
                    url: {
                        required: "{{ __('tag.validate_tag_required') }}",
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
                                <form id="quickForm" action="{{ route('news-web.update', ['id' => $infoWeb->id]) }}"
                                    method="post">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">
                                                    {{ __('tag.category') }}<span style="color: red; ">
                                                        *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    <select
                                                        class="form-control {{ $errors->has('category_id') ? ' is-invalid' : '' }} col-6"
                                                        name="category_id" id="category_id">
                                                        <option value>--</option>
                                                        @foreach ($listsCate as $item)
                                                            <option value="{{ $item->id }}"
                                                                {{ old('category_id') ? (old('category_id') == $item->id ? 'selected' : '') : ($infoWeb->category_id == $item->id ? 'selected' : '') }}>
                                                                {{ $item->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('category_id')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">{{ __('tag.tag_name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    <input type="text" value="{{ $infoWeb->domain }}"
                                                        class="form-control{{ $errors->has('domain') ? ' is-invalid' : '' }} col-6"
                                                        name="domain" id="domain" placeholder="">
                                                        {{-- <textarea class="form-control{{ $errors->has('domain') ? ' is-invalid' : '' }} col-12"
                                                            name="domain" rows="5">{{ old('domain') ? old('domain') : $infoTag->name }}</textarea> --}}
                                                    @error('domain')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center lable-form-detail">
                                                <p class="align-middle p-0 m-0">{{ __('tag.tag_name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    <input type="text" value="{{ $infoWeb->base_url }}"
                                                        class="form-control{{ $errors->has('url') ? ' is-invalid' : '' }} col-6"
                                                        name="url" id="url" placeholder="">
                                                        {{-- <textarea class="form-control{{ $errors->has('url') ? ' is-invalid' : '' }} col-12"
                                                            name="url" rows="5">{{ old('url') ? old('url') : $infoTag->name }}</textarea> --}}
                                                    @error('url')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('tag.detail', ['id' => $infoWeb->id]) }}"
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
