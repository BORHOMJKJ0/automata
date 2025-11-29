@extends('dropbox.layout')

@section('title', 'Dropbox Manager - الرئيسية')

@push('styles')
<style>
    .hero-section {
        text-align: center;
        padding: 3rem 0;
    }

    .hero-icon {
        font-size: 5rem;
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 1rem;
    }

    .feature-card {
        background: white;
        border-radius: 15px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        border-left: 4px solid #667eea;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
    }

    .feature-card:hover {
        transform: translateX(-5px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
    }

    .feature-icon {
        font-size: 2.5rem;
        margin-left: 1rem;
    }

    .input-shared-link {
        border: 2px solid #e0e0e0;
        border-radius: 15px;
        padding: 1rem 1.5rem;
        font-size: 1rem;
        transition: all 0.3s ease;
    }

    .input-shared-link:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
    }

    .status-badge {
        display: inline-block;
        padding: 0.5rem 1rem;
        border-radius: 50px;
        font-weight: 600;
        margin-bottom: 1rem;
    }

    .status-connected {
        background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        color: white;
    }

    .status-disconnected {
        background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
        color: white;
    }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card-custom">
            <div class="card-header-custom text-center">
                <div class="hero-section">
                    <div class="hero-icon">
                        <i class="bi bi-box-seam" style="color: white;"></i>
                    </div>
                    <h2 class="mb-2">Dropbox File Manager</h2>
                    <p class="mb-0 opacity-75">أداة قوية لإدارة وتصفح ملفات Dropbox</p>
                </div>
            </div>

            <div class="card-body p-4">
                @if(Session::has('dropbox_access_token'))
                    {{-- المستخدم مسجل دخول --}}
                    <div class="text-center mb-4">
                        <span class="status-badge status-connected">
                            <i class="bi bi-check-circle me-2"></i>متصل بـ Dropbox
                        </span>
                    </div>

                    <div class="mb-4">
                        <h5 class="fw-bold mb-3">
                            <i class="bi bi-link-45deg me-2"></i>أدخل رابط Dropbox المشارك
                        </h5>
                        <form method="POST" action="{{ route('dropbox.browse.shared') }}">
                            @csrf
                            <div class="mb-3">
                                <input type="url"
                                       name="shared_url"
                                       class="form-control input-shared-link"
                                       placeholder="https://www.dropbox.com/scl/fo/..."
                                       required>
                                <small class="text-muted mt-2 d-block">
                                    <i class="bi bi-info-circle me-1"></i>
                                    الصق رابط المجلد المشارك من Dropbox
                                </small>
                            </div>
                            <button type="submit" class="btn btn-gradient-primary w-100 btn-lg">
                                <i class="bi bi-search me-2"></i>تصفح المحتوى
                            </button>
                        </form>
                    </div>

                    <hr class="my-4">

                    <div class="text-center">
                        <p class="text-muted mb-3">أو</p>
                        <a href="{{ route('dropbox.logout') }}" class="btn btn-outline-secondary">
                            <i class="bi bi-box-arrow-right me-2"></i>تسجيل الخروج
                        </a>
                    </div>
                @else
                    {{-- المستخدم غير مسجل دخول --}}
                    <div class="text-center mb-4">
                        <span class="status-badge status-disconnected">
                            <i class="bi bi-x-circle me-2"></i>غير متصل
                        </span>
                        <p class="lead mt-3">للبدء، قم بربط حسابك في Dropbox</p>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-stars me-2"></i>المميزات:
                        </h6>

                        <div class="feature-card">
                            <div class="d-flex align-items-center">
                                <span class="feature-icon">📁</span>
                                <div>
                                    <h6 class="mb-1 fw-bold">تصفح الملفات</h6>
                                    <small class="text-muted">تصفح جميع مجلداتك وملفاتك بسهولة</small>
                                </div>
                            </div>
                        </div>

                        <div class="feature-card">
                            <div class="d-flex align-items-center">
                                <span class="feature-icon">🔗</span>
                                <div>
                                    <h6 class="mb-1 fw-bold">دعم الروابط المشاركة</h6>
                                    <small class="text-muted">الوصول للملفات عبر روابط Dropbox المشاركة</small>
                                </div>
                            </div>
                        </div>

                        <div class="feature-card">
                            <div class="d-flex align-items-center">
                                <span class="feature-icon">⬇️</span>
                                <div>
                                    <h6 class="mb-1 fw-bold">تحميل الملفات</h6>
                                    <small class="text-muted">تحميل أي ملف بضغطة واحدة</small>
                                </div>
                            </div>
                        </div>

                        <div class="feature-card">
                            <div class="d-flex align-items-center">
                                <span class="feature-icon">👁️</span>
                                <div>
                                    <h6 class="mb-1 fw-bold">معاينة المحتوى</h6>
                                    <small class="text-muted">معاينة محتوى الملفات النصية مباشرة</small>
                                </div>
                            </div>
                        </div>

                        <div class="feature-card">
                            <div class="d-flex align-items-center">
                                <span class="feature-icon">🤖</span>
                                <div>
                                    <h6 class="mb-1 fw-bold">جاهز للأتمتة</h6>
                                    <small class="text-muted">بنية قابلة للتوسع للعمليات التلقائية</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <a href="{{ route('dropbox.connect') }}" class="btn btn-gradient-primary btn-lg">
                            <i class="bi bi-dropbox me-2"></i>الاتصال بـ Dropbox
                        </a>
                    </div>

                    <div class="text-center mt-3">
                        <small class="text-muted">
                            <i class="bi bi-shield-check me-1"></i>
                            آمن تماماً - نستخدم OAuth 2.0
                        </small>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
