 <!-- Navbar -->
 <nav class="main-header navbar navbar-expand navbar-white navbar-light">
     <!-- Left navbar links -->
     <ul class="navbar-nav">
         <li class="nav-item">
             <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars text-white"></i></a>
         </li>
         <li class="nav-item">
             <a class="nav-link d-inline-flex" href="{{ route('admin.index') }}">
                <b>GENERAL MANAGEMENT</b>
             </a>
         </li>
     </ul>
     <!-- Right navbar links -->
     <ul class="navbar-nav ml-auto">
        <li class="nav-item user-action">
            <a href="#">
                <img src="{{ asset('assets/admin/img/bell.svg') }}" />
            </a>
         </li>
         <li class="nav-item user-action">
            <a href="#">
                <img src="{{ asset('assets/admin/img/setting.svg') }}" />
            </a>
         </li>
         <li class="nav-item user-action">
            <a href="#">
                <img src="{{ asset('assets/admin/img/help.svg') }}" />
            </a>
         </li>
         <li class="nav-item ml-3 user-logout">
            <a href="{{ route('logout') }}" title="{{ __('sidebar.logout') }}" class="btn text-light">{{ __('sidebar.logout') }}</a>
         </li>
     </ul>
 </nav>
 <!-- /.navbar -->
