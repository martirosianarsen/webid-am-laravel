<?php

namespace Webid\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Webid\Laravel\Tests\Fixtures\User;

class ComponentsTest extends TestCase
{
    #[Test]
    public function the_button_carries_webids_mark_and_speaks_the_page_language(): void
    {
        app()->setLocale('hy');
        $html = Blade::render('<x-webid::button return="/checkout" />');

        $this->assertStringContainsString('href="http://localhost/webid/login?return=%2Fcheckout"', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Մուտք webid-ով', $html);
        $this->assertStringContainsString('background:#0a66e8', $html, 'styled without a stylesheet');

        app()->setLocale('en');
        $this->assertStringContainsString('Sign in with webid', Blade::render('<x-webid::button />'));
        $this->assertStringContainsString('Join', Blade::render('<x-webid::button>Join</x-webid::button>'));
        $this->assertStringNotContainsString('background:#0a66e8', Blade::render('<x-webid::button plain class="btn" />'));
    }

    #[Test]
    public function the_chat_component_loads_webids_widget_for_this_site(): void
    {
        $html = Blade::render('<x-webid::chat mode="agent" target="#inbox" />');

        $this->assertStringContainsString('src="https://chat.webid.test/widget.js"', $html);
        $this->assertStringContainsString('data-client-id="'.self::CLIENT.'"', $html);
        $this->assertStringContainsString('data-token-url="http://localhost/webid/token"', $html);
        // The widget adds the page to come back to; a visitor nobody knows signs in with webid.
        $this->assertStringContainsString('data-login-url="http://localhost/webid/login"', $html);
        $this->assertStringContainsString('data-mode="agent"', $html);
        $this->assertStringContainsString('data-target="#inbox"', $html);
        $this->assertStringNotContainsString('data-title', $html);

        // Someone signed in here with the site's own password connects that account instead of becoming another.
        $this->actingAs(new User);
        $this->assertStringContainsString('data-login-url="http://localhost/webid/connect"', Blade::render('<x-webid::chat />'));
    }
}
