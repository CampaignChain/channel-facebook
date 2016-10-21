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

namespace CampaignChain\Channel\FacebookBundle\Controller\REST;

use CampaignChain\Channel\TwitterBundle\REST\TwitterClient;
use CampaignChain\CoreBundle\Controller\REST\BaseModuleController;
use CampaignChain\CoreBundle\EntityService\LocationService;
use FOS\RestBundle\Controller\Annotations as REST;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use FOS\RestBundle\Request\ParamFetcher;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

/**
 * @REST\NamePrefix("campaignchain_channel_facebook_rest_")
 *
 * Class ChannelController
 * @package CampaignChain\Channel\FacebookBundle\Controller\REST
 */
class ChannelController extends BaseModuleController
{
    /**
     * Search for users and pages on Twitter.
     *
     * Example Request
     * ===============
     *
     *      GET /api/v1/p/campaignchain/channel-facebook/users/search?q=ordnas&location=42
     *
     * Example Response
     * ================
     *
    [
        {
            "twitter_status": {
                "id": 26,
                "message": "Alias quaerat natus iste libero. Et dolor assumenda odio sequi. http://www.schmeler.biz/nostrum-quia-eaque-quo-accusantium-voluptatem.html",
                "createdDate": "2015-12-14T11:02:23+0000"
            }
        },
        {
            "status_location": {
                "id": 63,
                "status": "unpublished",
                "createdDate": "2015-12-14T11:02:23+0000"
            }
        },
        {
            "activity": {
                "id": 82,
                "equalsOperation": true,
                "name": "Announcement 26 on Twitter",
                "startDate": "2012-01-10T05:23:34+0000",
                "status": "paused",
                "createdDate": "2015-12-14T11:02:23+0000"
            }
        },
        {
            "operation": {
                "id": 58,
                "name": "Announcement 26 on Twitter",
                "startDate": "2012-01-10T05:23:34+0000",
                "status": "open",
                "createdDate": "2015-12-14T11:02:23+0000"
            }
        }
    ]
     *
     * @ApiDoc(
     *  section="Packages: Facebook"
     * )
     *
     * @REST\QueryParam(
     *      name="q",
     *      map=false,
     *      requirements="(?i)^[0-9a-z]+(?:\s[0-9a-z]+)*$",
     *      description="The search query to run against people search."
     *  )
     *
     * @REST\QueryParam(
     *      name="location",
     *      map=false,
     *      requirements="\d+",
     *      description="The ID of a CampaignChain Location you'd like to use to connect with Twitter."
     *  )
     */
    public function getUsersSearchAction(ParamFetcher $paramFetcher)
    {
        try {
            $params = $paramFetcher->all();
            $data = array();

            /** @var LocationService $locationService */
            $locationService = $this->get('campaignchain.core.location');
            $location = $locationService->getLocation($params['location']);

            /** @var TwitterClient $channelRESTService */
            $channelRESTService = $this->get('campaignchain.channel.facebook.rest.client');
            $connection = $channelRESTService->connectByLocation($location);

            $response = $connection->api(
                '/search?q='.urlencode($params['q']).'&type=user&fields='.urlencode('name,id,picture').'&limit=3',
                'GET'
            );

            foreach($response['data'] as $user){
                $data[] = array(
                    'insert_name' => $user['id'],
                    'display_name' => $user['name'],
                    'search_key' => $user['name'],
                    'image' => $user['picture']['data']['url'],
                );
            }

            $response = $connection->api(
                '/search?q='.urlencode($params['q']).'&type=page&fields='.urlencode('name,id,picture').'&limit=3',
                'GET'
            );

            foreach($response['data'] as $user){
                $data[] = array(
                    'insert_name' => $user['id'],
                    'display_name' => $user['name'],
                    'search_key' => $user['name'],
                    'image' => $user['picture']['data']['url'],
                );
            }

            return $this->response($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode());
        }
    }
}