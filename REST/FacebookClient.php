<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Channel\FacebookBundle\REST;

use Symfony\Component\HttpFoundation\Session\Session;
use Guzzle\Http\Client;
use Guzzle\Plugin\Oauth\OauthPlugin;

class FacebookClient
{
    const RESOURCE_OWNER = 'Facebook';

    protected $container;

    public function setContainer($container)
    {
        $this->container = $container;
    }

    public function connectByActivity($activity){
        $oauthApp = $this->container->get('campaignchain.security.authentication.client.oauth.application');
        $application = $oauthApp->getApplication(self::RESOURCE_OWNER);

        // Get Access Token and Token Secret
        $oauthToken = $this->container->get('campaignchain.security.authentication.client.oauth.token');

        $token = $oauthToken->getToken($activity->getLocation());

        return $this->connect($application->getKey(), $application->getSecret(), $token->getAccessToken());
    }

    public function connect($appId, $appSecret, $accessToken){
        $config = array(
            'appId' => $appId,
            'secret' => $appSecret,
            'fileUpload' => false, // optional
            'allowSignedRequest' => false, // optional, but should be set to false for non-canvas apps
        );

        $facebook = new \Facebook($config);

        $facebook->setAccessToken($accessToken);
        $user = $facebook->getUser();

        try {
            if ($user) {
                return $facebook;
            }
            else {
                return false;
                // Handle errors, if authentication did not work.
                // 1) Check if App is installed.
                // 2) check if access token is valid and retrieve new access token if necessary.
                // Log error, send email, prompt user, ask to check App Key and Secret or to authenticate again
            }
        } catch (\FacebookApiException $e) {
            $user = null;
        }
    }
}