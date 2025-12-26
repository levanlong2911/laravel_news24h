@extends('layouts.base', ['title' => __('admin.admin_management')])
@section('title', __('admin.admin_management'))
@section('content')
    {{-- <section class="content"> --}}
    <div class="container-fluid">
        <div class="col-md-12">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-default">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <div class="float-right">
                                        <div class="btn-group">
                                            <a href="{{ route('admin.add') }}"
                                                class="btn btn-primary btn-block"><b>{{ __('admin.create_new_account') }}</b></a>
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
                                                    <th class="text-center">{{ __('admin.name') }}</th>
                                                    <th class="text-center">{{ __('admin.email') }}</th>
                                                    <th class="text-center">{{ __('admin.role') }}</th>
                                                    <th class="text-center">{{ __('admin.domain') }}</th>
                                                    <th class="text-center">{{ __('admin.detail') }}</th>
                                            </thead>
                                            <tbody>
                                                @foreach ($dataAdmin as $admin)
                                                    <tr>
                                                        <td class="text-center">{{ $admin->name }}</td>
                                                        <td class="text-center">{{ $admin->email }}</td>
                                                        <td class="text-center">{{ data_get($admin, 'role.name') }}</td>
                                                        <td class="text-center">{{ data_get($admin, 'domains.host') }}</td>
                                                        <td class="text-center">
                                                            <a href="{{ route('admin.detail',['id' => $admin->id]) }}">
                                                                {{ __('admin.detail') }}
                                                            </a>
                                                            <a href="{{ route('admin.update',['id' => $admin->id]) }}">
                                                                {{ __('admin.update') }}
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer clearfix">
                                        {{ $dataAdmin->appends(request()->except('page'))->links('pagination::bootstrap-4') }}
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
    {{-- </section> --}}
@endsection
