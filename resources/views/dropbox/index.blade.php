{{-- resources/views/dropbox/index.blade.php --}}
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dropbox Browser</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0">📦 Dropbox File Browser</h3>
                    </div>
                    <div class="card-body">
                        @if(session('error'))
                            <div class="alert alert-danger">
                                ❌ {{ session('error') }}
                            </div>
                        @endif

                        <form method="POST" action="{{ route('dropbox.browse') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-bold">أدخل رابط مجلد Dropbox:</label>
                                <input type="url"
                                       name="dropbox_url"
                                       class="form-control form-control-lg"
                                       placeholder="https://www.dropbox.com/scl/fo/..."
                                       required>
                                <small class="text-muted">
                                    مثال: https://www.dropbox.com/scl/fo/abc123/xyz?rlkey=...
                                </small>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                🔍 عرض المحتوى
                            </button>
                        </form>

                        <div class="mt-4 p-3 bg-light rounded">
                            <h6 class="fw-bold">💡 ملاحظات:</h6>
                            <ul class="mb-0 small">
                                <li>تأكد من أن الرابط مشارك</li>
                                <li>يجب أن يكون لديك صلاحيات الوصول</li>
                                <li>يدعم المجلدات والمجلدات الفرعية</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
