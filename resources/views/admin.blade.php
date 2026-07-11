<!DOCTYPE html>
<html>

<head>
    @php($assetVersion = $version . '.' . max(
        @filemtime(public_path('assets/admin/umi.js')),
        @filemtime(public_path('assets/admin/custom.css'))
    ))
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$assetVersion}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$assetVersion}}">
    <link rel="stylesheet" href="/assets/admin/custom.css?v={{$assetVersion}}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
<div id="root"></div>
<script src="/assets/admin/vendors.async.js?v={{$assetVersion}}"></script>
<script src="/assets/admin/components.async.js?v={{$assetVersion}}"></script>
<script src="/assets/admin/umi.js?v={{$assetVersion}}"></script>
</body>

</html>
