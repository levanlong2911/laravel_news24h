<aside class="main-sidebar sidebar-light-primary elevation-4">
    <div class="sidebar">
        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                <!-- Add icons to the links using the .nav-icon class
                with font-awesome or any other icon font library -->
                @if(auth()->user()?->isAdmin())
                <li class="nav-item">
                    <a href="{{ route('admin.index') }}" class="nav-link d-inline-flex">
                        <i class="nav-icon fas fa-user-shield"></i>
                        <p>{{ __('sidebar.admin') }}</p>
                    </a>
                </li>
                @endif
                <li class="nav-item {{ ($route == 'category') ? $menu ?? '' : '' }}">
                    <a href="{{ route('admin.category.index') }}" class="nav-link d-inline-flex {{ ($action == 'category-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-folder-open"></i>
                        <p>{{ __('sidebar.category_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'news-web') ? $menu ?? '' : '' }}">
                    <a href="{{ route('news-web.index') }}" class="nav-link d-inline-flex {{ ($action == 'news-web-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-globe"></i>
                        <p>{{ __('sidebar.news-web') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'tag') ? $menu ?? '' : '' }}">
                    <a href="{{ route('tag.index') }}" class="nav-link d-inline-flex {{ ($action == 'tag-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-tags"></i>
                        <p>{{ __('sidebar.tag_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'post') ? $menu ?? '' : '' }}">
                    <a href="{{ route('post.index') }}" class="nav-link d-inline-flex {{ ($action == 'post-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-file-alt"></i>
                        <p>{{ __('sidebar.post_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'domain') ? $menu ?? '' : '' }}">
                    <a href="{{ route('domain.index') }}" class="nav-link d-inline-flex {{ ($action == 'domain-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-server"></i>
                        <p>{{ __('sidebar.domain_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'ads') ? $menu ?? '' : '' }}">
                    <a href="{{ route('ads.index') }}" class="nav-link d-inline-flex {{ ($action == 'ads-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-ad"></i>
                        <p>{{ __('sidebar.ads_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'font') ? $menu ?? '' : '' }}">
                    <a href="{{ route('font.index') }}" class="nav-link d-inline-flex {{ ($action == 'font-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-font"></i>
                        <p>{{ __('sidebar.font_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'website') ? $menu ?? '' : '' }}">
                    <a href="{{ route('website.index') }}" class="nav-link d-inline-flex {{ ($action == 'website-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-sitemap"></i>
                        <p>{{ __('sidebar.website_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'article') ? $menu ?? '' : '' }}">
                    <a href="{{ route('article.index') }}" class="nav-link d-inline-flex {{ ($action == 'article-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-newspaper"></i>
                        <p>{{ __('sidebar.article_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'keyword') ? $menu ?? '' : '' }}">
                    <a href="{{ route('keyword.index') }}" class="nav-link d-inline-flex {{ ($action == 'keyword-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-key"></i>
                        <p>Keywords</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'news-source') ? $menu ?? '' : '' }}">
                    <a href="{{ route('news-source.index') }}" class="nav-link d-inline-flex {{ ($action == 'news-source-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-rss"></i>
                        <p>{{ __('sidebar.news-source_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'raw-article') ? $menu ?? '' : '' }}">
                    <a href="{{ route('raw-article.index') }}" class="nav-link d-inline-flex {{ ($action == 'raw-article-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-file-import"></i>
                        <p>{{ __('sidebar.raw-article_management') }}</p>
                    </a>
                </li>
                <li class="nav-item {{ ($route == 'prompt-framework') ? $menu ?? '' : '' }}">
                    <a href="{{ route('prompt-framework.index') }}" class="nav-link d-inline-flex {{ ($action == 'prompt-framework-index') ? $active ?? '' : '' }}">
                        <i class="nav-icon fas fa-robot"></i>
                        <p>Prompt Framework</p>
                    </a>
                </li>
            </ul>
        </nav>
        <!-- /.sidebar-menu -->
    </div>
<!-- /.sidebar -->
</aside>
