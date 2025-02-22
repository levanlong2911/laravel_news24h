<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }}</title>

  <!-- Google Font: Source Sans Pro -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{ asset('assets/plugins/fontawesome-free/css/all.min.css') }}">
  <!-- icheck bootstrap -->
  <link rel="stylesheet" href="{{ asset('assets/plugins/icheck-bootstrap/icheck-bootstrap.min.css') }}">
  <!-- Toastr -->
  <!-- Theme style -->
  <link rel="stylesheet" href="{{ asset('assets/dist/css/adminlte.min.css') }}">
  <!-- custom auth css -->
  <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}">
  <link rel="stylesheet" href="{{ asset('assets/css/policy.css') }}">
  @yield('css')
</head>
<body class="hold-transition login-page">