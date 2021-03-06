<?php
namespace Bolt\EventListener;

use Bolt\Config;
use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;
use Bolt\Storage\Entity;
use Bolt\Storage\EntityManager;
use PasswordLib\PasswordLib;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class StorageEventListener implements EventSubscriberInterface
{
    /** @var \Bolt\Storage\EntityManager */
    protected $em;
    /** @var \Bolt\Config $config */
    protected $config;
    /** @var string */
    protected $hashStrength;

    /**
     * Constructor.
     *
     * @param EntityManager $em
     * @param Config        $config
     */
    public function __construct(EntityManager $em, Config $config, $hashStrength)
    {
        $this->em = $em;
        $this->config = $config;
        $this->hashStrength = $hashStrength;
    }

    /**
     * Pre-save storage event.
     *
     * @param StorageEvent $event
     */
    public function onPreSave(StorageEvent $event)
    {
        /** @var Entity\Entity $entityRecord */
        $entityRecord = $event->getContent();
        if ($entityRecord instanceof Entity\Users) {
            $this->passwordHash($entityRecord);
        }
    }

    /**
     * Kernel request listener callback.
     *
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $contenttypes = $this->config->get('contenttypes', []);

        foreach ($contenttypes as $contenttype) {
            $contenttype = $this->em->getContentType($contenttype['slug']);

            // Check if we need to 'publish' any 'timed' records, or 'depublish' any expired records.
            $this->em->publishTimedRecords($contenttype);
            $this->em->depublishExpiredRecords($contenttype);
        }
    }

    /**
     * Hash user passwords on save.
     *
     * @param Entity\Users $usersEntity
     */
    protected function passwordHash(Entity\Users $usersEntity)
    {
        if ($usersEntity->getShadowSave()) {
            return;
        } elseif ($usersEntity->getPassword() && $usersEntity->getPassword() !== '**dontchange**') {
            $crypt = new PasswordLib();
            $usersEntity->setPassword($crypt->createPasswordHash($usersEntity->getPassword(), '$2a$', ['cost' => $this->hashStrength]));
        } else {
            unset($usersEntity->password);
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST   => ['onKernelRequest', 31],
            StorageEvents::PRE_SAVE => 'onPreSave',
        ];
    }
}
