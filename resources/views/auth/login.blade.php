@extends('layouts.auth.base', ['title' => __('auth.login')])
@section('content')
<div class="login-box">
  <div class="login-logo">
    {{-- <img src="{{ asset('assets/img/logo.png') }}" alt=""> --}}
    <h1>Login</h1>
  </div>
  <div class="card">
    <div class="card-body login-card-body">
        <form action="{{ route('login') }}" method="post" id="quickForm">
            @csrf
            <div class="form-group">
                @if (Session::has('error'))
                    <div style="color:red">
                        {{ Session::get('error') }}
                    </div>
                @endif
            </div>
            <div class="form-group">
              {{-- <label class="form-check-label">{{ __('auth.user_id') }}</label> --}}
              <div class="form-group input-group mb-3">
                    <input type="email" name="email" class="form-control" placeholder="admin@gmail.com" value="{{ old('email') }}">
              </div>
                    @error('email')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
            </div>
            <div class="form-group">
                {{-- <label class="form-check-label">{{ __('auth.password') }}</label> --}}
                <div class="input-group mb-3">
                    <input type="password" name="password" class="form-control" placeholder="Password">
                </div>
                    @error('password')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
            </div>
            <div class="row mt-2">
                <div class="col-12">
                  <button type="submit" class="btn btn-primary btn-block btn-auth-custom">{{ __('auth.login') }}</button>
                </div>
                <div class="col-12 mt-2">
                  <div class="icheck-primary">
                      <input type="checkbox" id="remember" name="remember">
                      <label for="remember" class="form-check-label lable-login-remember-me"><span style="margin-left: -9px;">{{ __('auth.remember_me') }}</span></label>
                  </div>
                  <hr/>
                </div>
            </div>
        </form>

      <!-- /.social-auth-links -->
      <p class="mb-1 help-block">
        {{-- <a href="{{ route('forgot_password') }}" class="form-check-label text-dark forgot-password">{{ __('auth.forgot_password') }}</a> --}}
      </p>
    </div>
    <!-- /.login-card-body -->
  </div>
</div>
<!-- /.login-box -->
@endsection
@section('js')
<script>
  $(function () {
      $('#quickForm').validate({
        rules: {
            email:{
              required: true,
            },
            password: {
                required: true,
                // minlength: 8, // Đảm bảo mật khẩu có ít nhất 8 ký tự
                // pattern: /^[A-Z][A-Za-z\d!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]*[!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\/?]+.*$/
            }
        },
        messages: {
            email: {
              required: "{{ __('messages.user_id_required') }}",
              email: "{{ __('messages.email_format') }}"
            },
            password: {
              required: "{{ __('messages.password_required') }}",
              // minlength: "{{ __('messages.minlength') }}",
              // pattern: "{{ __('messages.pattern') }}",
            }
        },
        errorElement: 'span',
        errorPlacement: function (error, element) {
            error.addClass('invalid-feedback');
            element.closest('.form-group').append(error);
        },
        highlight: function (element, errorClass, validClass) {
            $(element).addClass('is-invalid');
        },
        unhighlight: function (element, errorClass, validClass) {
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
