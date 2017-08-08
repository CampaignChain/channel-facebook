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

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Exception\RequestException as GuzzleException;
use Facebook\HttpClients\FacebookHttpClientInterface;
use Facebook\Exceptions\FacebookSDKException;
use Facebook\Http\GraphRawResponse;

class Guzzle6FacebookClient implements FacebookHttpClientInterface
{
    private $client;

    public function __construct(GuzzleClient $client)
    {
        $this->client = $client;
    }

    public function send($url, $method, $body, array $headers, $timeOut)
    {
        $request = new GuzzleRequest($method, $url, $headers, $body);
        try {
            $response = $this->client->send($request, ['timeout' => $timeOut, 'http_errors' => false]);
        } catch (GuzzleException $e) {
            throw new FacebookSDKException($e->getMessage(), $e->getCode());
        }
        $httpStatusCode = $response->getStatusCode();
        $responseHeaders = $response->getHeaders();

        foreach ($responseHeaders as $key => $values) {
            $responseHeaders[$key] = implode(', ', $values);
        }

        $responseBody = $response->getBody()->getContents();

        return new GraphRawResponse(
            $responseHeaders,
            $responseBody,
            $httpStatusCode
        );
    }
}