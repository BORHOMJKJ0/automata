<?php

// api/index.php

// إعدادات مهمة لـ Vercel
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['DOCUMENT_ROOT'] = __DIR__.'/../public';

// تغيير المسار للمشروع
chdir(__DIR__.'/..');

// تحميل autoload
require __DIR__.'/../vendor/autoload.php';

// تشغيل Laravel
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
