@extends('layouts.base', ['title' => __('post.add_post')])
@section('title', __('post.add_post'))
@section('css')
    <link rel="stylesheet" href="{{ asset('assets/css/tag.css') }}">
    {{-- <link rel="stylesheet" href="{{ asset('assets/css/content_addPost.css') }}"> --}}
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
    <script>
        const IS_ADMIN = {{ auth()->user()->isAdmin() ? 'true' : 'false' }};
    </script>
    <script>
        $.validator.setDefaults({
            ignore: []
        });
        $('#quickForm').submit(function() {
            for (var instance in CKEDITOR.instances) {
                CKEDITOR.instances[instance].updateElement();
            }
        });
        $(function() {
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
                    tagIds: {
                        required: true,
                    },
                    image: {
                        required: true,
                        url: true,
                    },
                    // 👇 CHỈ ADMIN MỚI BẮT BUỘC
                    ...(IS_ADMIN ? {
                        domain_id: {
                            required: true,
                        }
                    } : {})
                },
                messages: {
                    title: {
                        required: "{{ __('post.validate_title_required') }}",
                        maxlength: "{{ __('post.validate_max_required') }}",
                        minlength: "{{ __('post.validate_min_title_required') }}",
                    },
                    slug: {
                        required: "{{ __('post.validate_editor_content_required') }}",
                    },
                    editor_content: {
                        required: "{{ __('post.validate_editor_content_required') }}",
                        minlength: "{{ __('post.validate_editor_content_required') }}",
                    },
                    category: {
                        required: "{{ __('post.validate_category_required') }}",
                    },
                    tagIds: {
                        required: "{{ __('post.validate_tag_required') }}",
                    },
                    image: {
                        required: "{{ __('post.validate_image_required') }}",
                        url: "{{ __('post.validate_url_required') }}",
                    },
                    // 👇 CHỈ ADMIN MỚI BẮT BUỘC
                    ...(IS_ADMIN ? {
                        domain_id: {
                            required: "{{ __('post.validate_tag_required') }}",
                        }
                    } : {})
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
    {{-- <script>
        document.addEventListener("DOMContentLoaded", function() {
            let editorContentId = 'editor_content';

            // Kiểm tra CKEditor đã tồn tại chưa
            if (!CKEDITOR.instances[editorContentId]) {
                CKEDITOR.replace(editorContentId, {
                    entities: false, // Tắt auto encode HTML entities
                    entities_latin: false // Ngăn mã hóa các ký tự Latin như "ι"
                });
            }

            let titleInput = document.getElementById("title");

            function getEditorContent() {
                return CKEDITOR.instances[editorContentId].getData();
            }

            function setEditorContent(content) {
                let editor = CKEDITOR.instances[editorContentId];
                if (editor) {
                    editor.setData(content, function() {
                        editor.updateElement(); // Cập nhật lại dữ liệu trong form
                    });
                }
            }

            function replaceText(find, replace) {
                // Thay thế trong input title
                if (titleInput) {
                    titleInput.value = titleInput.value.replace(new RegExp(find, "g"), replace);
                }

                // Thay thế trong CKEditor (content)
                let content = getEditorContent();

                // Chuyển nội dung HTML thành DOM để tránh lỗi phá vỡ cấu trúc
                let tempDiv = document.createElement("div");
                tempDiv.innerHTML = content;

                function replaceInNode(node) {
                    if (node.nodeType === 3) { // Chỉ thay đổi trong text node
                        node.nodeValue = node.nodeValue.replace(new RegExp(find, "g"), replace);
                    } else if (node.nodeType === 1) { // Nếu là element, duyệt tất cả con của nó
                        for (let i = 0; i < node.childNodes.length; i++) {
                            replaceInNode(node.childNodes[i]);
                        }
                    }
                }

                replaceInNode(tempDiv);

                setEditorContent(tempDiv.innerHTML);
            }

            // Nút thay thế "i" → "ι"
            document.getElementById("replaceToGreekI").addEventListener("click", function() {
                replaceText("(?<!&)h(?!ota;)", "Һ"); // Đảm bảo không thay "&iota;"
            });

            // Nút thay thế "ι" → "i"
            document.getElementById("replaceToLatinI").addEventListener("click", function() {
                replaceText("Һ", "h");
            });
        });
    </script> --}}
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
            var oldCategory = $('#category').val();
            if (oldCategory) {
                loadTags(oldCategory);
            }

            // Khi chọn category mới, load lại tags
            $('#category').change(function () {
                loadTags($(this).val());
            });
        });
    </script>
    {{-- <script>
        // let editorContentadd = 'editor_content';
        CKEDITOR.replace('editor_content', {
            contentsCss: [
                CKEDITOR.basePath + 'contents.css', // file mặc định
                '{{ asset("assets/css/content_addPost.css") }}'          // file custom của bạn
            ]
        });
    </script> --}}
@endsection
@section('content')
    <section class="content">
        <div class="get-link">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#getLink">
                {{ __('post.get_link') }}
            </button>
        </div>
        @include('modal.get_link')
        <form id="quickForm" action="{{ route('post.add') }}" method="post">
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
                                        <input type="text" value="{{ old('title') ?? old('title') }}"
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
                                    <p class="align-middle p-0 m-0">{{ __('admin.link') }}<span style="color: red; ">
                                            *</span></p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <input type="text" value="{{ old('slug') ?? (old('slug')) }}"
                                            class="form-control{{ $errors->has('slug') ? ' is-invalid' : '' }} col-12"
                                            name="slug" id="slug" placeholder="">
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
                                    {{ old('editor_content') }}
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
                                                <option value="{{ $item->id }}" {{ (old('category') == $item->id) ? 'selected' : '' }}>{{  $item->name }}</option>
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
                                        <input type="hidden" name="tagIds" id="tag-hidden" value="{{ old('tagIds') }}">
                                    </div>
                                </div>
                            </div>
                            @if(auth()->user()->isAdmin())
                                <div class="form-group">
                                    <div>
                                        <p class="align-middle p-0 m-0">{{ __('post.website') }}
                                            <span style="color: red; ">*</span>
                                        </p>
                                    </div>
                                    <div class="col-12 pl-0">
                                        <div class="input inputMessage">
                                            <select
                                                class="form-control {{ $errors->has('domain_id') ? ' is-invalid' : '' }} col-12"
                                                name="domain_id" id="domain_id">
                                                <option value>Select website</option>
                                                @foreach ($listWebsite as $web)
                                                    <option value="{{ $web->id }}" {{ (old('domain_id') == $web->id) ? 'selected' : '' }}>{{  $web->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('domain_id')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            @endif
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
                                        <input id="thumbnail" type="text" value="{{ old('image') ?? old('image') }}"
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
                                        class="btn button-center button-back btn-detail-custom">{{ __('admin.back') }}</a>
                                    <input type="submit" value="Create Post" class="btn btn-success float-right">
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
