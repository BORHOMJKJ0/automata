@extends('dropbox.layout')

@section('title', 'نتائج معالجة Excel')

@push('styles')
<style>
    .success-banner {
        background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        color: white;
        padding: 3rem;
        border-radius: 20px;
        text-align: center;
        margin-bottom: 2rem;
        box-shadow: 0 10px 40px rgba(17, 153, 142, 0.3);
    }

    .success-icon {
        font-size: 5rem;
        margin-bottom: 1rem;
        animation: scaleIn 0.5s ease-out;
    }

    @keyframes scaleIn {
        from {
            transform: scale(0);
        }
        to {
            transform: scale(1);
        }
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .stat-box {
        background: white;
        padding: 2rem;
        border-radius: 15px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
    }

    .stat-box:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 30px rgba(0,0,0,0.15);
    }

    .stat-box .stat-number {
        font-size: 3rem;
        font-weight: bold;
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        display: block;
    }

    .stat-box .stat-label {
        color: #666;
        font-size: 1rem;
        margin-top: 0.5rem;
    }

    .process-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        border-left: 4px solid #ddd;
    }

    .process-card.success {
        border-left-color: #28a745;
        background: linear-gradient(to right, #f0fff4 0%, white 10%);
    }

    .process-card.warning {
        border-left-color: #ffc107;
        background: linear-gradient(to right, #fff3cd 0%, white 10%);
    }

    .process-card.error {
        border-left-color: #dc3545;
        background: linear-gradient(to right, #f8d7da 0%, white 10%);
    }

    .file-icon {
        font-size: 2rem;
        margin-left: 1rem;
    }

    .download-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2rem;
        border-radius: 15px;
        text-align: center;
        margin-top: 2rem;
    }

    .download-btn {
        background: white;
        color: #667eea;
        border: none;
        padding: 1rem 3rem;
        border-radius: 12px;
        font-weight: 600;
        font-size: 1.1rem;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .download-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        color: #667eea;
    }

    .action-buttons {
        display: flex;
        gap: 1rem;
        justify-content: center;
        flex-wrap: wrap;
        margin-top: 2rem;
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .success-banner {
            padding: 2rem 1rem;
        }

        .success-icon {
            font-size: 3rem;
        }
    }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        @if($success ?? false)
            {{-- Success Banner --}}
            <div class="success-banner">
                <div class="success-icon">✓</div>
                <h2 class="mb-3">تمت المعالجة بنجاح!</h2>
                <p class="mb-0 fs-5">تم تحديث ملف Excel بنجاح بالبيانات من ملفات PDF</p>
            </div>

            {{-- Statistics --}}
            <div class="stats-grid">
                <div class="stat-box">
                    <span class="stat-number">{{ count($processedFiles ?? []) }}</span>
                    <div class="stat-label">إجمالي الملفات المعالجة</div>
                </div>
                <div class="stat-box">
                    <span class="stat-number">{{ $updatedCount ?? 0 }}</span>
                    <div class="stat-label">✓ صفوف تم تحديثها</div>
                </div>
                <div class="stat-box">
                    <span class="stat-number">
                        {{ count(array_filter($processedFiles ?? [], fn($f) => $f['status'] === 'warning')) }}
                    </span>
                    <div class="stat-label">⚠ تحذيرات</div>
                </div>
                <div class="stat-box">
                    <span class="stat-number">
                        {{ count(array_filter($processedFiles ?? [], fn($f) => $f['status'] === 'error')) }}
                    </span>
                    <div class="stat-label">✗ أخطاء</div>
                </div>
            </div>

            {{-- Download Section --}}
            @if(isset($newFilePath))
            <div class="download-section">
                <h4 class="mb-3">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>
                    ملف Excel المحدث جاهز!
                </h4>
                <p class="mb-4">تم رفع الملف المحدث إلى Dropbox</p>
                <div class="alert alert-light mb-4">
                    <strong>اسم الملف:</strong><br>
                    <code style="color: #333;">{{ basename($newFilePath) }}</code>
                </div>
                <p class="text-sm">يمكنك الآن تحميل الملف المحدث من Dropbox</p>
            </div>
            @endif

            {{-- Processed Files Details --}}
            @if(isset($processedFiles) && count($processedFiles) > 0)
            <div class="mt-4">
                <h5 class="mb-3">
                    <i class="bi bi-list-check me-2"></i>تفاصيل الملفات المعالجة
                </h5>

                {{-- Success Files --}}
                @php
                    $successFiles = array_filter($processedFiles, fn($f) => $f['status'] === 'success');
                @endphp
                @if(count($successFiles) > 0)
                <h6 class="text-success mt-4 mb-2">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    ملفات تمت معالجتها بنجاح ({{ count($successFiles) }})
                </h6>
                @foreach($successFiles as $file)
                <div class="process-card success">
                    <div class="d-flex align-items-center">
                        <span class="file-icon">📄</span>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">{{ $file['name'] }}</h6>
                            <div class="d-flex gap-3 flex-wrap">
                                <small class="text-muted">
                                    <strong>Manifest:</strong> {{ $file['manifest'] ?? 'N/A' }}
                                </small>
                                <small class="text-muted">
                                    <strong>Quantity:</strong> {{ $file['quantity'] ?? 'N/A' }} Kg
                                </small>
                            </div>
                        </div>
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 1.5rem;"></i>
                    </div>
                </div>
                @endforeach
                @endif

                {{-- Warning Files --}}
                @php
                    $warningFiles = array_filter($processedFiles, fn($f) => $f['status'] === 'warning');
                @endphp
                @if(count($warningFiles) > 0)
                <h6 class="text-warning mt-4 mb-2">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    ملفات بها تحذيرات ({{ count($warningFiles) }})
                </h6>
                @foreach($warningFiles as $file)
                <div class="process-card warning">
                    <div class="d-flex align-items-center">
                        <span class="file-icon">⚠️</span>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">{{ $file['name'] }}</h6>
                            <small class="text-muted">{{ $file['message'] ?? 'No details available' }}</small>
                        </div>
                        <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 1.5rem;"></i>
                    </div>
                </div>
                @endforeach
                @endif

                {{-- Error Files --}}
                @php
                    $errorFiles = array_filter($processedFiles, fn($f) => $f['status'] === 'error');
                @endphp
                @if(count($errorFiles) > 0)
                <h6 class="text-danger mt-4 mb-2">
                    <i class="bi bi-x-circle-fill me-2"></i>
                    ملفات حدثت بها أخطاء ({{ count($errorFiles) }})
                </h6>
                @foreach($errorFiles as $file)
                <div class="process-card error">
                    <div class="d-flex align-items-center">
                        <span class="file-icon">❌</span>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">{{ $file['name'] }}</h6>
                            <small class="text-danger">{{ $file['message'] ?? 'Unknown error' }}</small>
                        </div>
                        <i class="bi bi-x-circle-fill text-danger" style="font-size: 1.5rem;"></i>
                    </div>
                </div>
                @endforeach
                @endif
            </div>
            @endif

            {{-- Action Buttons --}}
            <div class="action-buttons">
                <a href="{{ route('dropbox.browse.shared.folder') }}?shared_url={{ urlencode($sharedUrl ?? '') }}" 
                   class="btn btn-gradient-primary btn-lg">
                    <i class="bi bi-folder me-2"></i>العودة إلى الملفات
                </a>
                <a href="{{ route('dropbox.search.match') }}?shared_url={{ urlencode($sharedUrl ?? '') }}" 
                   class="btn btn-outline-secondary btn-lg">
                    <i class="bi bi-search me-2"></i>بحث جديد
                </a>
                <a href="{{ route('dropbox.index') }}" class="btn btn-outline-secondary btn-lg">
                    <i class="bi bi-house me-2"></i>الصفحة الرئيسية
                </a>
            </div>

        @else
            {{-- No Success --}}
            <div class="text-center py-5">
                <div style="font-size: 5rem; opacity: 0.3;">❓</div>
                <h5 class="text-muted mt-3">لا توجد نتائج للعرض</h5>
                <p class="text-muted mb-4">قم بمعالجة ملفات PDF أولاً</p>
                <a href="{{ route('dropbox.search.match') }}" class="btn btn-gradient-primary btn-lg">
                    <i class="bi bi-search me-2"></i>البحث والمطابقة
                </a>
            </div>
        @endif
    </div>
</div>
@endsection