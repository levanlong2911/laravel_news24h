<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'FREEKEY レンタカー 管理システム') }} | @yield('title')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Google Font: Spline Sans -->
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Spline+Sans:wght@400;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ asset('assets/plugins/fontawesome-free/css/all.min.css') }}">
    <!-- Theme style -->
    <link rel="stylesheet" href="{{ asset('assets/dist/css/adminlte.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/dist/css/main.css') }}">
    <!-- overlayScrollbars -->
    <link rel="stylesheet" href="{{ asset('assets/plugins/overlayScrollbars/css/OverlayScrollbars.min.css') }}">
    <!-- Toastr -->
    <link rel="stylesheet" href="{{ asset('assets/plugins/toastr/toastr.min.css') }}">
    {{-- <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css"> --}}
    {{-- <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta.2/css/bootstrap.min.css"> --}}

    @yield('import-script-customize')
    <link rel="stylesheet" href="{{ asset('assets/css/common.css') }}">
    {{-- <script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.5.11/ckeditor.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.5.11/adapters/jquery.js"></script> --}}

    @yield('css')
</head>

<body class="hold-transition sidebar-mini layout-absolute" data-panel-auto-height-mode="height">
    <div class="wrapper">
        @include('layouts.navbars.navbar')
        <!-- Main Sidebar Container -->
        @include('layouts.navbars.sidebar')
        <!-- Content Wrapper. Contains page content -->
        <div class="content-wrapper">
            <section class="content-header">
                <div class="container-fluid">
                    <div class="mb-2">
                        <div class="d-flex">
                            <div class="col-sm-12">
                                <h1 class="title-screens"><b>@yield('title')</b></h1>
                            </div>
                            @yield('button_href')
                            @yield('custom_header')
                        </div>
                        <!-- <div class="col-sm-6"> -->
                        <!-- @yield('breadcrumb') -->
                        <!-- </div> -->
                    </div>
                </div><!-- /.container-fluid -->
                <hr>
            </section>
            @yield('content')
            @include('modal.create')
            @include('modal.edit')
        </div>
        <!-- /.content-wrapper -->
        {{-- <!-- @include('layouts.footers.guest') --> --}}
        <!-- Control Sidebar -->
        <aside class="control-sidebar control-sidebar-light">
            <!-- Control sidebar content goes here -->
        </aside>
        <!-- /.control-sidebar -->
    </div>
    <!-- ./wrapper -->
    <!-- jQuery -->
    <script src="{{ asset('assets/plugins/jquery/jquery.min.js') }}"></script>
    <!-- jQuery UI 1.11.4 -->
    <script src="{{ asset('assets/plugins/jquery-ui/jquery-ui.min.js') }}"></script>
    <!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
    <script>
        $.widget.bridge('uibutton', $.ui.button)
    </script>
    <!-- Bootstrap 4 -->
    <script src="{{ asset('assets/plugins/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <!-- Toastr -->
    <script src="{{ asset('assets/plugins/toastr/toastr.min.js') }}"></script>
    <!-- overlayScrollbars -->
    <script src="{{ asset('assets/plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js') }}"></script>
    <!-- AdminLTE App -->
    <script src="{{ asset('assets/dist/js/adminlte.js') }}"></script>
    <script src="{{ asset('assets/dist/js/custom.js') }}"></script>
    @yield('script')
    <script>
        const routeName = "{{ \Request::route()->getName() }}";
        if (localStorage.getItem('current_route_name') === routeName) {
            localStorage.setItem('keep_storage_data', 1);
        } else {
            localStorage.setItem('keep_storage_data', 0);
        }
        localStorage.setItem('current_route_name', routeName);

        // init
        $(window).on('load', function() {
            $('#loading_page_wrapper').hide();
        });

        /**
         * Init const
         */
        // $(".sidebar").height(Math.max($(".content").height(),$(".sidebar").height()));
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
            @if (session('warning'))
                toastr.warning("{{ session('warning') }}");
            @endif
            @if (session('success'))
                toastr.success("{{ session('success') }}");
            @endif
        });
        /**
         * Init const
         */

    </script>
</body>

</html>
