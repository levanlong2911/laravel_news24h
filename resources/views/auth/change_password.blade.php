@extends('layouts.auth.base', ['title' => __('two_fa.resetting_password')])
@section('css')
    <link rel="stylesheet" href="{{ asset('assets/plugins/toastr/toastr.min.css') }}">
@endsection
@section('content')
    <div class="login-box">
        <div class="login-logo">
            <img src="{{ asset('assets/img/logo.png') }}" alt="">
        </div>
        <!-- /.login-logo -->
        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">{{ __('two_fa.resetting_password') }}</p>
                <form role="form" id="quickForm" method="POST" action="{{ route('change_password') }}">
                    @csrf
                    <input type="hidden" name="id" value="{{ $admin['id'] }}">
                    <input type="hidden" name="email" value="{{ $admin['email'] }}">
                    <div class="form-group">
                        <label for="inputPassword label-login">{{ __('two_fa.password_new') }}</label>
                        <div class="input-group mb-3">
                            <input type="password" class="form-control {{ $errors->has('password') ? ' has-danger' : '' }}" id="inputPassword"
                                name="password" placeholder="{{ __('two_fa.password_required') }}" required>
                        </div>
                        @if ($errors->has('password'))
                            <span class="invalid-feedback" style="display: block;" role="alert">
                                <strong>{{ $errors->first('password') }}</strong>
                            </span>
                        @endif
                    </div>
                    <div class="form-group mb-3">
                        <label for="inputPasswordConfirm label-login">{{ __('two_fa.password_confirm') }}</label>
                        <div class="input-group mb-3">
                            <input type="password"
                                class="form-control {{ $errors->has('password_confirm') ? ' has-danger' : '' }}"
                                name="password_confirm" placeholder="{{ __('two_fa.password_confirm_required') }}" id="inputPasswordConfirm">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block btn-auth-custom">{{  __('two_fa.setting') }}</button>
                </form>
            </div>
            <!-- /.login-card-body -->
        </div>
    </div>
    <!-- /.login-box -->
@endsection
@section('js')
    <script>
        $(function() {
            $.validator.addMethod("checkStringNumber", function(value) {
                return /^(?=.*[A-Za-z])(?=.*\d)(?=.*[\[\]\/.=,:@$!%*#?&+_-])[A-Za-z\d\[\]\/.=,:@$!%*#?&+_-]{8,}$/.test(value);
            });
            $('#quickForm').validate({
                rules: {
                    password: {
                        required: true,
                        checkStringNumber: true,
                    },
                    password_confirm: {
                        required: true,
                        equalTo: "#inputPassword"
                    },
                },
                messages: {
                    password: {
                        required: "{{ __('two_fa.verify_required') }}",
                        checkStringNumber: "{{ __('two_fa.verify_check_string') }}"
                    },
                    password_confirm: {
                        required: "{{ __('two_fa.verify_confirm_required') }}",
                        equalTo: "{{ __('two_fa.verify_equa_to') }}"
                    },
                },
                errorElement: 'span',
                errorPlacement: function(error, element) {
                    error.addClass('invalid-feedback');
                    element.closest('.form-group').append(error);
                },
                highlight: function(element, errorClass, validClass) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element, errorClass, validClass) {
                    $(element).removeClass('is-invalid');
                }
            });
        });
    </script>
    <script>
        $(function() {
            // toastr option
            toastr.options.escapeHtml = false;
            toastr.options.closeButton = true;
            toastr.options.closeDuration = 0;
            toastr.options.extendedTimeOut = 500;
            toastr.options.timeOut = 5000;
            toastr.options.tapToDismiss = false;
            toastr.options.positionClass = 'toast-top-right';
            @if (session('error'))
                toastr.error("{{ session('error') }}");
            @endif
        });
    </script>
@endsection
