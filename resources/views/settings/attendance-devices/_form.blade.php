@php
    $editing = isset($device) && $device;
    $selectedBrand = old('brand', $device->brand ?? 'zkteco');
    $selectedConnection = old('connection_type', $device->connection_type ?? 'zkteco_tcp');
@endphp

<div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
    @if (isset($bridges) && $bridges->isNotEmpty())
        <div class="sm:col-span-2">
            <x-ui.select
                name="attendance_bridge_id"
                :label="__('attendance.bridge.device_bridge')"
                :value="old('attendance_bridge_id', $device->attendance_bridge_id ?? null)"
            >
                <option value="">{{ __('attendance.bridge.direct_or_push') }}</option>
                @foreach ($bridges as $bridge)
                    <option value="{{ $bridge->id }}" @selected((string) old('attendance_bridge_id', $device->attendance_bridge_id ?? '') === (string) $bridge->id)>
                        {{ $bridge->name }} — {{ $bridge->isOnline() ? __('attendance.bridge.online') : __('attendance.bridge.offline') }}
                    </option>
                @endforeach
            </x-ui.select>
        </div>
    @endif

    <x-ui.input name="name" :label="__('attendance.fields.name')" :value="old('name', $device->name ?? null)" required maxlength="255" />

    <x-ui.select name="brand" :label="__('attendance.fields.brand')" :value="$selectedBrand" required>
        @foreach ($brands as $key => $brand)
            <option value="{{ $key }}" @selected($selectedBrand === $key)>{{ $brand['label'] }}</option>
        @endforeach
    </x-ui.select>

    <x-ui.input name="model" :label="__('attendance.fields.model')" :value="old('model', $device->model ?? null)" maxlength="255" />

    <x-ui.select name="connection_type" :label="__('attendance.fields.connection_type')" :value="$selectedConnection" required>
        @foreach ($connections as $key => $connection)
            <option value="{{ $key }}" @selected($selectedConnection === $key)>
                {{ $connection['label'] }}
                @if (! empty($connection['default_port']))
                    — {{ __('attendance.fields.port') }} {{ $connection['default_port'] }}
                @endif
            </option>
        @endforeach
    </x-ui.select>

    <x-ui.input name="host" :label="__('attendance.fields.host')" :value="old('host', $device->host ?? null)" dir="ltr" maxlength="255" />
    <x-ui.input name="port" type="number" min="1" max="65535" :label="__('attendance.fields.port')" :value="old('port', $device->port ?? null)" dir="ltr" />

    <div class="sm:col-span-2">
        <x-ui.input name="base_url" type="url" :label="__('attendance.fields.base_url')" :value="old('base_url', $device->base_url ?? null)" dir="ltr" maxlength="1000" placeholder="https://device-or-server.example" />
    </div>

    <x-ui.input name="serial_number" :label="__('attendance.fields.serial_number')" :value="old('serial_number', $device->serial_number ?? null)" dir="ltr" maxlength="255" />
    <x-ui.input name="timezone" :label="__('attendance.fields.timezone')" :value="old('timezone', $device->timezone ?? config('app.timezone'))" dir="ltr" maxlength="255" />

    <x-ui.input name="username" :label="__('attendance.fields.username')" :value="old('username', $device->username ?? null)" autocomplete="off" maxlength="255" />
    <x-ui.input name="password" type="password" :label="__('attendance.fields.password')" :helper="$editing ? __('attendance.leave_blank_to_keep') : null" autocomplete="new-password" maxlength="1000" />

    <div class="sm:col-span-2">
        <x-ui.input name="api_key" type="password" :label="__('attendance.fields.api_key')" :helper="$editing ? __('attendance.leave_blank_to_keep') : null" autocomplete="new-password" maxlength="2000" />
    </div>

    <x-ui.input name="timeout_seconds" type="number" min="1" max="60" :label="__('attendance.fields.timeout_seconds')" :value="old('timeout_seconds', $device->timeout_seconds ?? 8)" />

    <div class="flex flex-col justify-end gap-3 pb-1">
        <input type="hidden" name="tls_verify" value="0">
        <x-ui.toggle name="tls_verify" :label="__('attendance.fields.tls_verify')" :checked="(bool) old('tls_verify', $device->tls_verify ?? true)" />
    </div>

    <div class="sm:col-span-2 border-t border-slate-100 pt-4 dark:border-slate-700">
        <input type="hidden" name="enabled" value="0">
        <x-ui.toggle name="enabled" :label="__('attendance.fields.enabled')" :checked="(bool) old('enabled', $device->enabled ?? true)" />
    </div>
</div>
