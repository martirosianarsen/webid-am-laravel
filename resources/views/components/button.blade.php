{{-- "Sign in with webid": <x-webid::button /> or <x-webid::button return="/checkout">Մուտք webid-ով</x-webid::button> --}}
@props(['return' => null])
<a href="{{ route('webid.login', array_filter(['return' => $return])) }}" {{ $attributes->merge(['class' => 'webid-button']) }}>{{ $slot->isEmpty() ? __('Sign in with webid') : $slot }}</a>
