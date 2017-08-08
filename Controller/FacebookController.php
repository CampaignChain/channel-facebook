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

namespace CampaignChain\Channel\FacebookBundle\Controller;

use CampaignChain\Channel\FacebookBundle\REST\FacebookClient;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Location\FacebookBundle\Entity\Page;
use CampaignChain\Location\FacebookBundle\Entity\User;
use CampaignChain\Security\Authentication\Client\OAuthBundle\Entity\Token;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

class FacebookController extends Controller
{
    const RESOURCE_OWNER = 'Facebook';

    private $applicationInfo = [
        'key_labels' => ['id', 'App ID'],
        'secret_labels' => ['secret', 'App Secret'],
        'config_url' => 'https://developers.facebook.com/apps',
        'parameters' => [
            'trustForwarded' => false,
            'display' => 'popup',
            'scope' => 'public_profile, publish_pages, user_friends, email, publish_actions, manage_pages, read_insights',
        ],
    ];

    public function getLogger()
    {
        return $this->has('monolog.logger.external') ? $this->get('monolog.logger.external') : $this->get('monolog.logger');
    }

    public function createAction()
    {
        $oauthApp = $this->get('campaignchain.security.authentication.client.oauth.application');
        $application = $oauthApp->getApplication(self::RESOURCE_OWNER);

        if (!$application) {
            return $oauthApp->newApplicationTpl(self::RESOURCE_OWNER, $this->applicationInfo);
        } else {
            return $this->render(
                'CampaignChainChannelFacebookBundle:Create:index.html.twig',
                [
                    'page_title' => 'Connect with '.self::RESOURCE_OWNER,
                    'app_id' => $application->getKey(),
                    'server_name' => $_SERVER['SERVER_NAME'],
                ]
            );
        }
    }

    public function loginAction()
    {
        $oauth = $this->get('campaignchain.security.authentication.client.oauth.authentication');
        $status = $oauth->authenticate(self::RESOURCE_OWNER, $this->applicationInfo, false, false);

        if ($status) {
            $wizard = $this->get('campaignchain.core.channel.wizard');
            $wizard->set('profile', $oauth->getProfile());
            // Allow to easily find the Facebook user's ID through the Wizard.
            $wizard->set('facebook_user_id', $oauth->getProfile()->identifier);
            // Save the access token in the Wizard.
            $tokens[$oauth->getProfile()->identifier] = $oauth->getToken();
            $wizard->set('tokens', $tokens);
            $redirect = $this->generateUrl('campaignchain_channel_facebook_location_add');
        } else {
            // A channel already exists that has been connected with this Facebook account
            $this->addFlash(
                'warning',
                // TODO: Provide link where to edit existing channel
                'A channel already exists that has been connected with this Facebook account.'
            );

            $redirect = $this->generateUrl('campaignchain_core_location');
        }

        return $this->render(
            'CampaignChainChannelFacebookBundle:Create:login.html.twig',
            [
                'redirect' => $redirect,
            ]
        );
    }

