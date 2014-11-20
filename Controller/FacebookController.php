<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) Sandro Groganz <sandro@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Channel\FacebookBundle\Controller;

use CampaignChain\CoreBundle\Entity\Channel,
    CampaignChain\CoreBundle\Entity\Location,
    CampaignChain\Location\FacebookBundle\Entity\FacebookUser,
    CampaignChain\Location\FacebookBundle\Entity\FacebookPage;
use CampaignChain\Security\Authentication\Client\OAuthBundle\Entity\Token;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Request;

class FacebookController extends Controller
{
    const RESOURCE_OWNER = 'Facebook';

    private $applicationInfo = array(
        'key_labels' => array('id', 'App ID'),
        'secret_labels' => array('secret', 'App Secret'),
        'config_url' => 'https://developers.facebook.com/apps',
        'parameters' => array(
            "trustForwarded" => false,
            "display" => "popup",
            "scope" => "public_profile, user_friends, email, user_about_me, user_activities, user_events, user_likes, user_photos, user_status, user_videos, user_website, publish_actions, manage_pages",
        ),
    );

    public function createAction()
    {
        $oauthApp = $this->get('campaignchain.security.authentication.client.oauth.application');
        $application = $oauthApp->getApplication(self::RESOURCE_OWNER);

        if(!$application){
            return $oauthApp->newApplicationTpl(self::RESOURCE_OWNER, $this->applicationInfo);
        }
        else {
            return $this->render(
                'CampaignChainChannelFacebookBundle:Create:index.html.twig',
                array(
                    'page_title' => 'Connect with '.self::RESOURCE_OWNER,
                    'app_id' => $application->getKey(),
                )
            );
        }
    }

    public function loginAction(Request $request){
        $oauth = $this->get('campaignchain.security.authentication.client.oauth.authentication');
        $status = $oauth->authenticate(self::RESOURCE_OWNER, $this->applicationInfo);

        if($status){
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
            $this->get('session')->getFlashBag()->add(
                'warning',
                // TODO: Provide link where to edit existing channel
                'A channel already exists that has been connected with this Facebook account.'
            );

            $redirect = $this->generateUrl('campaignchain_core_channel');
        }

        return $this->render(
            'CampaignChainChannelFacebookBundle:Create:login.html.twig',
            array(
                'redirect' => $redirect,
            )
        );
    }

