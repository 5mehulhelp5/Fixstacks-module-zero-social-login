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
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Model\ServiceException;

/**
 * Facebook Login (OAuth 2.0 code + PKCE, Graph API with appsecret_proof).
 * It doesn't say whether the email is verified, so it never takes over an
 * existing account by email: connect it in My Account instead.
 */
class Facebook implements ProviderInterface
{
    public const CODE = 'facebook';
    private const GRAPH_VERSION = 'v23.0';
    private const AUTHORIZE_URL = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';
    private const GRAPH_URL = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/';

    public function __construct(
        private readonly Config $config,
        private readonly Http $http
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Facebook';
    }

    public function isAvailable(): bool
    {
        return $this->config->isProviderEnabled(self::CODE)
            && $this->config->getValue(self::CODE, 'app_id') !== ''
            && $this->config->getSecret(self::CODE, 'app_secret') !== '';
    }

    public function authorizationUrl(Flow $flow, string $redirectUri): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->config->getValue(self::CODE, 'app_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'email,public_profile',
            'state' => $flow->state,
            'code_challenge' => $flow->codeChallenge(),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function profile(Flow $flow, string $redirectUri, array $params): Profile
    {
        $secret = $this->config->getSecret(self::CODE, 'app_secret');
        $token = $this->http->getJson(self::GRAPH_URL . 'oauth/access_token', [
            'client_id' => $this->config->getValue(self::CODE, 'app_id'),
            'client_secret' => $secret,
            'redirect_uri' => $redirectUri,
            // An array (code[]=x) counts as no code: the provider refuses it, never a 500.
            'code' => is_scalar($params['code'] ?? null) ? (string) $params['code'] : '',
            'code_verifier' => $flow->codeVerifier,
        ]);
        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new ServiceException('Facebook: no access token');
        }
        $me = $this->http->getJson(self::GRAPH_URL . 'me', [
            'fields' => 'id,email,first_name,last_name',
            'access_token' => $accessToken,
            'appsecret_proof' => hash_hmac('sha256', $accessToken, $secret),
        ]);
        if (!is_scalar($me['id'] ?? null) || (string) $me['id'] === '') {
            throw new ServiceException('Facebook: no user ID');
        }

        return new Profile(
            self::CODE,
            (string) $me['id'],
            strtolower(trim((string) ($me['email'] ?? ''))),
            false,
            trim((string) ($me['first_name'] ?? '')),
            trim((string) ($me['last_name'] ?? ''))
        );
    }
}
