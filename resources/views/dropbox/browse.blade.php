@extends('dropbox.layout')

@section('title', 'تصفح الملفات')

@push('styles')
<style>
    .breadcrumb-custom {
        background: white;
        padding: 1rem 1.5rem;
        border-radius: 15px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .action-bar {
        background: white;
        padding: 1rem 1.5rem;
        border-radius: 15px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 1rem;
    }

    .folder-item {
        background: white;
        border: none;
        border-radius: 12px;
        padding: 1.25rem;
        margin-bottom: 0.75rem;
        transition: all 0.3s ease;
        text-decoration: none;
        color: inherit;
        display: flex;
        align-items: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .folder-item:hover {
        transform: translateX(-8px);
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
        background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
    }

    .folder-icon {
        font-size: 2.5rem;
        margin-left: 1rem;
    }

    .file-table {
        background: white;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .file-row {
        transition: all 0.2s ease;
    }

    .file-row:hover {
        background-color: #f8f9ff;
    }

    .section-header {
        background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
        padding: 1rem 1.5rem;
        border-bottom: 2px solid #e0e0e0;
        font-weight: 600;
    }

    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
    }

    .empty-icon {
        font-size: 5rem;
        opacity: 0.3;
        margin-bottom: 1rem;
    }

    .badge-count {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 50px;
        font-size: 0.875rem;
    }

    .btn-action {
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
        font-size: 0.875rem;
        transition: all 0.2s ease;
    }

    .btn-action:hover {
        transform: translateY(-2px);
    }

    .btn-search-match {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        border: none;
        padding: 0.5rem 1.5rem;
        border-radius: 10px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-search-match:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(245, 87, 108, 0.4);
        color: white;
    }
</style>
@endpush

@section('content')
{{-- Breadcrumb --}}
<div class="breadcrumb-custom mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item">
                <a href="{{ route('dropbox.index') }}">
                    <i class="bi bi-house"></i> الرئيسية
                </a>
            </li>
            @if($currentPath)
                @php
                    $pathParts = array_filter(explode('/', trim($currentPath, '/')));
                    $buildPath = '';
                @endphp
                @foreach($pathParts as $index => $part)
                    @php $buildPath .= '/' . $part; @endphp
                    @if($index === count($pathParts) - 1)
                        <li class="breadcrumb-item active">
                            <i class="bi bi-folder"></i> {{ $part }}
                        </li>
                    @else
                        <li class="breadcrumb-item">
                            <a href="{{ route('dropbox.browse.shared.folder') }}?shared_url={{ urlencode($sharedUrl) }}&path={{ urlencode($buildPath) }}">
                                <i class="bi bi-folder"></i> {{ $part }}
                            </a>
                        </li>
                    @endif
                @endforeach
            @else
                <li class="breadcrumb-item active">
                    <i class="bi bi-folder"></i> الجذر
                </li>
            @endif
        </ol>
    </nav>
</div>

{{-- Action Bar with Search Button --}}
<div class="action-bar">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <a href="{{ route('dropbox.search.match') }}?shared_url={{ urlencode($sharedUrl) }}&current_path={{ urlencode($currentPath) }}" 
               class="btn btn-search-match">
                <i class="bi bi-search me-2"></i>البحث والمطابقة في الملفات
            </a>
        </div>
        <div>
            <a href="{{ route('dropbox.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-return-right"></i> رجوع للرئيسية
            </a>
        </div>
    </div>
</div>

{{-- Content --}}
<div class="card-custom">
    <div class="card-header-custom">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                <i class="bi bi-folder2-open me-2"></i>
                محتوى المجلد
            </h4>
        </div>
    </div>

    <div class="card-body p-0">
        {{-- Folders --}}
        @if(count($items['folders']) > 0)
            <div class="section-header">
                <i class="bi bi-folder-fill me-2"></i>
                المجلدات
                <span class="badge-count">{{ count($items['folders']) }}</span>
            </div>
            <div class="p-3">
                @foreach($items['folders'] as $folder)
                    <a href="{{ route('dropbox.browse.shared.folder') }}?shared_url={{ urlencode($sharedUrl) }}&path={{ urlencode($folder['path']) }}"
                       class="folder-item">
                        <span class="folder-icon">📁</span>
                        <span class="fw-bold">{{ $folder['name'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Files --}}
        @if(count($items['files']) > 0)
            <div class="section-header">
                <i class="bi bi-file-earmark me-2"></i>
                الملفات
                <span class="badge-count">{{ count($items['files']) }}</span>
            </div>
            <div class="file-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 45%;">
                                    <i class="bi bi-file-text me-2"></i>الاسم
                                </th>
                                <th style="width: 15%;">
                                    <i class="bi bi-hdd me-2"></i>الحجم
                                </th>
                                <th style="width: 20%;">
                                    <i class="bi bi-clock me-2"></i>التعديل
                                </th>
                                <th style="width: 20%;" class="text-center">
                                    <i class="bi bi-gear me-2"></i>العمليات
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items['files'] as $file)
                                <tr class="file-row">
                                    <td>
                                        @php
                                            $icons = [
                                                'pdf' => ['icon' => '📕', 'color' => '#dc3545'],
                                                'doc' => ['icon' => '📘', 'color' => '#0d6efd'],
                                                'docx' => ['icon' => '📘', 'color' => '#0d6efd'],
                                                'xls' => ['icon' => '📗', 'color' => '#198754'],
                                                'xlsx' => ['icon' => '📗', 'color' => '#198754'],
                                                'ppt' => ['icon' => '📙', 'color' => '#fd7e14'],
                                                'pptx' => ['icon' => '📙', 'color' => '#fd7e14'],
                                                'txt' => ['icon' => '📝', 'color' => '#6c757d'],
                                                'md' => ['icon' => '📝', 'color' => '#6c757d'],
                                                'jpg' => ['icon' => '🖼️', 'color' => '#0dcaf0'],
                                                'jpeg' => ['icon' => '🖼️', 'color' => '#0dcaf0'],
                                                'png' => ['icon' => '🖼️', 'color' => '#0dcaf0'],
                                                'gif' => ['icon' => '🖼️', 'color' => '#0dcaf0'],
                                                'zip' => ['icon' => '📦', 'color' => '#ffc107'],
                                                'rar' => ['icon' => '📦', 'color' => '#ffc107'],
                                                'mp4' => ['icon' => '🎬', 'color' => '#d63384'],
                                                'avi' => ['icon' => '🎬', 'color' => '#d63384'],
                                                'mp3' => ['icon' => '🎵', 'color' => '#20c997'],
                                                'wav' => ['icon' => '🎵', 'color' => '#20c997'],
                                                'html' => ['icon' => '💻', 'color' => '#fd7e14'],
                                                'css' => ['icon' => '💻', 'color' => '#0d6efd'],
                                                'js' => ['icon' => '💻', 'color' => '#ffc107'],
                                                'php' => ['icon' => '⚙️', 'color' => '#6f42c1'],
                                                'py' => ['icon' => '⚙️', 'color' => '#0d6efd'],
                                                'java' => ['icon' => '⚙️', 'color' => '#dc3545'],
                                            ];

                                            $fileIcon = $icons[$file['extension']] ?? ['icon' => '📄', 'color' => '#6c757d'];
                                        @endphp
                                        <span class="me-2 fs-4">{{ $fileIcon['icon'] }}</span>
                                        <span>{{ $file['name'] }}</span>
                                    </td>
                                    <td>
                                        @if($file['size'] < 1024)
                                            <span class="badge bg-secondary">{{ $file['size'] }} B</span>
                                        @elseif($file['size'] < 1048576)
                                            <span class="badge bg-info">{{ number_format($file['size'] / 1024, 2) }} KB</span>
                                        @else
                                            <span class="badge bg-primary">{{ number_format($file['size'] / 1048576, 2) }} MB</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($file['modified'])
                                            <div>{{ date('Y-m-d', strtotime($file['modified'])) }}</div>
                                            <small class="text-muted">{{ date('H:i', strtotime($file['modified'])) }}</small>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            @if($file['is_previewable'])
                                                <a href="{{ route('dropbox.shared.preview') }}?shared_url={{ urlencode($sharedUrl) }}&path={{ urlencode($file['path']) }}"
                                                   class="btn btn-outline-info btn-action"
                                                   title="معاينة">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            @endif
                                            <form method="POST" action="{{ route('dropbox.shared.download') }}" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="shared_url" value="{{ $sharedUrl }}">
                                                <input type="hidden" name="path" value="{{ $file['path'] }}">
                                                <button type="submit" class="btn btn-outline-success btn-action" title="تحميل">
                                                    <i class="bi bi-download"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Empty State --}}
        @if(count($items['folders']) === 0 && count($items['files']) === 0)
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <h5 class="text-muted">المجلد فارغ</h5>
                <p class="text-muted mb-0">لا يوجد ملفات أو مجلدات في هذا الموقع</p>
            </div>
        @endif
    </div>

    {{-- Footer --}}
    <div class="card-footer bg-light text-center">
        <small class="text-muted">
            <i class="bi bi-folder me-2"></i>{{ count($items['folders']) }} مجلد
            <span class="mx-2">•</span>
            <i class="bi bi-file-earmark me-2"></i>{{ count($items['files']) }} ملف
        </small>
    </div>
</div>
@endsection