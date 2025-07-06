<aside class="main-sidebar sidebar-light-primary elevation-4">
    <div class="sidebar">
        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                <!-- Add icons to the links using the .nav-icon class
                with font-awesome or any other icon font library -->
                <li class="nav-item" >
                    <a href="{{ route('admin.index') }}" class="nav-link d-inline-flex ">
                        <p class="icon"><img src="{{ asset('assets/admin/img/user.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.admin') }}
                        </p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'category') ? $menu ?? '' : '' }}">
                    <a href="{{ route('admin.category.index') }}" class="nav-link d-inline-flex {{ ($action == 'category-index') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/certificate.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.category_management') }}
                        </p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'tag') ? $menu ?? '' : '' }}">
                    <a href="{{ route('tag.index') }}" class="nav-link d-inline-flex {{ ($action == 'tag-index') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/code.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.tag_management') }}
                        </p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'post') ? $menu ?? '' : '' }}">
                    <a href="{{ route('post.index') }}" class="nav-link d-inline-flex {{ ($action == 'post-index') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/user.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.post_management') }}
                        </p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'domain') ? $menu ?? '' : '' }}">
                    <a href="{{ route('domain.index') }}" class="nav-link d-inline-flex {{ ($action == 'domain-index') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/user.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.domain_management') }}
                        </p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'ads') ? $menu ?? '' : '' }}">
                    <a href="{{ route('ads.index') }}" class="nav-link d-inline-flex {{ ($action == 'ads-index') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/user.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.ads_management') }}
                        </p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'font') ? $menu ?? '' : '' }}">
                    <a href="{{ route('font.index') }}" class="nav-link d-inline-flex {{ ($action == 'font-index') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/user.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.font_management') }}
                        </p>
                    </a>
                </li>
            </ul>
        </nav>
        <!-- /.sidebar-menu -->
    </div>
<!-- /.sidebar -->
</aside>
