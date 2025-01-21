@extends('layouts.base', ['title' => __('tag.create_new_account')])
@section('title', __('tag.create_new_account'))
@section('css')
    <link rel="stylesheet" href="{{ asset('assets/css/tag.css') }}">
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script>
        $(function() {
            $('#quickForm').validate({
                rules: {
                    category: {
                        required: true,
                    },
                    tags: {
                        required: true,
                    },
                },
                messages: {
                    category: {
                        required: "{{ __('category.validate_tag_required') }}",
                    },
                    tags: {
                        required: "{{ __('tag.validate_tag_required') }}",
                    },
                },
                errorElement: 'span',
                errorPlacement: function(error, element) {
                    error.addClass('invalid-feedback');
                    element.closest('.inputMessage').append(error);
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
    {{-- <script src="https://code.jquery.com/jquery-3.2.1.min.js"></script> --}}
    <script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.5.11/ckeditor.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.5.11/adapters/jquery.js"></script>
    {{-- <script src="{{ asset('ckeditor/ckeditor.js') }}"></script> --}}
    <script>
        var route_prefix = "/filemanager";
    </script>
    <script>
        {!! \File::get(base_path('vendor/unisharp/laravel-filemanager/public/js/stand-alone-button.js')) !!}
      </script>
      <script>
        $('#editor_content').filemanager('image', {prefix: route_prefix});
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
    {{-- <script> CKEDITOR.replace('editor_content', options); </script> --}}
@endsection
@section('content')
    {{-- <section class="content"> --}}
        <section class="content">
            <form id="quickForm" action="{{ route('tag.add') }}" method="post">
                @csrf
                <div class="row">
                <div class="col-md-9">
                    <div class="card card-primary">
                        <div class="card-body">
                            <div class="form-group">
                                {{-- <div class="col-2 d-flex align-items-center"> --}}
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('admin.name') }}<span
                                            style="color: red; "> *</span></p>
                                </div>
                                <div class="col-10 pl-0">
                                    <div class="input inputMessage">
                                        <input type="text" value="{{ old('name') ?? old('name') }}"
                                            class="form-control{{ $errors->has('name') ? ' is-invalid' : '' }} col-6"
                                            name="name" id="name" placeholder="">
                                        @error('name')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="inputDescription">Project Description</label>
                                <textarea id="editor_content" name="editor_content" class="form-control" rows="4"></textarea>
                              </div>
                            <div class="form-group">
                            <label for="inputStatus">Status</label>
                            <select id="inputStatus" class="form-control custom-select">
                                <option selected="" disabled="">Select one</option>
                                <option>On Hold</option>
                                <option>Canceled</option>
                                <option>Success</option>
                            </select>
                            </div>
                            <div class="form-group">
                            <label for="inputClientCompany">Client Company</label>
                            <input type="text" id="inputClientCompany" class="form-control">
                            </div>
                            <div class="form-group">
                            <label for="inputProjectLeader">Project Leader</label>
                            <input type="text" id="inputProjectLeader" class="form-control">
                            </div>
                        </div>
                    <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                </div>
                <div class="col-md-3">
                    <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title">Budget</h3>

                        <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                            <i class="fas fa-minus"></i>
                        </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                        <label for="inputEstimatedBudget">Estimated budget</label>
                        <input type="number" id="inputEstimatedBudget" class="form-control">
                        </div>
                        <div class="form-group">
                        <label for="inputSpentBudget">Total amount spent</label>
                        <input type="number" id="inputSpentBudget" class="form-control">
                        </div>
                        <div class="form-group">
                        <label for="inputEstimatedDuration">Estimated project duration</label>
                        <input type="number" id="inputEstimatedDuration" class="form-control">
                        </div>
                    </div>
                    <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                </div>
                </div>
                <div class="row">
                <div class="col-12">
                    <a href="#" class="btn btn-secondary">Cancel</a>
                    <input type="submit" value="Create new Project" class="btn btn-success float-right">
                </div>
                </div>
        </form>
          </section>
    {{-- </section> --}}
@endsection
