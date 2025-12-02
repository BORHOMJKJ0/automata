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
        word-break: break-all;
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

    @media (max-width: 768px) {
        .stat-item {
            display: block;
            margin: 1rem 0;
        }
    }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        {{-- إحصائيات --}}
        @if(isset($totalFiles))
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
            <form method="POST" action="{{ route('dropbox.search.match') }}">
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
                        <small class="text-muted">Search for: "Producer Name : your_value"</small>
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
                        <small class="text-muted">Search for: "Wastes Location : your_value"</small>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-gradient-primary">
                        <i class="bi bi-search me-2"></i>البحث و المطابقة
                    </button>
                    <a href="{{ route('dropbox.browse.shared.folder') }}?shared_url={{ urlencode($sharedUrl ?? '') }}&path={{ urlencode($currentPath ?? '') }}" 
                       class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right me-2"></i>الجوع الى الملفات
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
                    <div class="mb-2 mb-md-0">
                        <h6 class="mb-1">
                            <i class="bi bi-file-text me-2"></i>{{ $file['name'] }}
                        </h6>
                        <small class="text-muted">{{ $file['path'] }}</small>
                    </div>
                    <span class="status-badge badge-match">
                        <i class="bi bi-check-circle me-1"></i>مطابق
                    </span>
                </div>

                <div class="row mt-3">
                    @if(!empty($wastesLocation))
                    <div class="col-md-6 mb-2">
                        <div class="field-info">
                            <div class="field-label">Wastes Location:</div>
                            <div class="field-value">{{ $file['wastes_found'] }}</div>
                        </div>
                    </div>
                    @endif
                    
                    @if(!empty($producerName))
                    <div class="col-md-6 mb-2">
                        <div class="field-info">
                            <div class="field-label">Producer Name:</div>
                            <div class="field-value">{{ $file['producer_found'] }}</div>
                        </div>
                    </div>
                    @endif
                </div>

                <div class="mt-3">
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
                    <div class="mb-2 mb-md-0">
                        <h6 class="mb-1">
                            <i class="bi bi-file-text me-2"></i>{{ $file['name'] }}
                        </h6>
                        <small class="text-muted">{{ $file['path'] }}</small>
                    </div>
                    <span class="status-badge badge-no-match">
                        <i class="bi bi-x-circle me-1"></i>غير مطابق
                    </span>
                </div>

                <div class="missing-info">
                    <strong><i class="bi bi-exclamation-triangle me-2"></i>مفقود أو مختلف:</strong>
                    <ul class="mb-0 mt-2">
                        @foreach($file['missing'] as $missing)
                        <li>{{ $missing }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="row mt-3">
                    @if(!empty($wastesLocation))
                    <div class="col-md-6 mb-2">
                        <div class="field-info">
                            <div class="field-label">Wastes Location الموجود:</div>
                            <div class="field-value">{{ $file['wastes_found'] }}</div>
                        </div>
                    </div>
                    @endif
                    
                    @if(!empty($producerName))
                    <div class="col-md-6 mb-2">
                        <div class="field-info">
                            <div class="field-label">Producer Name الموجود:</div>
                            <div class="field-value">{{ $file['producer_found'] }}</div>
                        </div>
                    </div>
                    @endif
                </div>

                <div class="mt-3">
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
        @if(isset($matchingFiles) && isset($nonMatchingFiles) && count($matchingFiles) === 0 && count($nonMatchingFiles) === 0)
        <div class="text-center py-5">
            <div style="font-size: 5rem; opacity: 0.3;">🔍</div>
            <h5 class="text-muted mt-3">لا توجد نتائج بعد</h5>
            <p class="text-muted">أدخل معايير البحث أعلاه واضغط "بحث ومطابقة"</p>
        </div>
        @endif
    </div>
</div>
@endsection