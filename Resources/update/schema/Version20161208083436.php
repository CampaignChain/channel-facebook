<?php

namespace Application\Migrations;

use CampaignChain\Location\FacebookBundle\Entity\Page;
use CampaignChain\Security\Authentication\Client\OAuthBundle\Entity\Token;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\ApplicationService;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\TokenService;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Version20161208083436 extends AbstractMigration implements ContainerAwareInterface
{

    /**
     * @var ContainerInterface
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DELETE FROM `campaignchain_security_authentication_client_oauth_token` WHERE location_id IS NULL');
    }

    public function postUp(Schema $schema)
    {
        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->container->get('doctrine');
        $em = $doctrine->getManager();

        // Get the Facebook Pages that don't have an OAuth token yet.
        $pages =
            $em->createQueryBuilder()
                ->select('lb')
                ->from('CampaignChain\Location\FacebookBundle\Entity\Page', 'lb')
                ->where("NOT EXISTS (SELECT t.id FROM CampaignChain\Security\Authentication\Client\OAuthBundle\Entity\Token t WHERE t.location = lb.id)"
                )
                ->getQuery()
                ->getResult();

        if ($pages !== null) {
            // Retrieve OAuth tokens for pages.

            /** @var ApplicationService $oauthAppService */
            $oauthAppService = $this->container->get('campaignchain.security.authentication.client.oauth.application');
            $oauthApp = $oauthAppService->getApplication('Facebook');

            /** @var Page $page */
            foreach ($pages as $page) {
                /** @var TokenService $tokenService */
                $tokenService = $this->container->get('campaignchain.security.authentication.client.oauth.token');
                $userToken = $tokenService->getToken($page->getUsers()[0]->getLocation());

                $client = $this->container->get('campaignchain.channel.facebook.rest.client');
                $connection = $client->connect($userToken->getAccessToken());

                if ($connection) {
                    $response = $connection->api('/me/accounts');
                    $pagesData = $response['data'];

                    if (is_array($pagesData) && count($pagesData)) {
                        // Restructure response data
                        foreach ($pagesData as $pageData) {
                            $tokens[$pageData['id']] = $pageData['access_token'];
                        }

                        // Apply retrieved tokens to Pages where they are missing.
                        $token = new Token();
                        $token->setApplication($oauthApp);
                        $token->setLocation($page->getLocation());
                        $token->setAccessToken($tokens[$page->getIdentifier()]);

                        $this->write(
                            'Inserted token for Facebook page "'.$page->getLocation()->getName().'" ('.$page->getIdentifier().')'
                            );
                        
                        $em->persist($token);
                    }
                }
            }

            $em->flush();
        }
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
    }
}
