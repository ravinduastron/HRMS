@extends('layouts.app')
@section('title', 'Edit '.$vendor->name)
@section('content')
<x-page-header :title="'Edit '.$vendor->name" description="Contact details only. Hire purchases already created keep pointing to this vendor." />
<x-card>@include('hire-purchases.vendors._form')</x-card>
@endsection
