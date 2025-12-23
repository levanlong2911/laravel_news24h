@extends('layouts.base', ['title' => __('post.list_post')])
@section('title', __('post.list_post'))
@section('content')
    {{-- <section class="content"> --}}
    <div class="container-fluid">
        <div class="col-md-12">
            <div class="row">
                <div class="col-md-12">
                    {{-- <div class="row">
                        <!-- FormValidation -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body line-input">
                                    <form id="name" class="row g-3 fv-plugins-bootstrap5 fv-plugins-framework" novalidate="novalidate">
                                        <div class="col-6 fv-plugins-icon-container inputMessage">
                                            <label class="form-label" for="tag">{{ __('post.tag') }}</label>
                                            <input type="text" id="tag" class="form-control" value="{{ request()->input('tag') ?? request()->input('tag') }}" placeholder="John Doe" name="tag">
                                            @error('tag')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                        <div class="col-6 fv-plugins-icon-container inputMessage">
                                            <label class="form-label" for="answer">{{ __('post.answer') }}</label>
                                            <input class="form-control{{ $errors->has('answer') ? ' is-invalid' : '' }}" type="number" id="answer" value="{{ request()->input('answer') ?? request()->input('answer') }}" name="answer">
                                            @error('answer')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                        <div class="col-6 fv-plugins-icon-container inputMessage">
                                            <label class="form-label" for="level_id">{{ __('post.level') }}</label>
                                            <select class="form-control {{ $errors->has('level_id') ? ' is-invalid' : '' }}"
                                                name="level_id">
                                                <option value="">{{ __('post.level') }}</option>
                                                @foreach ($levels as $level)
                                                    <option value="{{ $level->id }}"
                                                        {{ $level->id == request()->input('level_id') ? 'selected' : '' }}>
                                                        {{ $level->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-6 fv-plugins-icon-container inputMessage">
                                            <label class="form-label" for="language_id">{{ __('post.language') }}</label>
                                            <select class="form-control {{ $errors->has('language_id') ? ' is-invalid' : '' }}"
                                                name="language_id">
                                                <option value="">{{ __('post.language') }}</option>
                                                @foreach ($languages as $language)
                                                    <option value="{{ $language->id }}"
                                                        {{ $language->id == request()->input('language_id') ? 'selected' : '' }}>
                                                        {{ $language->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-12 pt-2 text-right">
                                            <button type="submit" class="btn btn-primary float-btn">{{ __('post.search') }}</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- /FormValidation -->
                    </div> --}}
                    <div class="card card-default">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <div class="float-right">
                                        <div class="btn-group">
                                            <a href="{{ route('post.add') }}"
                                                class="btn btn-primary btn-block"><b>{{ __('post.add') }}</b></a>
                                        </div>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-danger btn-block disabled-button reload"
                                                onclick="deleteMulti()"
                                                disabled><b>{{ __('post.delete_selected') }}</b></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="policy">
                                <div>
                                    <div class="card-body table-responsive p-0">
                                        <table class="table box-list table-bordered table-striped">
                                            <thead class="bg-th-blue">
                                                <tr class="background-title-table">
                                                    <th scope="col" class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                        <div
                                                            class="d-flex align-items-center justify-content-center form-check">
                                                            <input type="checkbox" id="check_select_all"
                                                                class="form-check-input">
                                                            <label for="check_select_all"
                                                                class="form-check-label form-checkbox text-box-label"></label>
                                                        </div>
                                                    </th>
                                                    <th class="text-center">{{ __('post.title') }}</th>
                                                    <th class="text-center">{{ __('post.category') }}</th>
                                                    <th class="text-center">{{ __('post.domain') }}</th>
                                                    <th class="text-center">{{ __('post.day') }}</th>
                                                    <th class="text-center">{{ __('post.detail') }}</th>
                                            </thead>
                                            <tbody>
                                                @foreach ($listsPost as $post)
                                                    <tr id="id_tr_{{ $post->id }}" data-id="{{ $post->id }}">
                                                        <td class="pl-0 pr-0" style="vertical-align: middle !important;">
                                                            <div
                                                                class="d-flex align-items-center justify-content-center form-check">
                                                                <input id="id_tag{{ $post->id }}" type="checkbox"
                                                                    class="check-select is-edit is-choose form-check-input"
                                                                    value="{{ $post->id }}">
                                                                <label for="id_tag{{ $post->id }}"
                                                                    class="form-check-label form-checkbox text-box-label"></label>
                                                            </div>
                                                            <input type="text" hidden name="id[{{ $post->id }}]"
                                                                value="{{ $post->id }}">
                                                        </td>
                                                        <td>
                                                            {{ $post->title }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ data_get($post, 'category.name') }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $post->domain }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $post->created_at->format('d-m-Y') }}
                                                        </td>
                                                        <td class="text-center">
                                                            <a href="https://caranddriverenthusiast.com/post/{{ $post->slug }}">
                                                                {{ __('post.detail') }}
                                                            </a>
                                                            <a href="{{ route('post.update', ['id' => $post->id]) }}">
                                                                {{ __('post.update') }}
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="card-footer clearfix">
                                        {{ $listsPost->appends(request()->except('page'))->links('pagination::bootstrap-4') }}
                                    </div>
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="float-right">
                                                <div class="btn-group">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- The Modal -->

    {{-- </section> --}}
@endsection
@section('script')
    <script src="{{ asset('assets/dist/js/commonHandleList.js') }}"></script>
    <script>
        // const
        const MODAL_CONFIRM_URL = "{{ route('modal.confirm') }}";
        var STORAGE_NAME = "level_selected_storage";
        var DELETE_URL = "{{ route('post.delete') }}";
        // get list client init
        var listIds = <?php echo json_encode($listIdPost); ?>;
        listIds = listIds.map(String);
    </script>
@endsection
