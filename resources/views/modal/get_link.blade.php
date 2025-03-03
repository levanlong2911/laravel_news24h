<!-- The Modal -->
<div class="modal fade" id="getLink">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <!-- Modal body -->
            <div class="modal-body">
                <form id="quickFormModal">
                    @csrf
                    <div class="row row mg-bt">
                        <div class="col-2 d-flex align-items-center">
                            <p class="align-middle p-0 m-0">{{ __('post.url') }}<span
                                    style="color: red; "> *</span></p>
                        </div>
                        <div class="col-10 pl-0">
                            <div class="input inputMessage">
                                <input type="url" value="{{ old('url') ?? old('url') }}"
                                    class="form-control{{ $errors->has('url') ? ' is-invalid' : '' }} col-12"
                                    name="url" id="url" placeholder="{{ __('post.placeholder_url') }}">
                                @error('url')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn button-back"
                        data-dismiss="modal">{{ __('modal.cancel') }}</button>
                        <button type="submit" class="btn btn-primary btn-main--modal text-light">{{ __('modal.get_link') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
    $(document).ready(function() {
        // Cấu hình jQuery Validate
        $('#quickFormModal').validate({
            rules: {
                url: {
                    required: true,
                    url: true
                }
            },
            messages: {
                url: {
                    required: "{{ __('post.url_required') }}",
                    url: "{{ __('post.url_false') }}"
                }
            },
            errorElement: 'span',
            errorPlacement: function(error, element) {
                error.addClass('invalid-feedback');
                element.closest('.inputMessage').append(error);
            },
            highlight: function(element) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function(element) {
                $(element).removeClass('is-invalid');
            }
        });

        // Reset form khi modal bị đóng
        $('#getLink').on('hidden.bs.modal', function() {
            $('#quickFormModal')[0].reset();
            $('#quickFormModal').validate().resetForm();
            $('.is-invalid').removeClass('is-invalid'); // Xóa class lỗi
        });

        // Cấu hình CSRF token mặc định cho Ajax
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        });

        // Xử lý gửi form bằng Ajax
        $('#quickFormModal').on('submit', function(e) {
            e.preventDefault(); // Ngăn form submit theo cách thông thường

            if (!$('#quickFormModal').valid()) return; // Kiểm tra hợp lệ

            $.ajax({
                url: '/admin/getlink',
                method: 'POST',
                data: $(this).serialize(),
                success: function(response) {
                    if (response.success) {
                        $('#title').val(response.title); // Gán tiêu đề
                        $('#editor_content').val(response.content); // Gán nội dung
                        $('#getLink').modal('hide'); // Đóng modal
                    } else {
                        alert(response.message);
                    }
                },
                error: function(xhr) {
                    alert('Lỗi: ' + xhr.responseText);
                }
            });
        });
    });
</script>

