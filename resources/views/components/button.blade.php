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
    $style = $plain ? null : 'display:inline-flex;align-items:center;gap:10px;padding:10px 18px 10px 12px;border-radius:980px;'
        .'font:600 15px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;text-decoration:none;cursor:pointer;'
        .($light ? 'background:#fff;color:#1d1d1f;border:1px solid #d2d2d7;' : 'background:#0a66e8;color:#fff;border:0;');
    $ink = $light ? 'url(#webid-button-ink)' : '#fff';
@endphp
<a href="{{ route($connect ? 'webid.connect' : 'webid.login', array_filter(['return' => $return])) }}" {{ $attributes->merge(['class' => 'webid-button', 'style' => $style]) }}>
    {{-- webid's mark: the W with a person; on the light button graphite and blue, on the blue one white. --}}
    <svg width="30" height="15" viewBox="11.5 21.2 61.5 31.3" aria-hidden="true" focusable="false" style="flex:none">
        <path d="M17.5 26 31.5 48.5M67.5 26 53.5 48.5" fill="none" stroke="{{ $light ? '#1d1d1f' : '#fff' }}" stroke-width="7.4" stroke-linecap="round"/>
        <path d="M33.4 44.6 42.5 27 51.6 44.6Z" fill="{{ $light ? '#0a66e8' : '#fff' }}"/>
        <path d="M31.5 48.5 42.5 26.5 53.5 48.5" fill="none" stroke="{{ $light ? '#0a66e8' : '#fff' }}" stroke-width="7.4" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="42.5" cy="33.4" r="3.5" fill="{{ $light ? '#fff' : '#0a66e8' }}"/><path d="M36.6 44.4c0-3.8 2.6-6.3 5.9-6.3s5.9 2.5 5.9 6.3Z" fill="{{ $light ? '#fff' : '#0a66e8' }}"/>
    </svg>
    <span>{{ $label }}</span>
</a>
