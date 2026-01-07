@extends('layouts.base', ['title' => __('post.update_post')])
@section('title', __('post.update_post'))
@section('css')
    <link rel="stylesheet" href="{{ asset('assets/css/tag.css') }}">
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
    <script>
        $.validator.setDefaults({
            ignore: []
        });
        $('#quickForm').on('submit', function() {
            Object.keys(CKEDITOR.instances).forEach(function(instance) {
                CKEDITOR.instances[instance].updateElement();
            });
        });
        $(document).ready (function() {
            $('#quickForm').validate({
                rules: {
                    title: {
                        required: true,
                        maxlength: 500,
                        minlength: 5,
                    },
                    slug: {
                        required: true,
                    },
                    editor_content: {
                        required: true,
                        minlength: 200,
                    },
                    category: {
                        required: true,
                    },
                    tag_names: {
                        required: true,
                    },
                    image: {
                        required: true,
                        url: true,
                    },
                },
                messages: {
                    title: {
                        required: "{{ __('post.validate_title_required') }}",
                        maxlength: "{{ __('post.validate_max_required') }}",
                        minlength: "{{ __('post.validate_min_title_required') }}",
                    },
                    slug: {
                        required: "{{ __('post.validate_title_required') }}",
                    },
                    editor_content: {
                        required: "{{ __('post.validate_editor_content_required') }}",
                        minlength: "{{ __('post.validate_editor_content_required') }}",
                    },
                    category: {
                        required: "{{ __('post.validate_category_required') }}",
                    },
                    tag_names: {
                        required: "{{ __('post.validate_tag_required') }}",
                    },
                    image: {
                        required: "{{ __('post.validate_image_required') }}",
                        url: "{{ __('post.validate_url_required') }}",
                    },
                },
                errorElement: 'span',
                errorPlacement: function(error, element) {
                    if (element.closest('.inputMessage').length) {
                        error.addClass('invalid-feedback');
                        element.closest('.inputMessage').append(error);
                    } else {
                        error.insertAfter(element);
                    }
                },
                highlight: function(element, errorClass, validClass) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element, errorClass, validClass) {
                    $(element).removeClass('is-invalid');
                }
            });
        })
    </script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.5.11/ckeditor.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.5.11/adapters/jquery.js"></script>
    <script>
        var route_prefix = "/filemanager";
    </script>
    <script>
        {!! \File::get(base_path('vendor/unisharp/laravel-filemanager/public/js/stand-alone-button.js')) !!}
    </script>
    <script>
        $('#editor_content').filemanager('image', {
            prefix: route_prefix
        });
        // $('#lfm').filemanager('file', {prefix: route_prefix});
    </script>
    <script>
        $('textarea[name=editor_content]').ckeditor({
            height: 100,
            filebrowserImageBrowseUrl: route_prefix + '?type=Images',
            filebrowserImageUploadUrl: route_prefix + '/upload?type=Images&_token=',
            filebrowserBrowseUrl: route_prefix + '?type=Files',
            filebrowserUploadUrl: route_prefix + '/upload?type=Files&_token='
        });
    </script>
    <script>
        $('#lfm').filemanager('image', {
            prefix: route_prefix
        });
        // $('#lfm').filemanager('file', {prefix: route_prefix});
    </script>

    <script>
        var lfm = function(id, type, options) {
            let button = document.getElementById(id);

            button.addEventListener('click', function() {
                var route_prefix = (options && options.prefix) ? options.prefix : '/filemanager';
                var target_input = document.getElementById(button.getAttribute('data-input'));
                var target_preview = document.getElementById(button.getAttribute('data-preview'));

                window.open(route_prefix + '?type=' + options.type || 'file', 'FileManager',
                    'width=900,height=600');
                window.SetUrl = function(items) {
                    var file_path = items.map(function(item) {
                        return item.url;
                    }).join(',');

                    // set the value of the desired input to image url
                    target_input.value = file_path;
                    target_input.dispatchEvent(new Event('change'));

                    // clear previous preview
                    target_preview.innerHtml = '';

                    // set or change the preview image src
                    items.forEach(function(item) {
                        let img = document.createElement('img')
                        img.setAttribute('style', 'height: 5rem')
                        img.setAttribute('src', item.thumb_url)
                        target_preview.appendChild(img);
                    });

                    // trigger change event
                    target_preview.dispatchEvent(new Event('change'));
                };
            });
        };

        lfm('lfm2', 'file', {
            prefix: route_prefix
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {

            const editorContentId = 'editor_content';
            const titleInput = document.getElementById("title");

            /* =============================
            *  INIT CKEDITOR
            * ============================= */
            if (!CKEDITOR.instances[editorContentId]) {
                CKEDITOR.replace(editorContentId, {
                    entities: false,
                    entities_latin: false,
                    autoParagraph: false
                });
            }

            function getEditorContent() {
                return CKEDITOR.instances[editorContentId].getData();
            }

            function setEditorContent(content) {
                const editor = CKEDITOR.instances[editorContentId];
                if (!editor) return;

                editor.setData(content, function () {
                    editor.updateElement();
                });
            }

            /* =============================
            *  REPLACE MAP
            * ============================= */
            const MAP_TO_FAKE = {
                "h": "Һ",
                "k": "ƙ"
            };

            const MAP_TO_REAL = {
                "Һ": "h",
                "ƙ": "k"
            };

            /* =============================
            *  SAFE TEXT NODE REPLACE
            * ============================= */
            function replaceMultipleInNode(node, replaceMap) {
                if (node.nodeType === 3) {
                    let text = node.nodeValue;

                    for (const [find, replace] of Object.entries(replaceMap)) {
                        text = text.replace(new RegExp(find, "g"), replace);
                    }

                    node.nodeValue = text;
                }
                else if (node.nodeType === 1) {

                    // Skip các thẻ không nên đụng
                    const skipTags = ['SCRIPT', 'STYLE', 'CODE', 'PRE'];
                    if (skipTags.includes(node.tagName)) return;

                    node.childNodes.forEach(child => replaceMultipleInNode(child, replaceMap));
                }
            }

            /* =============================
            *  MAIN REPLACE FUNCTION
            * ============================= */
            function replaceMultipleText(replaceMap) {

                /* Replace title */
                if (titleInput) {
                    let value = titleInput.value;
                    for (const [find, replace] of Object.entries(replaceMap)) {
                        value = value.replace(new RegExp(find, "g"), replace);
                    }
                    titleInput.value = value;
                }

                /* Replace CKEditor content */
                let content = getEditorContent();
                let tempDiv = document.createElement("div");
                tempDiv.innerHTML = content;

                replaceMultipleInNode(tempDiv, replaceMap);

                setEditorContent(tempDiv.innerHTML);
            }

            /* =============================
            *  BUTTON EVENTS
            * ============================= */
            document.getElementById("replaceToGreekI").addEventListener("click", function () {
                replaceMultipleText(MAP_TO_FAKE);
            });

            document.getElementById("replaceToLatinI").addEventListener("click", function () {
                replaceMultipleText(MAP_TO_REAL);
            });

        });
    </script>
    <script>
        $(document).ready(function () {
            function loadTags(categoryId) {
                if (categoryId) {
                    $.ajax({
                        url: '{{ url("admin/get-tags") }}',
                        type: 'GET',
                        data: { category_id: categoryId },
                        success: function (data) {
                            if (data.length > 0) {
                                let tagNames = data.map(tag => tag.name).join(', '); // Chuỗi tag name
                                let tagIds = data.map(tag => tag.id).join(', '); // Chuỗi tag id

                                $('#tag').val(tagNames); // Hiển thị tên tag trong input
                                $('#tag-hidden').val(tagIds); // Lưu ID vào input hidden
                            } else {
                                $('#tag').val('');
                                $('#tag-hidden').val('');
                            }
                        },
                        error: function () {
                            alert('Không thể lấy danh sách tags!');
                        }
                    });
                } else {
                    $('#tag').val('');
                    $('#tag-hidden').val('');
                }
            }

            // Khi trang load lại, nếu có dữ liệu cũ => tự động load tags
            $('#category').change(function () {
                loadTags($(this).val());
            }).trigger('change');

            // Khi chọn category mới, load lại tags
            $('#category').change(function () {
                loadTags($(this).val());
            });
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {

            // Lấy username đang đăng nhập từ Laravel
            let currentUser = "{{ Auth::user()->name ?? 'user' }}";

            // Hàm tạo slug
            function generateSlug(title) {
                let slug = title
                    .toLowerCase()
                    .normalize("NFD").replace(/[\u0300-\u036f]/g, "") // Bỏ dấu tiếng Việt
                    .replace(/[^a-z0-9]+/g, "-")
                    .replace(/^-+|-+$/g, "");

                let userSlug = currentUser
                    .toLowerCase()
                    .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
                    .replace(/[^a-z0-9]+/g, "-")
                    .replace(/^-+|-+$/g, "");

                return slug + "-" + userSlug;
            }

            // Khi user chỉnh title → auto cập nhật slug
            document.getElementById("title").addEventListener("input", function () {
                let title = this.value;
                let newSlug = generateSlug(title);
                document.getElementById("slug").value = newSlug;
            });

            // Khi lấy link API (success) → auto cập nhật slug theo title
            $(document).ajaxSuccess(function (event, xhr, settings) {
                if (settings.url.includes("get-link")) { // đúng API lấy link
                    let response = xhr.responseJSON;
                    if (response && response.title) {
                        let autoSlug = generateSlug(response.title);
                        $("#slug").val(autoSlug);
                    }
                }
            });

        });
    </script>

@endsection
@section('content')
    <section class="content">
        <form id="quickForm" action="{{ route('post.update', $listPost->id) }}" method="post">
            @csrf
            <div class="row">
                <div class="col-md-9">
                    <div class="card card-primary">
                        <div class="card-body">
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('admin.title') }}<span style="color: red; ">
                                            *</span></p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <input type="text" value="{{ $listPost->title }}"
                                            class="form-control{{ $errors->has('title') ? ' is-invalid' : '' }} col-12"
                                            name="title" id="title" placeholder="">
                                        @error('title')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.slug') }}<span style="color: red; ">
                                            *</span></p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <input type="text" value="{{ $listPost->slug }}"
                                            class="form-control{{ $errors->has('slug') ? ' is-invalid' : '' }} col-12"
                                            name="slug" id="slug" placeholder="" readonly>
                                        @error('slug')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('admin.content') }}<span style="color: red; ">
                                            *</span></p>
                                </div>
                                {{-- <label for="">{{ __('admin.content') }}</label> --}}
                                <textarea id="editor_content" name="editor_content" class="form-control {{ $errors->has('editor_content') ? ' is-invalid' : '' }}" rows="4" style="height: 1000px;">
                                    {{ $listPost->content }}
                                </textarea>
                                @error('title')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>
                        <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                </div>
                <div class="col-md-3">
                    <div class="card card-secondary">
                        <div class="card-body">
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.category') }}
                                        <span style="color: red; ">*</span>
                                    </p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <select
                                            class="form-control {{ $errors->has('category') ? ' is-invalid' : '' }} col-12"
                                            name="category" id="category">
                                            <option value>Select category</option>
                                            @foreach ($listsCate as $item)
                                                <option value="{{ $item->id }}" {{ old('category') ? (old('category') == $item->id ? 'selected' : '') : ($listPost->category_id == $item->id ? 'selected' : '') }}>{{  $item->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('name')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.tag') }}
                                        <span style="color: red; ">*</span>
                                    </p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div id="tag-container">
                                        <input type="text" value="{{ old('tag_names') ?? old('tag_names') }}"
                                            class="form-control{{ $errors->has('tag') ? ' is-invalid' : '' }} col-12"
                                            name="tag_names" id="tag" readonly>
                                        {{-- @error('tag')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror --}}
                                        <input type="hidden" name="tagIds" id="tag-hidden" value="{{ old('tag') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.image') }}
                                        <span style="color: red; ">*</span>
                                    </p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <span class="input-group-btn">
                                            <a id="lfm" data-input="thumbnail" data-preview="holder"
                                                class="btn btn-primary text-white" style="margin-bottom:10px;">
                                                Choose image
                                            </a>
                                        </span>
                                        <input id="thumbnail" type="text" value="{{ $listPost->thumbnail }}"
                                            class="form-control{{ $errors->has('image') ? ' is-invalid' : '' }} col-12"
                                            name="image" placeholder="">
                                        @error('image')
                                            <span class="invalid-feedback d-block" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                    <div id="holder" style="margin-top:15px;max-height:100px;"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.convert_font') }}
                                        <span style="color: red; ">*</span>
                                    </p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <button type="button" id="replaceToGreekI" class="btn btn-warning col-5">
                                            Convet
                                        </button>
                                        <button type="button" id="replaceToLatinI" class="btn btn-info col-5">
                                            Revert
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <a href="{{ route('post.index') }}"
                                        class="btn button-center button-back btn-detail-custom">{{ __('admin.back') }}
                                    </a>
                                    <button type="submit"
                                    class="btn button-right button-create-update" id="edit_form">
                                        {{ __('post.update') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                </div>
            </div>
        </form>
    </section>
@endsection
