{{--
    The "Sign in with webid" button, with webid's mark, in the visitor's language:
    <x-webid::button />  ·  <x-webid::button return="/checkout" />  ·  <x-webid::button theme="light" />
    For a person signed in with the site's own password, <x-webid::button connect /> links their account with webid.
    Its look is inline, so it needs no stylesheet; give it a class="…" and plain to style it yourself.
--}}
@props(['return' => null, 'theme' => 'brand', 'plain' => false, 'connect' => false])
@php
    $label = $slot->isEmpty()
        ? ($connect
            ? (['hy' => 'Կապել webid-ի հետ', 'ru' => 'Привязать webid'][app()->getLocale()] ?? 'Connect webid')
            : (['hy' => 'Մուտք webid-ով', 'ru' => 'Войти через webid'][app()->getLocale()] ?? 'Sign in with webid'))
        : $slot;
    $light = $theme === 'light';
    $style = $plain ? null : 'display:inline-flex;align-items:center;gap:10px;padding:10px 18px 10px 12px;border-radius:12px;'
        .'font:600 15px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;text-decoration:none;cursor:pointer;'
        .($light ? 'background:#fff;color:#1f2340;border:1px solid #d9dcea;' : 'background:linear-gradient(135deg,#4f7cff,#7c5cff);color:#fff;border:0;');
    $ink = $light ? 'url(#webid-button-ink)' : '#fff';
@endphp
<a href="{{ route($connect ? 'webid.connect' : 'webid.login', array_filter(['return' => $return])) }}" {{ $attributes->merge(['class' => 'webid-button', 'style' => $style]) }}>
    <svg width="24" height="18" viewBox="11 16 42 32" aria-hidden="true" focusable="false" style="flex:none">
        @if ($light)<defs><linearGradient id="webid-button-ink" gradientUnits="userSpaceOnUse" x1="12" y1="17" x2="52" y2="47"><stop offset="0" stop-color="#4f7cff"/><stop offset="1" stop-color="#7c5cff"/></linearGradient></defs>@endif
        <path d="M15 23 23.5 44 32 34 40.5 44 49 23" fill="none" stroke="{{ $ink }}" stroke-width="5.6" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="32" cy="21.4" r="4" fill="{{ $ink }}"/>
    </svg>
    <span>{{ $label }}</span>
</a>
