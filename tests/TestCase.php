<?php

namespace Webid\Laravel\Tests;

use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Testbench;
use Webid\Laravel\Tests\Fixtures\User;
use Webid\Laravel\WebidServiceProvider;

/**
 * A Laravel site with the package, its users table, and a pretend webid at https://webid.test whose ID tokens are
 * signed with a key made for the test.
 */
abstract class TestCase extends Testbench
{
    protected const ISSUER = 'https://webid.test';

    protected const CLIENT = '01a2aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee';

    protected const SECRET = 'site-secret';

    protected string $privateKey;

    /** @var array<string, mixed> */
    protected array $jwks;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->privateKey = (string) $pem;
        $details = openssl_pkey_get_details($key)['rsa'];
        $this->jwks = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1', 'n' => self::b64($details['n']), 'e' => self::b64($details['e'])]]];
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [WebidServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('webid.key', self::connectionKey());
        $app['config']->set('webid.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected static function connectionKey(): string
    {
        return 'wk_'.self::b64((string) json_encode(['iss' => self::ISSUER, 'client_id' => self::CLIENT, 'client_secret' => self::SECRET]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function discovery(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
            'token_endpoint' => self::ISSUER.'/oauth/token',
            'jwks_uri' => self::ISSUER.'/.well-known/jwks.json',
            'end_session_endpoint' => self::ISSUER.'/oauth/logout',
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function idToken(array $claims): string
    {
        return JWT::encode([
            'iss' => self::ISSUER, 'aud' => self::CLIENT, 'iat' => time(), 'exp' => time() + 300, ...$claims,
        ], $this->privateKey, 'RS256', 'k1');
    }

    protected static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
