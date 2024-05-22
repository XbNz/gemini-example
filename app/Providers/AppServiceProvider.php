<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use GuzzleHttp\RequestOptions;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Saloon\Http\Auth\TokenAuthenticator;
use XbNz\Gemini\AIPlatform\Contracts\GoogleAIPlatformInterface;
use XbNz\Gemini\AIPlatform\GoogleAIPlatformService;
use XbNz\Gemini\AIPlatform\Saloon\Connectors\GoogleAIPlatformConnector;
use XbNz\Gemini\OAuth2\Contracts\GoogleOAuth2Interface;
use XbNz\Gemini\OAuth2\DataTransferObjects\Requests\TokenRequestDTO;
use XbNz\Gemini\OAuth2\GoogleOAuth2Service;
use XbNz\Gemini\OAuth2\Saloon\Connectors\GoogleOAuthConnector;
use XbNz\Gemini\OAuth2\ValueObjects\GoogleServiceAccount;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleOAuth2Interface::class, function (Application $app) {
            return new GoogleOAuth2Service(
                logger: $app->make(LoggerInterface::class)
            );
        });

        $this->app->bind(GoogleAIPlatformInterface::class, function (Application $app) {
            $connector = new GoogleAIPlatformConnector(
                $app->make('config')->get('services.google_ai_platform.project_id'),
                $app->make('config')->get('services.google_ai_platform.region'),
            );

            $connector->authenticate(
                new TokenAuthenticator(
                    $app->make('cache')->remember(
                        'google_api_platform_token',
                        CarbonImmutable::now()->addMinutes(60),
                        function () use ($app) {
                            return $app->make(GoogleOAuth2Interface::class)->token(
                                new TokenRequestDTO(
                                    googleServiceAccount: new GoogleServiceAccount(
                                        $app->make('config')->get('services.google_ai_platform.client_email'),
                                        $app->make('config')->get('services.google_ai_platform.private_key'),
                                    ),
                                    scope: 'https://www.googleapis.com/auth/cloud-platform',
                                    issuedAt: CarbonImmutable::now(),
                                    expiration: CarbonImmutable::now()->addHour()
                                )
                            )->accessToken;
                        }
                    ),
                )
            );

            $connector->sender()->getHandlerStack()
                ->push(function (callable $handler) {
                    return function (RequestInterface $request, array $options) use ($handler) {
                        $options[RequestOptions::TIMEOUT] = 300;
                        return $handler($request, $options);
                    };
                });

            return new GoogleAIPlatformService(
                $connector,
                $app->make('log')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
