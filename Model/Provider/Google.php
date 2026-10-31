<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model\Provider;

use Zero\SocialLogin\Model\Config;
use Zero\SocialLogin\Model\Flow;
use Zero\SocialLogin\Model\Http;
use Zero\SocialLogin\Model\IdToken;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Model\ServiceException;

/**
 * Sign in with Google: OpenID Connect authorization code flow with PKCE.
 * https://developers.google.com/identity/openid-connect/openid-connect
 */
class Google implements ProviderInterface
{
    public const CODE = 'google';
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(
        private readonly Config $config,
        private readonly Http $http,
        private readonly IdToken $idToken
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Google';
    }

    public function isAvailable(): bool
    {
        return $this->config->isProviderEnabled(self::CODE)
            && $this->config->getValue(self::CODE, 'client_id') !== ''
            && $this->config->getSecret(self::CODE, 'client_secret') !== '';
    }

    public function authorizationUrl(Flow $flow, string $redirectUri): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->config->getValue(self::CODE, 'client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $flow->state,
            'nonce' => $flow->nonce,
            'code_challenge' => $flow->codeChallenge(),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function profile(Flow $flow, string $redirectUri, array $params): Profile
    {
        $clientId = $this->config->getValue(self::CODE, 'client_id');
        $token = $this->http->postForm(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            // An array (code[]=x) counts as no code: the provider refuses it, never a 500.
            'code' => is_scalar($params['code'] ?? null) ? (string) $params['code'] : '',
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $this->config->getSecret(self::CODE, 'client_secret'),
            'code_verifier' => $flow->codeVerifier,
        ]);
        if (!is_string($token['id_token'] ?? null)) {
            throw new ServiceException('Google: no ID token');
        }
        $claims = $this->idToken->claims($token['id_token'], self::ISSUERS, $clientId, $flow->nonce);

        return new Profile(
            self::CODE,
            (string) $claims['sub'],
            strtolower(trim((string) ($claims['email'] ?? ''))),
            IdToken::isTrue($claims['email_verified'] ?? false),
            trim((string) ($claims['given_name'] ?? '')),
            trim((string) ($claims['family_name'] ?? ''))
        );
    }
}
