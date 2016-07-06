<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain, Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Channel\FacebookBundle\REST;

use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Exception\ExternalApiException;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\ApplicationService;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\TokenService;
use Facebook\Entities\AccessToken;
use Facebook\FacebookSDKException;
use Facebook\FacebookSession;

class FacebookClient
{
    const RESOURCE_OWNER = 'Facebook';

    protected $appService;
    protected $tokenService;

    protected $app;
    protected $token;

    public function __construct(ApplicationService $appService, TokenService $tokenService)
    {
        $this->appService = $appService;
        $this->tokenService = $tokenService;
    }

    public function connectByActivity(Activity $activity)
    {
        // Get Access Token and Token Secret
        $this->token = $this->tokenService->getToken($activity->getLocation());

        return $this->connect();
    }

    public function connect($accessToken = null)
    {
        if (!$this->token) {
            $accessToken = (string) $accessToken;
        } else {
            $accessToken = $this->token->getAccessToken();
        }

        if (!$accessToken) {
            throw new \Exception('You must provide an access token.');
        }

        $this->app = $this->appService->getApplication(self::RESOURCE_OWNER);

        $config = [
            'appId' => $this->app->getKey(),
            'secret' => $this->app->getSecret(),
            'fileUpload' => false, // optional
            'allowSignedRequest' => false, // optional, but should be set to false for non-canvas apps
        ];

        $facebook = new \Facebook($config);

        $facebook->setAccessToken($accessToken);
        $user = $facebook->getUser();

        try {
            if ($user) {
                return $facebook;
            } elseif ($this->token) {
                // Renew access token.
                FacebookSession::setDefaultApplication($this->app->getKey(), $this->app->getSecret());

                $longLivedAccessToken = new AccessToken(
                    $this->token->getAccessToken()
                );

                try {
                    // Get a code from a long-lived access token
                    $code = AccessToken::getCodeFromAccessToken($longLivedAccessToken);
                } catch (FacebookSDKException $e) {
                    throw new ExternalApiException($e->getMessage(), $e->getCode(), $e);
                }

                try {
                    // Get a new long-lived access token from the code
                    $newLongLivedAccessToken = AccessToken::getAccessTokenFromCode($code);
                } catch (FacebookSDKException $e) {
                    throw new ExternalApiException($e->getMessage(), $e->getCode(), $e);
                }

                $accessToken = new AccessToken($newLongLivedAccessToken);
//                dump($accessToken->getInfo());exit;

                $this->token->setAccessToken($newLongLivedAccessToken);
                $this->tokenService->setToken($this->token);

                // Connect to Facebook REST API again.
                $this->connect();
            }
        } catch (\FacebookApiException $e) {
            $user = null;
        }
    }

    public function connectByLocation(Location $location)
    {
        // Get Access Token and Token Secret
        $this->token = $this->tokenService->getToken($location);

        return $this->connect();
    }
}