    public function addLocationAction(Request $request){
        $wizard = $this->get('campaignchain.core.channel.wizard');
        $channel = $wizard->getChannel();
        $profile = $wizard->get('profile');

        $locations = array();

        $locationName = $profile->displayName;
        $username = $profile->username;

        if(!empty($username)){
            $locationName .= ' ('.$username.')';
        }

        // Get the location module for the user stream.
        $locationService = $this->get('campaignchain.core.location');
        $locationModuleUser = $locationService->getLocationModule('campaignchain/location-facebook', 'campaignchain-facebook-user');
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
        $oauthApp = $this->get('campaignchain.security.authentication.client.oauth.application');
        $application = $oauthApp->getApplication(self::RESOURCE_OWNER);
        $tokens = $wizard->get('tokens');

        $client = $this->container->get('campaignchain.channel.facebook.rest.client');
        $connection = $client->connect($application->getKey(), $application->getSecret(), $tokens[$profile->identifier]->getAccessToken());

        if($connection) {
            // TODO: Check whether user has manage_pages permission with /me/permissions

            // check if the user owns Facebook pages
            $response = $connection->api('/me/accounts');
            $pagesData = $response['data'];

            if(is_array($pagesData) && count($pagesData)){
                // TODO: Should we check whether the Facebook page has actually been published (through is_published), because if not, then posting to it won't make sense? Same with can_post and perms from /me/accounts?

                // Get the location module for the page stream.
                $locationModulePage = $locationService->getLocationModule('campaignchain/location-facebook', 'campaignchain-facebook-page');

                // User owns pages, so let's build the form and ask him whether to create channels for each of them
                // with the respective channel name
                foreach($pagesData as $pageData){
                    // Save the token in the Wizard.
                    $tokens = $wizard->get('tokens');
                    $newToken = new Token();
                    $newToken->setAccessToken($pageData['access_token']);
                    $application = $tokens[$wizard->get('facebook_user_id')]->getApplication();
                    $newToken->setApplication($application);
                    $tokens[$pageData['id']] = $newToken;
                    $wizard->set('tokens', $tokens);

                    // Get the page picture
                    $pageConnection = $client->connect($application->getKey(), $application->getSecret(), $pageData['access_token']);
                    $pagePicture = $pageConnection->api('/'.$pageData['id'].'/picture', 'GET',array (
                        'redirect' => false,
//                        'height' => '160',
                        'type' => 'large',
//                        'width' => '160',
                    ));
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
            }

            $wizard->set('pagesData', $wizardPages);
        }



        $data = array();
        $form = $this->createFormBuilder($data);
        foreach($locations as $identifier => $location){
            // Has the page already been added as a location?
            $repository = $this->getDoctrine()->getRepository('CampaignChainCoreBundle:Location');
            $pageExists = $repository->findOneBy(array(
                'identifier' => $identifier,
                'locationModule' => $location->getLocationModule(),
            ));

            // Compose the checkbox form field.
            $form->add($identifier, 'checkbox', array(
                'label'     => '<img class="campaignchain-location-image-input-prepend" src="'.$location->getImage().'"> '.$location->getName(),
                'required'  => false,
                'data'     => true,
                'mapped' => false,
                'disabled' => $pageExists,
                'attr' => array(
                    'align_with_widget' => true,
                ),
            ));

            // If a location has already been added before, remove it from this process.
            if($pageExists){
                unset($locations[$identifier]);
            }
        }

        $form = $form->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            // Find out which locations should be added, i.e. which respective checkbox is active.
            foreach($locations as $identifier => $location){
                if(!$form->get($identifier)){
                    unset($locations[$identifier]);
                    $wizard->removeLocation($identifier);
                }
            }

            // If there's at least one location to be added, then have the user configure it.
            if(is_array($locations) && count($locations)){
                $wizard->setLocations($locations);
                return $this->redirect($this->generateUrl('campaignchain_channel_facebook_location_configure', array(
                    'step' => 0,
                )));
            } else {
                $this->get('session')->getFlashBag()->add(
                    'warning',
                    'No new location has been added.'
                );
                return $this->redirect($this->generateUrl('campaignchain_core_channel'));
            }
        }

        return $this->render(
            'CampaignChainCoreBundle:Base:new.html.twig',
            array(
                'page_title' => 'Add Facebook Locations',
                'form' => $form->createView(),
            ));
    }