    public function addLocationAction(Request $request)
    {
        $wizard = $this->get('campaignchain.core.channel.wizard');
        $channel = $wizard->getChannel();
        $profile = $wizard->get('profile');

        $locations = [];

        $locationName = $profile->displayName;

        // Get the location module for the user stream.
        $locationService = $this->get('campaignchain.core.location');
        $locationModuleUser = $locationService->getLocationModule(
            'campaignchain/location-facebook',
            'campaignchain-facebook-user'
        );
        // Create the location instance for the user stream.
        $locationUser = new Location();
        $locationUser->setChannel($channel);
        $locationUser->setName($locationName);
        $locationUser->setIdentifier($profile->identifier);
        $locationUser->setImage($profile->photoURL);
        $locationUser->setLocationModule($locationModuleUser);
        $locationModuleUser->addLocation($locationUser);

        $locations[$profile->identifier] = $locationUser;

        // Connect to Facebook to retrieve pages related to the user.
        $tokens = $wizard->get('tokens');
        $client = $this->container->get('campaignchain.channel.facebook.rest.client');
        /** @var FacebookClient $connection */
        $connection = $client->connect($tokens[$profile->identifier]->getAccessToken());

        if ($connection) {
            // TODO: Check whether user has manage_pages permission with /me/permissions

            // check if the user owns Facebook pages
            $response = $connection->getMyPages();
            $pagesData = $response['data'];

            if (is_array($pagesData) && count($pagesData)) {
                // TODO: Should we check whether the Facebook page has actually been published (through is_published), because if not, then posting to it won't make sense? Same with can_post and perms from /me/accounts?

                // Get the location module for the page stream.
                $locationModulePage = $locationService->getLocationModule(
                    'campaignchain/location-facebook',
                    'campaignchain-facebook-page'
                );

                // User owns pages, so let's build the form and ask him whether to create channels for each of them
                // with the respective channel name
                foreach ($pagesData as $pageData) {
                    // Save the token in the Wizard.
                    $tokens = $wizard->get('tokens');
                    $newToken = new Token();
                    $newToken->setAccessToken($pageData['access_token']);
                    $application = $tokens[$wizard->get('facebook_user_id')]->getApplication();
                    $newToken->setApplication($application);
                    $tokens[$pageData['id']] = $newToken;
                    $wizard->set('tokens', $tokens);

                    // Get the page picture
                    $pageConnection = $client->connect($pageData['access_token']);
                    $pagePicture = $pageConnection->getPicture($pageData['id']);
                    $pageData['picture_url'] = $pagePicture['data']['url'];

                    // Create the location instance for the page stream.
                    $locationPage = new Location();
                    $locationPage->setChannel($channel);
                    $locationPage->setName($pageData['name']);
                    $locationPage->setIdentifier($pageData['id']);
                    $locationPage->setImage($pageData['picture_url']);
                    $locationPage->setLocationModule($locationModulePage);
                    $locationModulePage->addLocation($locationPage);

                    $locations[$pageData['id']] = $locationPage;

                    $wizardPages[$pageData['id']] = $pageData;
                }
                $wizard->set('pagesData', $wizardPages);
            }
        }

        $data = [];
        $form = $this->createFormBuilder($data);
        foreach ($locations as $identifier => $location) {
            // Has the page already been added as a location?
            $repository = $this->getDoctrine()->getRepository('CampaignChainCoreBundle:Location');
            $pageExists = $repository->findOneBy(
                [
                    'identifier' => $identifier,
                    'locationModule' => $location->getLocationModule(),
                ]
            );

            // Compose the checkbox form field.
            $form->add(
                $identifier,
                CheckboxType::class,
                [
                    'label' => '<img class="campaignchain-location-image-input-prepend" src="'.$location->getImage(
                        ).'"> '.$location->getName(),
                    'required' => false,
                    'data' => true,
                    'mapped' => false,
                    'disabled' => $pageExists,
                    'attr' => [
                        'align_with_widget' => true,
                    ],
                ]
            );

            // If a location has already been added before, remove it from this process.
            // TODO: Also assign existing locations to the new FB user.
            if ($pageExists) {
                unset($locations[$identifier]);
            }
        }

        $form = $form->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            // Find out which locations should be added, i.e. which respective checkbox is active.
            foreach ($locations as $identifier => $location) {
                if (!$form->get($identifier)->getData()) {
                    unset($locations[$identifier]);
                    $wizard->removeLocation($identifier);
                }
            }

            // If there's at least one location to be added, then have the user configure it.
            if (is_array($locations) && count($locations)) {
                $wizard->setLocations($locations);

                return $this->redirectToRoute('campaignchain_channel_facebook_location_configure', ['step' => 0]);
            } else {
                $this->addFlash(
                    'warning',
                    'No new location has been added.'
                );

                return $this->redirectToRoute('campaignchain_core_location');
            }
        }

