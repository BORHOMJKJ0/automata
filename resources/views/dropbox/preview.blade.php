@extends('dropbox.layout')

@section('title', 'معاينة: ' . $filename)

@push('styles')
<style>
    .preview-container {
        background: white;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }

    .preview-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1.5rem;
    }

    .preview-content {
        padding: 2rem;
        background: #f8f9fa;
        max-height: 70vh;
        overflow-y: auto;
    }

    .code-block {
        background: #2d2d2d;
        color: #f8f8f2;
        padding: 1.5rem;
        border-radius: 10px;
        font-family: 'Courier New', monospace;
        font-size: 14px;
        line-height: 1.6;
        overflow-x: auto;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    .text-content {
        background: white;
        padding: 2rem;
        border-radius: 10px;
        white-space: pre-wrap;
        font-family: 'Courier New', monospace;
        font-size: 14px;
        line-height: 1.8;
        border: 1px solid #e0e0e0;
        word-wrap: break-word;
    }

    .file-info {
        display: flex;
        gap: 1.5rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .info-badge {
        background: rgba(255,255,255,0.2);
        padding: 0.5rem 1rem;
        border-radius: 10px;
        font-size: 0.875rem;
    }

    @media (max-width: 768px) {
        .file-info {
            flex-direction: column;
            gap: 0.5rem;
        }

        .preview-content {
            padding: 1rem;
        }
    }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="preview-container">
            <div class="preview-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div class="mb-2 mb-md-0">
                        <h4 class="mb-2">
                            <i class="bi bi-eye me-2"></i>معاينة الملف
                        </h4>
                        <div class="file-info">
                            <span class="info-badge">
                                <i class="bi bi-file-text me-1"></i>{{ $filename }}
                            </span>
                            <span class="info-badge">
                                <i class="bi bi-filetype-{{ $extension }} me-1"></i>{{ strtoupper($extension) }}
                            </span>
                            <span class="info-badge">
                                <i class="bi bi-hdd me-1"></i>{{ number_format(strlen($content) / 1024, 2) }} KB
                            </span>
                        </div>
                    </div>
                    <div>
                        <button onclick="window.history.back()" class="btn btn-light">
                            <i class="bi bi-arrow-right me-2"></i>رجوع
                        </button>
                    </div>
                </div>
            </div>

            <div class="preview-content">
                @if(in_array($extension, ['php', 'js', 'css', 'html', 'json', 'xml', 'py', 'java', 'c', 'cpp']))
                    <div class="code-block">{{ $content }}</div>
                @else
                    <div class="text-content">{{ $content }}</div>
                @endif
            </div>

            <div class="card-footer bg-white text-center py-3">
                <form method="POST" action="{{ route('dropbox.shared.download') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="shared_url" value="{{ $sharedUrl }}">
                    <input type="hidden" name="path" value="{{ $path }}">
                    <button type="submit" class="btn btn-gradient-success">
                        <i class="bi bi-download me-2"></i>تحميل الملف
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
