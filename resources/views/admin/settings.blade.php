@extends(config('payment-gateway.admin.layout', 'layouts.app'))

@section(config('payment-gateway.admin.title_section', 'page-title'))
    <h1 class="mb-0">{{ __('payment-gateway::admin.title') }}</h1>
@endsection

@section(config('payment-gateway.admin.content_section', 'main'))
    <div class="content-wrapper">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning">{{ session('warning') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger" role="alert">
                <p class="mb-1">{{ __('payment-gateway::admin.messages.invalid') }}</p>
                <ul class="mb-0">
                    @foreach($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('payment-gateway.admin.settings.update') }}" method="POST">
            @csrf

            @foreach($packages as $package)
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h4 class="mb-1">{{ __($package->display_name) }}</h4>
                            <small class="text-muted">{{ __('payment-gateway::admin.package_hint') }}</small>
                        </div>
                        <label class="custom-switch m-0">
                            <input type="hidden" name="packages[{{ $package->package }}]" value="0">
                            <input type="checkbox" name="packages[{{ $package->package }}]" value="1" class="custom-switch-input" {{ $package->enabled ? 'checked' : '' }}>
                            <span class="custom-switch-indicator"></span>
                            <span class="custom-switch-description">{{ __('payment-gateway::admin.enable_package') }}</span>
                        </label>
                    </div>

                    <div class="card-body">
                        @foreach($package->gateways as $gateway)
                            @php($structured = $gateways[$gateway->gateway] ?? ['mode' => null, 'credentials' => [], 'settings' => []])
                            <div class="card border mb-4">
                                <div class="card-header d-flex align-items-center justify-content-between">
                                    <div>
                                        <h5 class="mb-1">{{ __($gateway->display_name) }}</h5>
                                        <small class="text-muted">{{ $gateway->gateway }}</small>
                                    </div>
                                    <label class="custom-switch m-0">
                                        <input type="hidden" name="gateways[{{ $gateway->id }}]" value="0">
                                        <input type="checkbox" name="gateways[{{ $gateway->id }}]" value="1" class="custom-switch-input" {{ $gateway->enabled ? 'checked' : '' }}>
                                        <span class="custom-switch-indicator"></span>
                                        <span class="custom-switch-description">{{ __('payment-gateway::admin.enable_gateway') }}</span>
                                    </label>
                                </div>

                                <div class="card-body">
                                    <div class="row">
                                        @if($structured['mode'] !== null)
                                            @include('payment-gateway::admin.partials.field', ['field' => $structured['mode']])
                                        @endif
                                    </div>

                                    @foreach($structured['credentials'] as $mode => $credentialFields)
                                        @if($credentialFields !== [])
                                            <h6 class="mt-2 mb-3">{{ __('payment-gateway::admin.credentials.'.$mode) }}</h6>
                                            <div class="row">
                                                @foreach($credentialFields as $field)
                                                    @include('payment-gateway::admin.partials.field', ['field' => $field])
                                                @endforeach
                                            </div>
                                        @endif
                                    @endforeach

                                    <h6 class="mt-2 mb-3">{{ __('payment-gateway::admin.settings') }}</h6>
                                    <div class="row">
                                        @foreach($structured['settings'] as $field)
                                            @include('payment-gateway::admin.partials.field', ['field' => $field])
                                        @endforeach

                                        <div class="col-lg-6">
                                            <div class="form-group">
                                                <label>{{ __('payment-gateway::admin.webhook_url') }}</label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control" value="{{ $values[$gateway->gateway . '.webhook_url'] ?? '' }}" readonly>
                                                    <div class="input-group-append"><button class="btn btn-outline-primary" type="button" title="{{ __('payment-gateway::admin.copy') }}" aria-label="{{ __('payment-gateway::admin.copy') }}" onclick="navigator.clipboard.writeText(this.closest('.input-group').querySelector('input').value)"><i class="fas fa-copy"></i></button></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="form-group">
                                                <label>{{ __('payment-gateway::admin.return_url') }}</label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control" value="{{ $values[$gateway->gateway . '.return_url'] ?? '' }}" readonly>
                                                    <div class="input-group-append"><button class="btn btn-outline-primary" type="button" title="{{ __('payment-gateway::admin.copy') }}" aria-label="{{ __('payment-gateway::admin.copy') }}" onclick="navigator.clipboard.writeText(this.closest('.input-group').querySelector('input').value)"><i class="fas fa-copy"></i></button></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="text-right">
                <button type="submit" class="btn btn-primary">{{ __('payment-gateway::admin.save') }}</button>
            </div>
        </form>
    </div>
@endsection
