<?php
/*
 * Copyright 2016 CampaignChain, Inc. <info@campaignchain.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CampaignChain\Channel\FacebookBundle\REST;

use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Exception\ExternalApiException;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\ApplicationService;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\TokenService;
use Facebook\Facebook;
use Facebook\Entities\AccessToken;
use Facebook\FacebookSDKException;
use Facebook\FacebookSession;
use GuzzleHttp\Client;

class FacebookClient
{
    const RESOURCE_OWNER = 'Facebook';

    /** @var  Facebook */
    protected $client;

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

    public function connectByLocation(Location $location)
    {
        // Get Access Token and Token Secret
        $this->token = $this->tokenService->getToken($location);

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

        $client = new Client();

        $config = [
            'http_client_handler' => new Guzzle6FacebookClient($client),
            'app_id' => $this->app->getKey(),
            'app_secret' => $this->app->getSecret(),
            'default_access_token' => $accessToken,
        ];

        $this->client = new Facebook($config);

        return $this;

//        try {
//            if ($user) {
//                return $facebook;
//            } elseif ($this->token) {
//                // Renew access token.
//                FacebookSession::setDefaultApplication($this->app->getKey(), $this->app->getSecret());
//
//                $longLivedAccessToken = new AccessToken(
//                    $this->token->getAccessToken()
//                );
//
//                try {
//                    // Get a code from a long-lived access token
//                    $code = AccessToken::getCodeFromAccessToken($longLivedAccessToken);
//                } catch (FacebookSDKException $e) {
//                    throw new ExternalApiException($e->getMessage(), $e->getCode(), $e);
//                }
//
//                try {
//                    // Get a new long-lived access token from the code
//                    $newLongLivedAccessToken = AccessToken::getAccessTokenFromCode($code);
//                } catch (FacebookSDKException $e) {
//                    throw new ExternalApiException($e->getMessage(), $e->getCode(), $e);
//                }
//
//                $accessToken = new AccessToken($newLongLivedAccessToken);
////                dump($accessToken->getInfo());exit;
//
//                $this->token->setAccessToken($newLongLivedAccessToken);
//                $this->tokenService->setToken($this->token);
//
//                // Connect to Facebook REST API again.
//                $this->connect();
//            }
//        } catch (\FacebookApiException $e) {
//            $user = null;
//        }
    }

    /**
     * Generic request method to refresh token in case session expired.
     *
     * @param $method
     * @param $uri
     * @param array $body
     * @return mixed
     */
    private function request($method, $uri, $body = array())
    {
        try {
            $res = $this->client->sendRequest($method, $uri, $body);
            return json_decode($res->getBody(), true);
        } catch(FacebookSDKException $e){
            /*
             * If the session expired, then we must request a new token with the
             * refresh token.
             */
            if($e->getCode() == '190'){
//                $this->refreshToken($method, $uri, $body);
//            } else {
                throw new \Exception($e->getMessage());
            }
        }
    }

//    protected function refreshToken($method, $uri, $body)
//    {
//        // Expired, so request a new token with the refresh token.
//        $restUrl = $host.'/services/oauth2/token';
//        $params = [
//            'grant_type'    => 'refresh_token',
//            'refresh_token' => $this->token->getRefreshToken(),
//            'client_id'     => $this->application->getKey(),
//            'client_secret' => $this->application->getSecret(),
//        ];
//
//        $client = new Client();
//        $res = $client->post($restUrl, array('query' => $params));
//        $data = json_decode($res->getBody());
//
//        $this->oauthTokenService->refreshToken(
//            $this->token->getAccessToken(), $data->access_token
//        );
//
//        // Re-connect with new access token and re-issue the request with
//        // the new access token.
//        $this->connect($data->access_token);
//        return $this->client->request($method, $uri, $body);
//    }

    public function getMyPages()
    {
        return $this->request('GET', '/me/accounts');
    }

    public function getPage($id, array $fields = array())
    {
        $query = '';
        if(count($fields)){
            $query = '?fields='.implode(',', $fields);
        }
        return $this->request('GET', '/'.$id.$query);
    }

    public function getPageFanCount($id)
    {
        return $this->getPage($id, array('fan_count'));
    }

    public function postPageMessage($id, $text)
    {
        $params['message'] = $text;

        return $this->request('POST', '/'.$id.'/feed', $params);
    }

    public function getPicture($id)
    {
        return $this->request('GET', '/'.$id.'/picture',
            [
                'redirect' => false,
//                        'height' => '160',
                'type' => 'large',
//                        'width' => '160',
            ]
        );
    }

    public function getRoles($id)
    {
        return $this->request('GET', '/' . $id . '/roles');
    }

    public function getUserFriendsCount($id)
    {
        $params = [
            'summary' => 'true',
        ];
        return $this->request('GET', $id . '/friends', $params);
    }

    public function getLatestPost($id)
    {
        $params['limit'] = '1';

        return $this->request('GET', '/' . $id . '/feed', $params);
    }

    public function getPostLikesCount($id)
    {
        $params["summary"] = "1";
        $res = $this->request('GET', '/'.$id.'/likes', $params);
        return $res['summary']['total_count'];
    }

    public function getPostCommentsCount($id)
    {
        $params["summary"] = "1";
        $params["filter"] = "stream";
        $response = $this->request('GET', '/'.$id.'/comments', $params);
        return $response['summary']['total_count'];
    }

    public function postUserMessage($id, $text, $privacy)
    {
        $privacy = array(
            'value' => $privacy,
        );
        $params['privacy'] = json_encode($privacy);
        $params['message'] = $text;

        return $this->request('POST', '/'.$id.'/feed', $params);
    }

    public function postPhoto($id, $text, $imgUrl)
    {
        $paramsImg['caption'] = $text;
        // Avoid that feed shows "... added a new photo" entry automtically.
        $paramsImg['no_story'] = 1;

        //Facebook handles only 1 image
        $paramsImg['url'] = $imgUrl;

        return $this->request('POST','/'.$id.'/photos', $paramsImg);
    }
}
