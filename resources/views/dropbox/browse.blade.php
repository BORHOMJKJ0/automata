{{-- resources/views/dropbox/browse.blade.php --}}
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تصفح الملفات</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .folder-item:hover, .file-item:hover {
            background-color: #f8f9fa;
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4">
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1">📁 محتوى المجلد</h4>
                    @if($currentPath)
                        <small>المسار: {{ $currentPath }}</small>
                    @else
                        <small>الجذر</small>
                    @endif
                </div>
                <a href="{{ route('dropbox.index') }}" class="btn btn-light btn-sm">
                    🏠 رابط جديد
                </a>
            </div>

            <div class="card-body p-0">
                {{-- المجلدات --}}
                @if(count($items['folders']) > 0)
                    <div class="p-3 bg-light border-bottom">
                        <h6 class="mb-0">📂 المجلدات ({{ count($items['folders']) }})</h6>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach($items['folders'] as $folder)
                            <form method="POST" action="{{ route('dropbox.folder') }}" class="m-0">
                                @csrf
                                <input type="hidden" name="shared_url" value="{{ $sharedUrl }}">
                                <input type="hidden" name="path" value="{{ $folder['path'] }}">
                                <button type="submit" class="list-group-item list-group-item-action folder-item border-0 d-flex align-items-center">
                                    <span class="fs-4 me-2">📁</span>
                                    <span class="fw-bold">{{ $folder['name'] }}</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif

                {{-- الملفات --}}
                @if(count($items['files']) > 0)
                    <div class="p-3 bg-light border-bottom">
                        <h6 class="mb-0">📄 الملفات ({{ count($items['files']) }})</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>الاسم</th>
                                    <th>الحجم</th>
                                    <th>آخر تعديل</th>
                                    <th>العمليات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($items['files'] as $file)
                                    <tr>
                                        <td class="file-item">
                                            @php
                                                $icon = match($file['extension']) {
                                                    'pdf' => '📕',
                                                    'doc', 'docx' => '📘',
                                                    'xls', 'xlsx' => '📗',
                                                    'jpg', 'jpeg', 'png', 'gif' => '🖼️',
                                                    'zip', 'rar' => '📦',
                                                    'mp4', 'avi' => '🎬',
                                                    'mp3', 'wav' => '🎵',
                                                    default => '📄'
                                                };
                                            @endphp
                                            <span class="me-2">{{ $icon }}</span>
                                            <span>{{ $file['name'] }}</span>
                                        </td>
                                        <td>
                                            @if($file['size'] < 1024)
                                                {{ $file['size'] }} B
                                            @elseif($file['size'] < 1048576)
                                                {{ number_format($file['size'] / 1024, 2) }} KB
                                            @else
                                                {{ number_format($file['size'] / 1048576, 2) }} MB
                                            @endif
                                        </td>
                                        <td>
                                            @if($file['modified'])
                                                {{ date('Y-m-d H:i', strtotime($file['modified'])) }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                @if($file['is_previewable'])
                                                    <form method="POST" action="{{ route('dropbox.preview') }}" target="_blank" class="d-inline">
                                                        @csrf
                                                        <input type="hidden" name="shared_url" value="{{ $sharedUrl }}">
                                                        <input type="hidden" name="path" value="{{ $file['path'] }}">
                                                        <button type="submit" class="btn btn-info">
                                                            👁️ معاينة
                                                        </button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ route('dropbox.download') }}" class="d-inline">
                                                    @csrf
                                                    <input type="hidden" name="shared_url" value="{{ $sharedUrl }}">
                                                    <input type="hidden" name="path" value="{{ $file['path'] }}">
                                                    <button type="submit" class="btn btn-primary">
                                                        ⬇️ تحميل
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if(count($items['folders']) === 0 && count($items['files']) === 0)
                    <div class="p-5 text-center text-muted">
                        <div class="fs-1 mb-3">📭</div>
                        <p class="mb-0">المجلد فارغ</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