    public function configureLocationAction(Request $request, $step){
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
            // Keep track of Flash Bag Messages in the Wizard.
            $flashBagMsg = '';
            $wizard->set('flashBagMsg', $flashBagMsg);

            // Is the location a Facebook user or page? The related location module will tell.
            if($location->getLocationModule()->getIdentifier() == 'campaignchain-facebook-user'){
                // The display name of the Facebook user will be the name of the CampaignChain channel.
                $wizard->setName($location->getName());
                // Get the OAuth profile data from the Wizard.
                $profile = $wizard->get('profile');
                // Define the URL of the location
                $location->setUrl($profile->profileURL);

                // Here we handle the specific data of the user stream as provided by Facebook.
                $facebookUser = new FacebookUser();
                $facebookUser->setLocation($location);
                $facebookUser->setScope($this->applicationInfo['parameters']['scope']);
                $facebookUser->setIdentifier($profile->identifier);
                $facebookUser->setUsername($profile->username);
                $facebookUser->setDisplayName($profile->displayName);
                $facebookUser->setFirstName($profile->firstName);
                $facebookUser->setLastName($profile->lastName);
                $facebookUser->setDescription($profile->description);
                $facebookUser->setGender($profile->gender);
                $facebookUser->setLanguage($profile->language);
                $facebookUser->setAge($profile->age);
                $facebookUser->setEmail($profile->email);
                $facebookUser->setEmailVerified($profile->emailVerified);
                $facebookUser->setPhone($profile->phone);
                $facebookUser->setAddress($profile->address);
                $facebookUser->setCountry($profile->country);
                $facebookUser->setRegion($profile->region);
                $facebookUser->setCity($profile->city);
                $facebookUser->setZip($profile->zip);
                $facebookUser->setWebsiteUrl($profile->webSiteURL);
                $facebookUser->setProfileUrl($profile->profileURL);
                $facebookUser->setProfileImageUrl($profile->photoURL);
                $obj = new \ReflectionObject($profile);
                if($obj->hasProperty("coverInfoUrl")){
                    $facebookUser->setCoverInfoUrl($profile->coverInfoURL);
                }

                // Remember the user object in the Wizard.
                $wizard->set($facebookUser->getIdentifier(), $facebookUser);

                $flashBagMsg = $wizard->get('flashBagMsg');
                $flashBagMsg .= '<li>User stream: <a href="'.$profile->profileURL.'">'.$profile->displayName.'</a></li>';
                $wizard->set('flashBagMsg', $flashBagMsg);
            } else {
                $pagesData = $wizard->get('pagesData');
                $pageData = $pagesData[$identifier];

                // Connect to Facebook to retrieve detailed info about this page.
                $oauthApp = $this->get('campaignchain.security.authentication.client.oauth.application');
                $application = $oauthApp->getApplication(self::RESOURCE_OWNER);
                $client = $this->container->get('campaignchain.channel.facebook.rest.client');
                $tokens = $wizard->get('tokens');
                $connection = $client->connect($application->getKey(), $application->getSecret(), $tokens[$wizard->get('facebook_user_id')]->getAccessToken());
                $response = $connection->api('/'.$identifier);
                $pageData = array_merge($pageData, $response);

                // Define the URL of the location
                $location->setUrl($pageData['link']);

                $facebookPage = new FacebookPage();
                $facebookPage->setLocation($location);
                $facebookPage->addUser($wizard->get($wizard->get('facebook_user_id')));
                $facebookPage->setIdentifier($identifier);
                $facebookPage->setName($pageData['name']);
                if(isset($pageData['username'])){
                    $facebookPage->setUsername($pageData['username']);
                }
                if(isset($pageData['description'])){
                    $facebookPage->setDescription($pageData['description']);
                }
                if(isset($pageData['about'])){
                    $facebookPage->setAbout($pageData['about']);
                }
                $facebookPage->setPermissions($pageData['perms']);
                $facebookPage->setCanPost($pageData['can_post']);
                $facebookPage->setCategory($pageData['category']);
                if(isset($pageData['cover'])){
                    $facebookPage->setCoverId($pageData['cover']['cover_id']);
                    $facebookPage->setCoverSource($pageData['cover']['source']);
                    $facebookPage->setCoverOffsetX($pageData['cover']['offset_x']);
                    $facebookPage->setCoverOffsetY($pageData['cover']['offset_y']);
                }
                $facebookPage->setIsPublished($pageData['is_published']);
                $facebookPage->setLink($pageData['link']);
                $facebookPage->setPictureUrl($pageData['picture_url']);

                // Remember the user object in the Wizard.
                $wizard->set($facebookPage->getIdentifier(), $facebookPage);

                $flashBagMsg = $wizard->get('flashBagMsg');
                $flashBagMsg .= '<li>Page: <a href="'.$facebookPage->getLink().'">'.$facebookPage->getName().'</a></li>';
                $wizard->set('flashBagMsg', $flashBagMsg);
            }

            // Add the updated Location to the wizard
            $wizard->addLocation($identifier, $location);

            // Are there still locations to be configured?
            if(isset(array_keys($locations)[$step + 1])){
                // Redirect to this same page to configure the next location.
                return $this->redirect($this->generateUrl('campaignchain_channel_facebook_location_configure', array(
                    'step' => $step + 1,
                )));
            } else {
                // We are done with configuring the locations, so lets end the Wizard and persist the locations.
                // TODO: Wrap into DB transaction.
                $repository = $this->getDoctrine()->getManager();

                foreach($locations as $identifier => $location){
                    // Persist the Facebook user- and page-specific data.
                    $repository->persist($wizard->get($identifier));
                }

                $this->get('session')->getFlashBag()->add(
                    'success',
                    'The following locations are now connected:'.
                    '<ul>'.$wizard->get('flashBagMsg').'</ul>'
                );

                $tokens = $wizard->get('tokens');

                $channel = $wizard->persist();

                // Store all access tokens per location in the OAuth Client bundle's Token entity.
                $tokenService = $this->get('campaignchain.security.authentication.client.oauth.token');
                foreach($tokens as $identifier => $token){
                    $token = $repository->merge($token);
                    $token->setLocation($locations[$identifier]);
                    $tokenService->setToken($token);
                }

                $wizard->end();
                $repository->flush();

                return $this->redirect($this->generateUrl('campaignchain_core_channel'));
            }
        }

        return $this->render(
            'CampaignChainLocationFacebookBundle::new.html.twig',
            array(
                'page_title' => 'Configure Facebook Location',
                'form' => $form->createView(),
                'location' => $location,
            ));
    }
}