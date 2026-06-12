@extends('layouts.default_tech')

@section('title', 'Branding')

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Company visual identity and shell color palette. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Branding</h1>
        <x-buttons.back url="{{ route('tech.admin.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    @php
        $themeGroups = [
            'Layout' => [
                'header_background' => 'Header background',
                'header_color' => 'Header text',
                'footer_background' => 'Footer background',
                'footer_color' => 'Footer text',
                'main_background' => 'Main background',
                'content_background' => 'Content background',
            ],
            'Sidebars' => [
                'left_sidebar_background' => 'Left sidebar background',
                'left_sidebar_color' => 'Left sidebar text',
                'right_sidebar_background' => 'Right sidebar background',
                'right_sidebar_color' => 'Right sidebar text',
            ],
            'Page And Cards' => [
                'page_header_background' => 'Page header background',
                'page_header_color' => 'Page header text',
                'card_header_background' => 'Card header background',
                'card_header_color' => 'Card header text',
            ],
            'Buttons' => [
                'primary_button_background' => 'Primary button background',
                'primary_button_color' => 'Primary button text',
                'secondary_button_background' => 'Secondary button background',
                'secondary_button_color' => 'Secondary button text',
            ],
        ];
    @endphp

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Branding form -->
    <!-- Branding is stored with the Company Profile payload but managed from its own admin surface. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.system.branding.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card-header">
                <h2 class="h5 mb-0">Logo</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <label for="logo" class="form-label">Fallback logo</label>
                        @if($companyProfile['logo_url'])
                            <div class="branding-logo-slot mb-2">
                                <img src="{{ $companyProfile['logo_url'] }}" alt="{{ $companyProfile['company_name'] }} logo" class="company-branding-logo-preview">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1">
                                    <label class="form-check-label" for="remove_logo">Remove current logo</label>
                                </div>
                            </div>
                        @endif
                        <input id="logo" name="logo" type="file" class="form-control" accept="image/*">
                        <div class="form-text mb-3">Used when no light or dark logo is configured.</div>
                    </div>

                    <div class="col-lg-4">
                        <label for="logo_light" class="form-label">Light mode logo</label>
                        @if($companyProfile['logo_light_path'])
                            <div class="branding-logo-slot mb-2">
                                <img src="{{ $companyProfile['logo_light_url'] }}" alt="" class="company-branding-logo-preview">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="remove_light_logo" name="remove_light_logo" value="1">
                                    <label class="form-check-label" for="remove_light_logo">Remove</label>
                                </div>
                            </div>
                        @endif
                        <input id="logo_light" name="logo_light" type="file" class="form-control" accept="image/*">
                    </div>

                    <div class="col-lg-4">
                        <label for="logo_dark" class="form-label">Dark mode logo</label>
                        @if($companyProfile['logo_dark_path'])
                            <div class="branding-logo-slot mb-2">
                                <img src="{{ $companyProfile['logo_dark_url'] }}" alt="" class="company-branding-logo-preview">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="remove_dark_logo" name="remove_dark_logo" value="1">
                                    <label class="form-check-label" for="remove_dark_logo">Remove</label>
                                </div>
                            </div>
                        @endif
                        <input id="logo_dark" name="logo_dark" type="file" class="form-control" accept="image/*">
                    </div>
                </div>
                <div class="form-text mt-2">PNG, JPG, GIF, or WebP up to 2 MB.</div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h2 class="h5 mb-0">Theme Preset</h2>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Apply a preset to quickly set all brand and surface colors. This overwrites all color fields but preserves logos and company information.
                </p>
                <div class="d-flex align-items-center gap-3">
                    <select id="theme_preset" name="preset" form="theme-preset-form" class="form-select w-auto">
                        @foreach(\App\Modules\System\Support\CompanyProfileSettings::getPresets() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit"
                            form="theme-preset-form"
                            class="btn btn-outline-primary"
                            onclick="return confirm('Apply this theme preset? All color fields will be overwritten.');">
                        <i class="bi bi-palette me-1" aria-hidden="true"></i>
                        Apply preset
                    </button>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h2 class="h5 mb-0">Brand Colors</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="primary_color" class="form-label">Primary action</label>
                        <input id="primary_color" name="primary_color" type="color" class="form-control form-control-color w-100" value="{{ old('primary_color', $companyProfile['primary_color']) }}">
                    </div>
                    <div class="col-md-4">
                        <label for="secondary_color" class="form-label">Secondary action</label>
                        <input id="secondary_color" name="secondary_color" type="color" class="form-control form-control-color w-100" value="{{ old('secondary_color', $companyProfile['secondary_color']) }}">
                    </div>
                    <div class="col-md-4">
                        <label for="accent_color" class="form-label">Accent</label>
                        <input id="accent_color" name="accent_color" type="color" class="form-control form-control-color w-100" value="{{ old('accent_color', $companyProfile['accent_color']) }}">
                    </div>
                </div>
                <div class="form-text mt-3">
                    These colors drive links, active navigation, focus states, and brand accents. Button colors can be tuned per mode below.
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label for="default_theme" class="form-label">Default workspace theme</label>
                        <select id="default_theme" name="default_theme" class="form-select">
                            @foreach(['light' => 'Light', 'dark' => 'Dark', 'system' => 'Browser system'] as $theme => $label)
                                <option value="{{ $theme }}" @selected(old('default_theme', $companyProfile['default_theme']) === $theme)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            Used when a technician chooses company default in their profile.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h2 class="h5 mb-0">Theme Surfaces</h2>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Configure the most used shell surfaces separately for light and dark mode.
                </p>

                @foreach(['light' => 'Light mode', 'dark' => 'Dark mode'] as $mode => $modeLabel)
                    <section class="theme-mode-section border rounded p-3 mb-3">
                        <h3 class="h5 mb-3">{{ $modeLabel }}</h3>

                        @foreach($themeGroups as $groupName => $fields)
                            <div class="theme-field-group mb-3">
                                <h4 class="h6 text-muted text-uppercase small mb-2">{{ $groupName }}</h4>
                                <div class="row g-2">
                                    @foreach($fields as $field => $label)
                                            @php($key = $mode . '_' . $field)
                                            <div class="col-sm-6 col-lg-3">
                                                <label for="{{ $key }}" class="form-label small">{{ $label }}</label>
                                                <input id="{{ $key }}" name="{{ $key }}" type="color" class="form-control form-control-color w-100" value="{{ old($key, $companyProfile[$key]) }}">
                                            </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="submit"
                    form="reset-branding-form"
                    class="btn btn-outline-secondary branding-action-button"
                    onclick="return confirm('Reset branding colors and logos to Nexum defaults?');">
                Reset to default
            </button>
            <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary branding-action-button">
                Cancel
            </a>
            <button type="submit" class="btn btn-primary branding-action-button">
                <i class="bi bi-save me-1" aria-hidden="true"></i>
                Save branding
            </button>
        </div>
    </form>

    <form id="reset-branding-form" method="POST" action="{{ route('tech.admin.system.branding.reset') }}">
        @csrf
        @method('PUT')
    </form>

    <form id="theme-preset-form" method="POST" action="{{ route('tech.admin.system.branding.preset') }}">
        @csrf
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection

@section('rightbar')
    <x-card.default title="Live Preview">
        <div class="btn-group btn-group-sm w-100 mb-3" role="group" aria-label="Preview mode">
            <button type="button" class="btn btn-primary" data-preview-mode="light">Light</button>
            <button type="button" class="btn btn-outline-primary" data-preview-mode="dark">Dark</button>
        </div>

        <div id="brandingPreview" class="branding-preview border rounded overflow-hidden">
            <div class="branding-preview-header d-flex align-items-center gap-2 p-2">
                <img src="{{ $companyProfile['logo_light_url'] ?? '' }}"
                     alt=""
                     class="branding-preview-logo {{ $companyProfile['logo_light_url'] ? '' : 'd-none' }}"
                     data-preview-logo
                     data-fallback-src="{{ $companyProfile['logo_url'] ?? '' }}"
                     data-light-src="{{ $companyProfile['logo_light_url'] ?? '' }}"
                     data-dark-src="{{ $companyProfile['logo_dark_url'] ?? '' }}">
                <span class="branding-preview-mark {{ $companyProfile['logo_light_url'] ? 'd-none' : '' }}" data-preview-logo-mark>
                    <i class="bi bi-buildings" aria-hidden="true"></i>
                </span>
                <span class="fw-semibold text-truncate">{{ $companyProfile['company_name'] }}</span>
            </div>

            <div class="branding-preview-page-header p-2">
                <div class="fw-semibold">Page Header</div>
                <div class="small opacity-75">Breadcrumb / context</div>
            </div>

            <div class="branding-preview-body d-grid">
                <div class="branding-preview-left p-2">
                    <div class="small fw-semibold mb-2">Left Sidebar</div>
                    <div class="branding-preview-pill active">Active item</div>
                    <div class="branding-preview-pill">Menu item</div>
                </div>
                <div class="branding-preview-content p-2">
                    <div class="branding-preview-card border rounded mb-2">
                        <div class="branding-preview-card-header px-2 py-1 fw-semibold">Card Header</div>
                        <div class="p-2">
                            <div class="small mb-2">Content area</div>
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <span class="branding-preview-badge primary">Primary</span>
                                <span class="branding-preview-badge secondary">Secondary</span>
                                <span class="branding-preview-badge accent">Accent</span>
                            </div>
                            <div class="d-flex flex-wrap gap-1">
                                <button type="button" class="branding-preview-button primary">Primary</button>
                                <button type="button" class="branding-preview-button secondary">Secondary</button>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="branding-preview-link small">Example link</a>
                </div>
                <div class="branding-preview-right p-2">
                    <div class="small fw-semibold mb-2">Right Sidebar</div>
                    <div class="small">Widget</div>
                </div>
            </div>

            <div class="branding-preview-footer p-2 small">Footer</div>
        </div>
    </x-card.default>

    <x-card.default title="Theme coverage">
        <p class="small text-muted mb-0">
            This pass covers the main shell, sidebars, page header, card headers, and primary/secondary buttons.
            Full Bootstrap component theming can be added later.
        </p>
    </x-card.default>
@endsection

@section('scripts')
    <style>
        .company-branding-logo-preview {
            max-height: 3rem;
            max-width: 12rem;
            object-fit: contain;
        }

        .branding-logo-slot {
            min-height: 4.75rem;
        }

        .branding-action-button {
            min-width: 9.5rem;
        }

        .theme-mode-section:last-child,
        .theme-field-group:last-child {
            margin-bottom: 0 !important;
        }

        .company-brand-preview {
            background: var(--bs-tertiary-bg);
        }

        .company-brand-preview-logo {
            max-height: 2rem;
            max-width: 10rem;
            object-fit: contain;
        }

        .company-brand-preview-mark {
            width: 2rem;
            height: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--bs-border-color);
            border-radius: .5rem;
            color: var(--nexum-brand-primary, var(--bs-primary));
            background: var(--bs-body-bg);
        }

        .branding-preview {
            --preview-brand-primary: {{ old('primary_color', $companyProfile['primary_color']) }};
            --preview-brand-secondary: {{ old('secondary_color', $companyProfile['secondary_color']) }};
            --preview-brand-accent: {{ old('accent_color', $companyProfile['accent_color']) }};
            --preview-header-bg: {{ old('light_header_background', $companyProfile['light_header_background']) }};
            --preview-header-color: {{ old('light_header_color', $companyProfile['light_header_color']) }};
            --preview-footer-bg: {{ old('light_footer_background', $companyProfile['light_footer_background']) }};
            --preview-footer-color: {{ old('light_footer_color', $companyProfile['light_footer_color']) }};
            --preview-left-sidebar-bg: {{ old('light_left_sidebar_background', $companyProfile['light_left_sidebar_background']) }};
            --preview-left-sidebar-color: {{ old('light_left_sidebar_color', $companyProfile['light_left_sidebar_color']) }};
            --preview-main-bg: {{ old('light_main_background', $companyProfile['light_main_background']) }};
            --preview-right-sidebar-bg: {{ old('light_right_sidebar_background', $companyProfile['light_right_sidebar_background']) }};
            --preview-right-sidebar-color: {{ old('light_right_sidebar_color', $companyProfile['light_right_sidebar_color']) }};
            --preview-page-header-bg: {{ old('light_page_header_background', $companyProfile['light_page_header_background']) }};
            --preview-page-header-color: {{ old('light_page_header_color', $companyProfile['light_page_header_color']) }};
            --preview-card-header-bg: {{ old('light_card_header_background', $companyProfile['light_card_header_background']) }};
            --preview-card-header-color: {{ old('light_card_header_color', $companyProfile['light_card_header_color']) }};
            --preview-content-bg: {{ old('light_content_background', $companyProfile['light_content_background']) }};
            --preview-primary-button-bg: {{ old('light_primary_button_background', $companyProfile['light_primary_button_background']) }};
            --preview-primary-button-color: {{ old('light_primary_button_color', $companyProfile['light_primary_button_color']) }};
            --preview-secondary-button-bg: {{ old('light_secondary_button_background', $companyProfile['light_secondary_button_background']) }};
            --preview-secondary-button-color: {{ old('light_secondary_button_color', $companyProfile['light_secondary_button_color']) }};
            background: var(--preview-main-bg);
            font-size: .75rem;
        }

        .branding-preview-header {
            background: var(--preview-header-bg);
            color: var(--preview-header-color);
            min-height: 2.75rem;
        }

        .branding-preview-logo {
            max-width: 5.5rem;
            max-height: 1.75rem;
            object-fit: contain;
        }

        .branding-preview-mark {
            width: 1.75rem;
            height: 1.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid currentColor;
            border-radius: .375rem;
        }

        .branding-preview-page-header {
            background: var(--preview-page-header-bg);
            color: var(--preview-page-header-color);
        }

        .branding-preview-body {
            grid-template-columns: 1fr 1.8fr 1fr;
            min-height: 11rem;
            background: var(--preview-main-bg);
        }

        .branding-preview-left {
            background: var(--preview-left-sidebar-bg);
            color: var(--preview-left-sidebar-color);
        }

        .branding-preview-content {
            background: var(--preview-content-bg);
        }

        .branding-preview-right {
            background: var(--preview-right-sidebar-bg);
            color: var(--preview-right-sidebar-color);
        }

        .branding-preview-card {
            background: var(--preview-content-bg);
        }

        .branding-preview-card-header {
            background: var(--preview-card-header-bg);
            color: var(--preview-card-header-color);
        }

        .branding-preview-footer {
            background: var(--preview-footer-bg);
            color: var(--preview-footer-color);
        }

        .branding-preview-pill {
            padding: .25rem .35rem;
            border-radius: .25rem;
            margin-bottom: .25rem;
            background: color-mix(in srgb, var(--preview-brand-primary) 12%, transparent);
        }

        .branding-preview-pill.active {
            background: var(--preview-brand-primary);
            color: #ffffff;
        }

        .branding-preview-badge,
        .branding-preview-button {
            display: inline-flex;
            align-items: center;
            min-height: 1.4rem;
            padding: .15rem .35rem;
            border: 0;
            border-radius: .25rem;
            font-size: .68rem;
            line-height: 1;
        }

        .branding-preview-badge.primary {
            background: var(--preview-brand-primary);
            color: #ffffff;
        }

        .branding-preview-badge.secondary {
            background: var(--preview-brand-secondary);
            color: #ffffff;
        }

        .branding-preview-badge.accent {
            background: var(--preview-brand-accent);
            color: #111111;
        }

        .branding-preview-button.primary {
            background: var(--preview-primary-button-bg);
            color: var(--preview-primary-button-color);
        }

        .branding-preview-button.secondary {
            background: var(--preview-secondary-button-bg);
            color: var(--preview-secondary-button-color);
        }

        .branding-preview-link {
            color: var(--preview-brand-primary);
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const preview = document.getElementById('brandingPreview');
            const modeButtons = document.querySelectorAll('[data-preview-mode]');
            const logoInput = document.getElementById('logo');
            const lightLogoInput = document.getElementById('logo_light');
            const darkLogoInput = document.getElementById('logo_dark');
            const previewLogo = document.querySelector('[data-preview-logo]');
            const logoMark = document.querySelector('[data-preview-logo-mark]');
            let previewMode = 'light';

            if (! preview) {
                return;
            }

            const surfaceMap = {
                header_background: '--preview-header-bg',
                header_color: '--preview-header-color',
                footer_background: '--preview-footer-bg',
                footer_color: '--preview-footer-color',
                left_sidebar_background: '--preview-left-sidebar-bg',
                left_sidebar_color: '--preview-left-sidebar-color',
                main_background: '--preview-main-bg',
                right_sidebar_background: '--preview-right-sidebar-bg',
                right_sidebar_color: '--preview-right-sidebar-color',
                page_header_background: '--preview-page-header-bg',
                page_header_color: '--preview-page-header-color',
                card_header_background: '--preview-card-header-bg',
                card_header_color: '--preview-card-header-color',
                content_background: '--preview-content-bg',
                primary_button_background: '--preview-primary-button-bg',
                primary_button_color: '--preview-primary-button-color',
                secondary_button_background: '--preview-secondary-button-bg',
                secondary_button_color: '--preview-secondary-button-color',
            };

            const brandMap = {
                primary_color: '--preview-brand-primary',
                secondary_color: '--preview-brand-secondary',
                accent_color: '--preview-brand-accent',
            };

            const setPreviewVar = (property, value) => {
                if (value) {
                    preview.style.setProperty(property, value);
                }
            };

            const applyMode = (mode) => {
                previewMode = mode;

                Object.entries(surfaceMap).forEach(([field, property]) => {
                    const input = document.querySelector(`[name="${mode}_${field}"]`);
                    setPreviewVar(property, input?.value);
                });

                modeButtons.forEach((button) => {
                    const active = button.dataset.previewMode === mode;
                    button.classList.toggle('btn-primary', active);
                    button.classList.toggle('btn-outline-primary', ! active);
                });

                updateLogoPreview();
            };

            const updateBrandColors = () => {
                Object.entries(brandMap).forEach(([field, property]) => {
                    const input = document.querySelector(`[name="${field}"]`);
                    setPreviewVar(property, input?.value);
                });
            };

            const setLogoFromInput = (input) => {
                if (! input?.files?.length || ! previewLogo) {
                    return false;
                }

                previewLogo.src = URL.createObjectURL(input.files[0]);
                previewLogo.classList.remove('d-none');
                logoMark?.classList.add('d-none');

                return true;
            };

            const updateLogoPreview = () => {
                const preferredInput = previewMode === 'dark' ? darkLogoInput : lightLogoInput;

                if (setLogoFromInput(preferredInput) || setLogoFromInput(logoInput)) {
                    return;
                }

                const savedSrc = previewMode === 'dark'
                    ? previewLogo?.dataset.darkSrc
                    : previewLogo?.dataset.lightSrc;
                const fallbackSrc = previewLogo?.dataset.fallbackSrc;

                if (previewLogo && (savedSrc || fallbackSrc)) {
                    previewLogo.src = savedSrc || fallbackSrc;
                    previewLogo.classList.remove('d-none');
                    logoMark?.classList.add('d-none');

                    return;
                }

                previewLogo?.classList.add('d-none');
                logoMark?.classList.remove('d-none');
            };

            document.querySelectorAll('input[type="color"]').forEach((input) => {
                input.addEventListener('input', () => {
                    updateBrandColors();
                    applyMode(previewMode);
                });
            });

            [logoInput, lightLogoInput, darkLogoInput].forEach((input) => {
                input?.addEventListener('change', updateLogoPreview);
            });

            modeButtons.forEach((button) => {
                button.addEventListener('click', () => applyMode(button.dataset.previewMode));
            });

            updateBrandColors();
            applyMode(previewMode);
        });
    </script>
@endsection
