@extends('layouts.auth.base', ['title' => __('two_fa.resetting_password')])
@section('css')
<link rel="stylesheet" href="{{ asset('assets/plugins/toastr/toastr.min.css') }}">
@endsection
@section('content')
<div class="login-box">
  <div class="login-logo">
    <img src="{{ asset('assets/img/logo.png') }}" alt="">
  </div>
  <div class="card">
    <div class="card-body login-card-body">
        <p class="login-box-msg">{{ __('two_fa.resetting_password') }}</p>

        <form action="{{ route('forgot_password') }}" method="post" id="quickForm">
            @csrf
            <div class="form-group">
              <label class="form-check-label">{{ __('two_fa.email_address') }}</label>
              <div class="input-group mb-3">
                    <input type="text" name="email" class="form-control {{ $errors->has('email') ? ' is-invalid' : '' }}"  placeholder="{{ __('two_fa.email_placeholder') }}">
                    @error('email')
                        <span id="inputName-error" class="error invalid-feedback">{{ $message }}</span>
                    @enderror
              </div>
            </div>
            <div class="row">
                <!-- /.col -->
                <div class="col-12">
                <button type="submit" class="btn btn-primary btn-block btn-auth-custom">{{ __('two_fa.send') }}</button>
                </div>
                <!-- /.col -->
            </div>
        </form>
      <p class="mb-1 help-block text-center mt-3">
        <a href="{{ route('login') }}" class="form-check-label">{{ __('two_fa.back_login') }}</a>
      </p>
    </div>
    <!-- /.login-card-body -->
  </div>
</div>
<!-- /.login-box -->
@endsection
@section('js')
<!-- Toastr -->
<script src="{{ asset('assets/plugins/toastr/toastr.min.js') }}"></script>
<script>
  $(function () {
    $('#quickForm').validate({
      rules: {
        email: {
              required: true,
              email: true,
          }
      },
      messages: {
        email: {
              required: "{{ __('two_fa.email_address_required') }}",
              email: "{{ __('two_fa.format_email') }}",
          },
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
  @if (session('success'))
      toastr.success("{{ session('success') }}");
  @endif
});
</script>
@endsection