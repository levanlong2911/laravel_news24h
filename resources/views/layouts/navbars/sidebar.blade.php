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
                            {{ __('sidebar.post.management') }}
                        </p>
                    </a>
                </li>
                {{-- <li class="nav-item {{ ($route == 'question') ? $menu ?? '' : '' }}">
                    <a href="{{ route('question.index') }}" class="nav-link d-inline-flex {{ ($action == 'question-index') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/question.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.question') }}
                        </p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'exam_resuilt') ? $menu ?? '' : '' }}">
                    <a href="{{ route('exam_resuilt.index') }}" class="nav-link d-inline-flex {{ ($action == 'exam_resuilt-index') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/exam.svg') }}" /></p>
                        <p>
                            {{ __('exam_resuilt.title') }}
                        </p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'spin') ? $menu ?? '' : '' }}">
                    <a href="{{ route('job-fair.spin.magagement') }}" class="nav-link d-inline-flex {{ ($action == 'job-fair.spin') ? $active ?? '' : '' }}">
                        <p class="icon"><img src="{{ asset('assets/admin/img/spin.svg') }}" /></p>
                        <p>
                            {{ __('sidebar.spin') }}
                        </p>
                    </a>
                </li> --}}
            </ul>
        </nav>
        <!-- /.sidebar-menu -->
    </div>
<!-- /.sidebar -->
</aside>
