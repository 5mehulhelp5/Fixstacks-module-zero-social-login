<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model\Provider;

use Zero\SocialLogin\Model\AppleClientSecret;
use Zero\SocialLogin\Model\Config;
use Zero\SocialLogin\Model\Flow;
use Zero\SocialLogin\Model\Http;
use Zero\SocialLogin\Model\IdToken;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Model\ServiceException;

/**
 * Sign in with Apple (web). Name and email need response_mode=form_post, a
 * cross-site POST without the session cookie: the callback turns it into a
 * GET (Plugin\KeepSessionOnApplePost). The name comes only on the first
 * sign-in; relay emails count as verified.
 */
class Apple implements ProviderInterface
{
    public const CODE = 'apple';
    private const AUTHORIZE_URL = 'https://appleid.apple.com/auth/authorize';
    private const TOKEN_URL = 'https://appleid.apple.com/auth/token';
    private const ISSUERS = ['https://appleid.apple.com'];

    public function __construct(
        private readonly Config $config,
        private readonly Http $http,
        private readonly IdToken $idToken,
        private readonly AppleClientSecret $clientSecret
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Apple';
    }

    public function isAvailable(): bool
    {
        return $this->config->isProviderEnabled(self::CODE)
            && $this->config->getValue(self::CODE, 'client_id') !== ''
            && $this->config->getValue(self::CODE, 'team_id') !== ''
            && $this->config->getValue(self::CODE, 'key_id') !== ''
            && $this->config->getSecret(self::CODE, 'private_key') !== '';
    }

    public function authorizationUrl(Flow $flow, string $redirectUri): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->config->getValue(self::CODE, 'client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope' => 'name email',
            'state' => $flow->state,
            'nonce' => $flow->nonce,
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
            'client_secret' => $this->clientSecret->create(
                $this->config->getValue(self::CODE, 'team_id'),
                $this->config->getValue(self::CODE, 'key_id'),
                $clientId,
                $this->config->getSecret(self::CODE, 'private_key')
            ),
        ]);
        if (!is_string($token['id_token'] ?? null)) {
            throw new ServiceException('Apple: no ID token');
        }
        $claims = $this->idToken->claims($token['id_token'], self::ISSUERS, $clientId, $flow->nonce);
        $user = json_decode(is_scalar($params['user'] ?? null) ? (string) $params['user'] : '', true);
        $name = is_array($user) && is_array($user['name'] ?? null) ? $user['name'] : [];

        return new Profile(
            self::CODE,
            (string) $claims['sub'],
            strtolower(trim((string) ($claims['email'] ?? ''))),
            IdToken::isTrue($claims['email_verified'] ?? false),
            trim((string) ($name['firstName'] ?? '')),
            trim((string) ($name['lastName'] ?? ''))
        );
    }
}
