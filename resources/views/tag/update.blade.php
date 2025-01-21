@extends('layouts.base', ['title' => __('tag.tag_edit')])
@section('title', __('tag.tag_edit'))
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
                    category_id: {
                        required: true,
                    },
                    tags: {
                        required: true,
                    },
                },
                messages: {
                    category_id: {
                        required: "{{ __('category.validate_tag_required') }}",
                    },
                    tags: {
                        required: "{{ __('tag.validate_tag_required') }}",
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
                                <form id="quickForm" action="{{ route('tag.update', ['id' => $infoTag->id]) }}"
                                    method="post">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        <div class="row mb-3">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">
                                                    {{ __('tag.category') }}<span style="color: red; ">
                                                        *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <select
                                                        class="form-control {{ $errors->has('category_id') ? ' is-invalid' : '' }} col-6"
                                                        name="category_id" id="category_id">
                                                        <option value>--</option>
                                                        @foreach ($listsCate as $item)
                                                            <option value="{{ $item->id }}"
                                                                {{ old('category_id') ? (old('category_id') == $item->id ? 'selected' : '') : ($infoTag->category_id == $item->id ? 'selected' : '') }}>
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
                                        <div class="row mb-3">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('tag.tag_name') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $infoTag->name }}"
                                                        class="form-control{{ $errors->has('tags') ? ' is-invalid' : '' }} col-6"
                                                        name="tags" id="tags" placeholder="">
                                                        {{-- <textarea class="form-control{{ $errors->has('tags') ? ' is-invalid' : '' }} col-12"
                                                            name="tags" rows="5">{{ old('tags') ? old('tags') : $infoTag->name }}</textarea> --}}
                                                    @error('tags')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <a href="{{ route('tag.detail', ['id' => $infoTag->id]) }}"
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
