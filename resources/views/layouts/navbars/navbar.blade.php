 <!-- Navbar -->
 <nav class="main-header navbar navbar-expand navbar-white navbar-light">
     <!-- Left navbar links -->
     <ul class="navbar-nav">
         <li class="nav-item">
             <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars text-white"></i></a>
         </li>
         <li class="nav-item">
             <a class="nav-link text-light pl-0 pt-1" href="{{ route('admin.index') }}">
                <img style="width: 150px" src="{{ asset('assets/admin/img/logo_light.png') }}" alt="Goline globle">
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