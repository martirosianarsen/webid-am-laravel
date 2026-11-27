{{--
    webid's chat on the site's pages (the site needs the Chat service on), with the visitor's webid sign-in:
    <x-webid::chat />                                     a floating button for customers
    <x-webid::chat mode="agent" target="#inbox" />        the staff inbox inside an element of the page
    <x-webid::chat title="Example" lang="hy" />            the chat's title and language
    A visitor signed in here with the site's own password is offered to connect that account with webid (not to sign
    in as another one); anyone else, to sign in with webid.
--}}
@props(['mode' => null, 'target' => null, 'title' => null, 'lang' => null])
@inject('webid', 'Webid\Laravel\Webid')
<script src="{{ $webid->chatScriptUrl() }}"
        data-client-id="{{ $webid->key()->clientId }}"
        data-token-url="{{ route('webid.token') }}"
        data-login-url="{{ route(auth()->check() ? 'webid.connect' : 'webid.login') }}"
        @if ($mode) data-mode="{{ $mode }}" @endif
        @if ($target) data-target="{{ $target }}" @endif
        @if ($title) data-title="{{ $title }}" @endif
        @if ($lang) data-lang="{{ $lang }}" @endif
        defer></script>