        return $this->render(
            'CampaignChainCoreBundle:Base:new.html.twig',
            [
                'page_title' => 'Add Facebook Locations',
                'form' => $form->createView(),
            ]
        );
    }

    public function configureLocationAction(Request $request, $step)
    {
        $wizard = $this->get('campaignchain.core.channel.wizard');
        $locations = $wizard->getLocations();

        // Get the identifier of the first element in the locations array.
        $identifier = array_keys($locations)[$step];

        // Retrieve the current location object.
        $location = $locations[$identifier];

        $locationType = $this->get('campaignchain.core.form.type.location');
        $locationType->setBundleName($location->getLocationModule()->getBundle()->getName());
        $locationType->setModuleIdentifier($location->getLocationModule()->getIdentifier());
        $locationType->setView('hide_url');

        $form = $this->createForm($locationType, $location);
        $form->handleRequest($request);

        if ($form->isValid()) {
            // Is the location a Facebook user or page? The related location module will tell.
            if ($location->getLocationModule()->getIdentifier() == 'campaignchain-facebook-user') {
                // The display name of the Facebook user will be the name of the CampaignChain channel.
                $wizard->setName($location->getName());
                // Get the OAuth profile data from the Wizard.
                $profile = $wizard->get('profile');
                // Define the URL of the location
                $location->setUrl($profile->profileURL);

                // Here we handle the specific data of the user stream as provided by Facebook.
                $user = new User();
                $user->setLocation($location);
                $user->setScope($this->applicationInfo['parameters']['scope']);
                $user->setIdentifier($profile->identifier);
                $user->setDisplayName($profile->displayName);
                $user->setFirstName($profile->firstName);
                $user->setLastName($profile->lastName);
                $user->setDescription($profile->description);
                $user->setGender($profile->gender);
                $user->setLanguage($profile->language);
                $user->setAge($profile->age);
                $user->setEmail($profile->email);
                $user->setEmailVerified($profile->emailVerified);
                $user->setPhone($profile->phone);
                $user->setAddress($profile->address);
                $user->setCountry($profile->country);
                $user->setRegion($profile->region);
                $user->setCity($profile->city);
                $user->setZip($profile->zip);
                $user->setWebsiteUrl($profile->webSiteURL);
                $user->setProfileUrl($profile->profileURL);
                $user->setProfileImageUrl($profile->photoURL);
                $obj = new \ReflectionObject($profile);
                if ($obj->hasProperty('coverInfoUrl')) {
                    $user->setCoverInfoUrl($profile->coverInfoURL);
                }

                // Remember the user object in the Wizard.
                $wizard->set($user->getIdentifier(), $user);
            } else {
                $pagesData = $wizard->get('pagesData');
                $pageData = $pagesData[$identifier];

                // Connect to Facebook to retrieve detailed info about this page.
                $client = $this->container->get('campaignchain.channel.facebook.rest.client');
                $tokens = $wizard->get('tokens');
                /** @var FacebookClient $connection */
                $connection = $client->connect($tokens[$wizard->get('facebook_user_id')]->getAccessToken());

                $fields = [
                    'about',
                    'link',
                    'name',
                    'username',
                    'description',
                    'can_post',
                    'category',
                    'cover',
                    'is_published',
                ];
                $response = $connection->getPage($identifier, $fields);
                $pageData = array_merge($pageData, $response);

                // Define the URL of the location
                $location->setUrl($pageData['link']);

                $page = new Page();
                $page->setLocation($location);

                $page->setIdentifier($identifier);
                $page->setName($pageData['name']);
                if (isset($pageData['username'])) {
                    $page->setUsername($pageData['username']);
                }
                if (isset($pageData['description'])) {
                    $page->setDescription($pageData['description']);
                }
                if (isset($pageData['about'])) {
                    $page->setAbout($pageData['about']);
                }
                $page->setPermissions($pageData['perms']);
                $page->setCanPost($pageData['can_post']);
                $page->setCategory($pageData['category']);
                if (isset($pageData['cover'])) {
                    $page->setCoverId($pageData['cover']['cover_id']);
                    $page->setCoverSource($pageData['cover']['source']);
                    $page->setCoverOffsetX($pageData['cover']['offset_x']);
                    $page->setCoverOffsetY($pageData['cover']['offset_y']);
                }
                $page->setIsPublished($pageData['is_published']);
                $page->setLink($pageData['link']);
                $page->setPictureUrl($pageData['picture_url']);

                // Remember the page object in the Wizard.
                $wizard->set($page->getIdentifier(), $page);
            }

            // Add the updated Location to the wizard
            $wizard->addLocation($identifier, $location);

            // Are there still locations to be configured?
            if (isset(array_keys($locations)[$step + 1])) {
                // Redirect to this same page to configure the next location.
                return $this->redirectToRoute('campaignchain_channel_facebook_location_configure', ['step' => $step + 1]);
            } else {
                // We are done with configuring the locations, so lets end the Wizard and persist the locations.
                $em = $this->getDoctrine()->getManager();
                try {
                    $em->getConnection()->beginTransaction();

                    // If the user is not in the session, then it has already been
                    // connected previously.
                    if ($wizard->has($wizard->get('facebook_user_id'))) {
                        $user = $wizard->get($wizard->get('facebook_user_id'));
                    } else {
                        $user = $this->getDoctrine()
                            ->getRepository('CampaignChainLocationFacebookBundle:User')
                            ->findOneByIdentifier($wizard->get('facebook_user_id'));
                    }

                    $flashBagMsg = '';

                    foreach ($locations as $identifier => $location) {
                        // Persist the Facebook user- and page-specific data.
                        $fbLocation = $wizard->get($identifier);
                        $em->persist($fbLocation);

                        if($fbLocation instanceof Page){
                            // Add related User account to Page.
                            $fbLocation->addUser($user);

                            $flashBagMsg .= '<li>Page: <a href="'.$fbLocation->getLink().'">'.$fbLocation->getName().'</a></li>';
                        } elseif($fbLocation instanceof User) {
                            $flashBagMsg .= '<li>User stream: <a href="'.$fbLocation->getProfileUrl().'">'.$fbLocation->getDisplayName().'</a></li>';
                        }

                        // schedule job to get metrics from now on
                        if ($location->getLocationModule()->getIdentifier() === 'campaignchain-facebook-page') {
                            $this->get('campaignchain.job.report.location.facebook')->schedule($location);
                        }
                    }

                    $tokens = $wizard->get('tokens');

                    $wizard->persist();

                    /*
                     * Store all access tokens per location in the OAuth Client
                     * bundle's Token entity, but only for the Facebook user
                     * locations, not the page locations.
                     */
                    $tokenService = $this->get('campaignchain.security.authentication.client.oauth.token');
                    foreach ($tokens as $identifier => $token) {
                        if (isset($locations[$identifier])) {
                            $token = $em->merge($token);
                            $token->setLocation($locations[$identifier]);
                            $tokenService->setToken($token);
                        }
                    }

                    $em->flush();

                    $this->addFlash(
                        'success',
                        'The following locations are now connected:'.
                        '<ul>'.$flashBagMsg.'</ul>'
                    );

                    $wizard->end();

                    $em->getConnection()->commit();
                } catch(\Exception $e) {
                    $em->getConnection()->rollback();

                    $message = 'An error occurred, please try to re-connect all Locations: ';

                    if($this->get('kernel')->getEnvironment() == 'dev'){
                        $message .= $e->getMessage().' '.$e->getFile().' '.$e->getLine().'<br/>'.$e->getTraceAsString();
                    } else {
                        $message .= $e->getMessage();
                    }
                    $this->addFlash(
                        'warning',
                        $message
                    );

                    $this->getLogger()->error($e->getMessage(), array(
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTrace(),
                    ));
                }

                return $this->redirectToRoute('campaignchain_core_location');
            }
        }

        return $this->render(
            'CampaignChainLocationFacebookBundle::new.html.twig',
            [
                'page_title' => 'Configure Facebook Location',
                'form' => $form->createView(),
                'location' => $location,
            ]
        );
    }
}
