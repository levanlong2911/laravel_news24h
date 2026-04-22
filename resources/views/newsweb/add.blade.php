@extends('layouts.base', ['title' => __('tag.create_new_account')])
@section('title', __('tag.create_new_account'))
@section('css')
    <link rel="stylesheet" href="{{ asset('assets/css/tag.css') }}">
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script>
        $(function() {
            $('#quickForm').validate({
                rules: {
                    category: {
                        required: true,
                    },
                    url: {
                        required: true,
                        url: true
                    },
                },
                messages: {
                    category: {
                        required: "{{ __('category.validate_tag_required') }}",
                    },
                    url: {
                        required: "{{ __('domain.input_domain_required') }}",
                        url: "{{ __('domain.input_url') }}",
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
                                <form id="quickForm" action="{{ route('news-web.add') }}" method="post">
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
                                                            <option value="{{ $item->id }}" {{ (old('category_id') == $item->id) ? 'selected' : '' }}>{{  $item->name }}</option>
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
                                                <p class="align-middle p-0 m-0">{{ __('url.name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage text-form-detail">
                                                    <input type="text" value="{{ old('url') ?? old('url') }}"
                                                        class="form-control{{ $errors->has('url') ? ' is-invalid' : '' }} col-6"
                                                        name="url" id="url" placeholder="">
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
                                        <a href="{{ route('news-web.index') }}"
                                            class="btn button-center button-back btn-detail-custom">{{ __('tag.back') }}</a>
                                        <button id="create_form"
                                            class="btn button-right button-create-update btn-detail-custom">{{ __('tag.add') }}</button>
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
