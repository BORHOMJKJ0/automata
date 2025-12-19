@extends('dropbox.layout')

@section('title', 'نتائج البحث والمطابقة')

@push('styles')
<style>
    .search-form-card {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        margin-bottom: 2rem;
    }

    .search-input {
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        transition: all 0.3s ease;
    }

    .search-input:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
    }

    .results-section {
        margin-bottom: 2rem;
    }

    .result-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
    }

    .result-card:hover {
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }

    .matching-card {
        border-left: 4px solid #28a745;
    }

    .non-matching-card {
        border-left: 4px solid #dc3545;
    }

    .status-badge {
        padding: 0.5rem 1rem;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.875rem;
    }

    .badge-match {
        background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        color: white;
    }

    .badge-no-match {
        background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
        color: white;
    }

    .field-info {
        background: #f8f9fa;
        padding: 0.75rem;
        border-radius: 8px;
        margin-top: 0.5rem;
        font-size: 0.9rem;
    }

    .field-label {
        font-weight: 600;
        color: #667eea;
        margin-bottom: 0.25rem;
    }

    .field-value {
        color: #495057;
        word-break: break-word;
    }

    .missing-info {
        background: #fff3cd;
        border-left: 3px solid #ffc107;
        padding: 0.75rem;
        border-radius: 8px;
        margin-top: 0.5rem;
    }

    .stats-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1.5rem;
        border-radius: 15px;
        margin-bottom: 2rem;
        text-align: center;
    }

    .stat-item {
        display: inline-block;
        margin: 0 1.5rem;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: bold;
        display: block;
    }

    .stat-label {
        opacity: 0.9;
        font-size: 0.9rem;
    }

    /* Loading Overlay */
    .loading-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.7);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }

    .loading-overlay.show {
        display: flex;
    }

    .loading-content {
        background: white;
        padding: 2rem;
        border-radius: 15px;
        text-align: center;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    }

    .spinner {
        border: 4px solid #f3f3f3;
        border-top: 4px solid #667eea;
        border-radius: 50%;
        width: 50px;
        height: 50px;
        animation: spin 1s linear infinite;
        margin: 0 auto 1rem;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .help-text {
        background: #e7f3ff;
        border-left: 3px solid #2196F3;
        padding: 1rem;
        border-radius: 8px;
        margin-top: 1rem;
    }

    .example-box {
        background: #f8f9fa;
        border: 1px dashed #dee2e6;
        padding: 0.5rem;
        border-radius: 5px;
        font-family: monospace;
        font-size: 0.85rem;
        margin-top: 0.5rem;
    }

    @media (max-width: 768px) {
        .stat-item {
            display: block;
            margin: 1rem 0;
        }
    }
</style>
@endpush

@section('content')
<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <div class="spinner"></div>
        <h5>جاري البحث في الملفات...</h5>
        <p class="text-muted mb-0">قد يستغرق هذا بضع دقائق</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-10">
        {{-- إحصائيات --}}
        @if(isset($totalFiles) && $totalFiles > 0)
        <div class="stats-card">
            <div class="stat-item">
                <span class="stat-number">{{ $totalFiles }}</span>
                <span class="stat-label">إجمالي الملفات المفحوصة</span>
            </div>
            <div class="stat-item">
                <span class="stat-number">{{ count($matchingFiles) }}</span>
                <span class="stat-label">✓ ملفات مطابقة</span>
            </div>
            <div class="stat-item">
                <span class="stat-number">{{ count($nonMatchingFiles) }}</span>
                <span class="stat-label">✗ ملفات غير مطابقة</span>
            </div>
        </div>
        @endif

        {{-- نموذج البحث --}}
        <div class="search-form-card">
            <h4 class="mb-4">
                <i class="bi bi-search me-2"></i>البحث و مطابقة الملفات
            </h4>
            
            <div class="help-text mb-3">
                <strong><i class="bi bi-info-circle me-2"></i>كيفية الاستخدام:</strong>
                <ul class="mb-2 mt-2">
                    <li>البحث يتم في جميع الملفات النصية بشكل تكراري</li>
                    <li>يمكنك البحث بحقل واحد أو حقلين معاً</li>
                    <li>البحث غير حساس لحالة الأحرف (Case-insensitive)</li>
                    <li>يدعم البحث الجزئي (Partial matching)</li>
                </ul>
            </div>

            <form method="POST" action="{{ route('dropbox.search.match') }}" id="searchForm">
                @csrf
                <input type="hidden" name="shared_url" value="{{ $sharedUrl ?? '' }}">
                <input type="hidden" name="current_path" value="{{ $currentPath ?? '' }}">
                
                <div dir='ltr' class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">
                            <i class="bi bi-building me-2"></i>Producer Name
                        </label>
                        <input type="text" 
                               name="producer_name" 
                               class="form-control search-input"
                               value="{{ $producerName ?? '' }}"
                               placeholder="Enter producer name">
                        <small class="text-muted">يبحث عن: "Producer Name : your_value"</small>
                        <div class="example-box">
                            مثال: Abu Dhabi Waste Management
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">
                            <i class="bi bi-geo-alt me-2"></i>Wastes Location
                        </label>
                        <input type="text" 
                               name="wastes_location" 
                               class="form-control search-input"
                               value="{{ $wastesLocation ?? '' }}"
                               placeholder="Enter wastes location">
                        <small class="text-muted">يبحث عن: "Wastes Location : your_value"</small>
                        <div class="example-box">
                            مثال: Dubai Industrial City
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-gradient-primary">
                        <i class="bi bi-search me-2"></i>البحث و المطابقة
                    </button>
                    <a href="{{ route('dropbox.browse.shared.folder') }}?shared_url={{ urlencode($sharedUrl ?? '') }}&path={{ urlencode($currentPath ?? '') }}" 
                       class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right me-2"></i>الرجوع الى الملفات
                    </a>
                </div>
            </form>
        </div>

        {{-- الملفات المطابقة --}}
        @if(isset($matchingFiles) && count($matchingFiles) > 0)
        <div class="results-section">
            <h5 class="mb-3">
                <i class="bi bi-check-circle-fill text-success me-2"></i>
                الملفات المطابقة ({{ count($matchingFiles) }})
            </h5>
            
            @foreach($matchingFiles as $file)
            <div class="result-card matching-card">
                <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap">
                    <div class="mb-2 mb-md-0 flex-grow-1">
                        <h6 class="mb-1">
                            <i class="bi bi-file-text me-2"></i>{{ $file['name'] }}
                        </h6>
                        <small class="text-muted">{{ $file['path'] }}</small>
                        <div class="mt-1">
                            <span class="badge bg-secondary">{{ number_format($file['size'] / 1024, 2) }} KB</span>
                        </div>
                    </div>
                    <span class="status-badge badge-match">
                        <i class="bi bi-check-circle me-1"></i>مطابق تماماً
                    </span>
                </div>

                <div class="row mt-3">
                    @if(!empty($producerName))
                    <div class="col-md-6 mb-2">
                        <div class="field-info">
                            <div class="field-label">
                                <i class="bi bi-building me-1"></i>Producer Name:
                            </div>
                            <div class="field-value">{{ $file['producer_found'] }}</div>
                        </div>
                    </div>
                    @endif
                    
                    @if(!empty($wastesLocation))
                    <div class="col-md-6 mb-2">
                        <div class="field-info">
                            <div class="field-label">
                                <i class="bi bi-geo-alt me-1"></i>Wastes Location:
                            </div>
                            <div class="field-value">{{ $file['wastes_found'] }}</div>
                        </div>
                    </div>
                    @endif
                </div>

                <div class="mt-3 d-flex gap-2 flex-wrap">
                    <a href="{{ route('dropbox.shared.preview') }}?shared_url={{ urlencode($sharedUrl) }}&path={{ urlencode($file['path']) }}" 
                       class="btn btn-sm btn-outline-info">
                        <i class="bi bi-eye me-1"></i>معاينة
                    </a>
                    <form method="POST" action="{{ route('dropbox.shared.download') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="shared_url" value="{{ $sharedUrl }}">
                        <input type="hidden" name="path" value="{{ $file['path'] }}">
                        <button type="submit" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-download me-1"></i>تحميل
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- الملفات غير المطابقة --}}
        @if(isset($nonMatchingFiles) && count($nonMatchingFiles) > 0)
        <div class="results-section">
            <h5 class="mb-3">
                <i class="bi bi-x-circle-fill text-danger me-2"></i>
                الملفات غير المطابقة ({{ count($nonMatchingFiles) }})
            </h5>
            
            @foreach($nonMatchingFiles as $file)
            <div class="result-card non-matching-card">
                <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap">
                    <div class="mb-2 mb-md-0 flex-grow-1">
                        <h6 class="mb-1">
                            <i class="bi bi-file-text me-2"></i>{{ $file['name'] }}
                        </h6>
                        <small class="text-muted">{{ $file['path'] }}</small>
                        <div class="mt-1">
                            <span class="badge bg-secondary">{{ number_format($file['size'] / 1024, 2) }} KB</span>
                        </div>
                    </div>
                    <span class="status-badge badge-no-match">
                        <i class="bi bi-x-circle me-1"></i>غير مطابق
                    </span>
                </div>

                @if(isset($file['missing']) && count($file['missing']) > 0)
                <div class="missing-info">
                    <strong><i class="bi bi-exclamation-triangle me-2"></i>القيم المطلوبة غير موجودة أو مختلفة:</strong>
                    <ul class="mb-0 mt-2">
                        @foreach($file['missing'] as $missing)
                        <li>{{ $missing }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="row mt-3">
                    @if(!empty($producerName))
                    <div class="col-md-6 mb-2">
                        <div class="field-info">
                            <div class="field-label">
                                <i class="bi bi-building me-1"></i>Producer Name الموجود:
                            </div>
                            <div class="field-value {{ $file['producer_found'] === 'Not Found' ? 'text-danger' : '' }}">
                                {{ $file['producer_found'] }}
                            </div>
                        </div>
                    </div>
                    @endif
                    
                    @if(!empty($wastesLocation))
                    <div class="col-md-6 mb-2">
                        <div class="field-info">
                            <div class="field-label">
                                <i class="bi bi-geo-alt me-1"></i>Wastes Location الموجود:
                            </div>
                            <div class="field-value {{ $file['wastes_found'] === 'Not Found' ? 'text-danger' : '' }}">
                                {{ $file['wastes_found'] }}
                            </div>
                        </div>
                    </div>
                    @endif
                </div>

                <div class="mt-3 d-flex gap-2 flex-wrap">
                    <a href="{{ route('dropbox.shared.preview') }}?shared_url={{ urlencode($sharedUrl) }}&path={{ urlencode($file['path']) }}" 
                       class="btn btn-sm btn-outline-info">
                        <i class="bi bi-eye me-1"></i>معاينة
                    </a>
                    <form method="POST" action="{{ route('dropbox.shared.download') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="shared_url" value="{{ $sharedUrl }}">
                        <input type="hidden" name="path" value="{{ $file['path'] }}">
                        <button type="submit" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-download me-1"></i>تحميل
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- رسالة في حالة عدم وجود نتائج --}}
        @if(isset($matchingFiles) && isset($nonMatchingFiles) && count($matchingFiles) === 0 && count($nonMatchingFiles) === 0 && isset($totalFiles) && $totalFiles === 0)
        <div class="text-center py-5">
            <div style="font-size: 5rem; opacity: 0.3;">🔍</div>
            <h5 class="text-muted mt-3">ابدأ البحث الآن</h5>
            <p class="text-muted">أدخل معايير البحث أعلاه واضغط "البحث والمطابقة"</p>
        </div>
        @endif

        {{-- رسالة عند عدم وجود ملفات نصية --}}
        @if(isset($totalFiles) && $totalFiles === 0 && isset($matchingFiles))
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>لم يتم العثور على ملفات نصية قابلة للبحث في المجلد المحدد.</strong>
            <p class="mb-0 mt-2">تأكد من وجود ملفات نصية (.txt, .log, .md, إلخ) في المجلد.</p>
        </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        // التحقق من وجود قيمة واحدة على الأقل
        const producerName = document.querySelector('input[name="producer_name"]').value.trim();
        const wastesLocation = document.querySelector('input[name="wastes_location"]').value.trim();
        
        if (!producerName && !wastesLocation) {
            e.preventDefault();
            alert('الرجاء إدخال قيمة واحدة على الأقل للبحث');
            return false;
        }
        
        // إظهار شاشة التحميل
        document.getElementById('loadingOverlay').classList.add('show');
    });
</script>
@endpush