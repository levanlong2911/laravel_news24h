@extends('layouts.base', ['title' => __('admin.edit_account')])
@section('title', __('admin.admin_management'))
@section('css')
    <link rel="stylesheet" href="/assets/css/admin.css" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <link rel="stylesheet" href="//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
    <script>
        $(function() {
            $('#role').on("change", function() {
                var roleSelect = ($(this).find(':selected').data('role'));
                var roleAdmin = "{{ App\Enums\Role::ADMIN->value }}";
                var roleUser = "{{ App\Enums\Role::MEMBER->value }}";
                if (roleSelect === roleUser) {
                    $('.user-field').show();
                }
                if (roleSelect === roleAdmin) {
                    $('.user-field').hide();
                    // $('#address').val("");
                    // $('#birthday').val("");
                }
            });
            $.validator.addMethod(
                "australianDate",
                function(value, element) {
                    // put your own logic here, this is just a (crappy) example
                    if (value) {
                        return value.match(/(?:0[1-9]|[12][0-9]|3[01])\/(?:0[1-9]|1[0-2])\/(?:19|20\d{2})/);
                    }
                    return true;
                },
                "Please enter a date in the format dd/mm/yyyy."
            );
            $('#change_password_toggle').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.password-fields').show();
                    $('#password, #confirm_password').prop('required', true);
                } else {
                    $('.password-fields').hide();
                    $('#password, #confirm_password').prop('required', false);
                }
            });
            $('#quickForm').validate({
                rules: {
                    name: {
                        required: true,
                        maxlength: 100
                    },
                    email: {
                        required: true,
                        email: true,
                    },
                    role: {
                        required: true
                    },
                    domain: {
                        required: true
                    },
                    password: {
                        required: true,
                        minlength: 8
                    },
                    confirm_password: {
                        required: true,
                        equalTo: "#password"
                    },
                },
                birthday: {
                    australianDate: true
                },
                messages: {
                    name: {
                        required: "{{ __('admin.input_name_required') }}",
                    },
                    email: {
                        required: "{{ __('admin.input_email_required') }}",
                        email: "{{ __('admin.input_email_valid') }}"
                    },
                    role: {
                        required: "{{ __('admin.select_role') }}",
                    },
                    domain: {
                        required: "{{ __('admin.input_domain') }}",
                    },
                    password: {
                        required: "{{ __('admin.input_password_required') }}",
                        minlength: "{{ __('admin.min_password') }}",
                    },
                    confirm_password: {
                        required: "{{ __('admin.input_confirm_password_required') }}",
                        equalTo: "{{ __('admin.input_confirm_password_required') }}",
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
            $("#datepicker").datepicker({
                dateFormat: 'dd/mm/yy'
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
                                <form id="quickForm" action="{{ route('admin.update', ['id' => $dataAcc->id]) }}"
                                    method="post">
                                    @csrf
                                    <div class="card-body lable-form-add-policy pt-0" id="card-add">
                                        {{-- <div class="row">
                                                    <p class="m-0 ">{{ __('as0011.sub_title') }}</p>
                                                </div> --}}
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.role') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <select
                                                        class="form-control {{ $errors->has('role') ? ' is-invalid' : '' }} col-6"
                                                        name="role" id="role">
                                                        @foreach ($listRole as $id => $role)
                                                            <option value="{{ $role->id }}"
                                                                data-role="{{ $role->name }}"
                                                                {{ object_get($dataAcc, 'role.name') == $role->name ? 'selected' : '' }}>
                                                                {{ $role->name }}
                                                            </option>
                                                        @endforeach
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
                                                    <input type="text" value="{{ $dataAcc->name }}"
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
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.email') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $dataAcc->email }}"
                                                        class="form-control{{ $errors->has('email') ? ' is-invalid' : '' }} col-6"
                                                        name="email" id="email" placeholder="">
                                                    @error('email')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        {{-- <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.domain') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="text" value="{{ $dataAcc->domain }}"
                                                        class="form-control{{ $errors->has('domain') ? ' is-invalid' : '' }} col-6"
                                                        name="domain" id="domain" placeholder="">
                                                    @error('domain')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div> --}}
                                        <div class="row row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.domain') }}<span
                                                        style="color: red; "> *</span></p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <select
                                                        class="form-control {{ $errors->has('domain') ? ' is-invalid' : '' }} col-6"
                                                        name="domain" id="domain">
                                                        @foreach ($listWebsite as $id => $web)
                                                            <option value="{{ $web->id }}"
                                                                data-web="{{ $web->name }}"
                                                                {{ object_get($dataAcc, 'domains.name') == $web->name ? 'selected' : '' }}>
                                                                {{ $web->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @error('name')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.change_password') }}</p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="checkbox" id="change_password_toggle">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row row password-fields" style="display: none;">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.password') }}
                                                    <span
                                                        style="color: red; "> *
                                                    </span>
                                                </p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="password" value="{{ old('password')}}"
                                                        class="form-control{{ $errors->has('password') ? ' is-invalid' : '' }} col-6"
                                                        name="password" id="password" placeholder="">
                                                    @error('password')
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row row password-fields" style="display: none;">
                                            <div class="col-2 d-flex align-items-center">
                                                <p class="align-middle p-0 m-0">{{ __('admin.confirm_password') }}
                                                    <span
                                                        style="color: red; "> *
                                                    </span>
                                                </p>
                                            </div>
                                            <div class="col-10 pl-0">
                                                <div class="input inputMessage">
                                                    <input type="password"
                                                        value="{{ old('confirm_password')}}"
                                                        class="form-control{{ $errors->has('confirm_password') ? ' is-invalid' : '' }} col-6"
                                                        name="confirm_password" id="confirm_password" placeholder="">
                                                    @error('confirm_password')
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
                                            <button type="button" class="btn button-create-update w-200"
                                                id="edit_form">{{ __('admin.update') }}</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
