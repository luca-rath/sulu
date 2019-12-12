<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\RouteBundle\Document\Subscriber;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Bundle\DocumentManagerBundle\Bridge\PropertyEncoder;
use Sulu\Bundle\RouteBundle\Entity\RouteRepositoryInterface;
use Sulu\Bundle\RouteBundle\Exception\RouteIsNotUniqueException;
use Sulu\Bundle\RouteBundle\Generator\ChainRouteGeneratorInterface;
use Sulu\Bundle\RouteBundle\Manager\ConflictResolverInterface;
use Sulu\Bundle\RouteBundle\Manager\RouteManagerInterface;
use Sulu\Bundle\RouteBundle\Model\RouteInterface;
use Sulu\Component\Content\Document\LocalizationState;
use Sulu\Component\Content\Exception\ResourceLocatorAlreadyExistsException;
use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\Event\AbstractMappingEvent;
use Sulu\Component\DocumentManager\Event\CopyEvent;
use Sulu\Component\DocumentManager\Event\PublishEvent;
use Sulu\Component\DocumentManager\Event\RemoveEvent;
use Sulu\Component\DocumentManager\Events;
use Sulu\Component\Route\Document\Behavior\RoutableBehavior;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles document-manager events to create/update/remove routes.
 */
class RoutableSubscriber implements EventSubscriberInterface
{
    const ROUTE_FIELD = 'routePath';

    const ROUTES_PROPERTY = 'suluRoutes';

    const TAG_NAME = 'sulu_article.article_route';

    /**
     * @var ChainRouteGeneratorInterface
     */
    private $chainRouteGenerator;

    /**
     * @var RouteManagerInterface
     */
    private $routeManager;

    /**
     * @var RouteRepositoryInterface
     */
    private $routeRepository;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var DocumentManagerInterface
     */
    private $documentManager;

    /**
     * @var DocumentInspector
     */
    private $documentInspector;

    /**
     * @var PropertyEncoder
     */
    private $propertyEncoder;

    /**
     * @var StructureMetadataFactoryInterface
     */
    private $metadataFactory;

    /**
     * @var ConflictResolverInterface
     */
    private $conflictResolver;

    /**
     * @param ChainRouteGeneratorInterface $chainRouteGenerator
     * @param RouteManagerInterface $routeManager
     * @param RouteRepositoryInterface $routeRepository
     * @param EntityManagerInterface $entityManager
     * @param DocumentManagerInterface $documentManager
     * @param DocumentInspector $documentInspector
     * @param PropertyEncoder $propertyEncoder
     * @param StructureMetadataFactoryInterface $metadataFactory
     * @param ConflictResolverInterface $conflictResolver
     */
    public function __construct(
        ChainRouteGeneratorInterface $chainRouteGenerator,
        RouteManagerInterface $routeManager,
        RouteRepositoryInterface $routeRepository,
        EntityManagerInterface $entityManager,
        DocumentManagerInterface $documentManager,
        DocumentInspector $documentInspector,
        PropertyEncoder $propertyEncoder,
        StructureMetadataFactoryInterface $metadataFactory,
        ConflictResolverInterface $conflictResolver
    ) {
        $this->chainRouteGenerator = $chainRouteGenerator;
        $this->routeManager = $routeManager;
        $this->routeRepository = $routeRepository;
        $this->entityManager = $entityManager;
        $this->documentManager = $documentManager;
        $this->documentInspector = $documentInspector;
        $this->propertyEncoder = $propertyEncoder;
        $this->metadataFactory = $metadataFactory;
        $this->conflictResolver = $conflictResolver;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            Events::HYDRATE => ['handleHydrate'],
            Events::PERSIST => [
                // low priority because all other subscriber should be finished
                ['handlePersist', -2000],
            ],
            Events::REMOVE => [
                // high priority to ensure nodes are not deleted until we iterate over children
                ['handleRemove', 1024],
            ],
            Events::PUBLISH => ['handlePublish', -2000],
            Events::COPY => ['handleCopy', -2000],
        ];
    }

    /**
     * Load route.
     *
     * @param AbstractMappingEvent $event
     */
    public function handleHydrate(AbstractMappingEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutableBehavior) {
            return;
        }

        $locale = $document->getLocale();
        if (LocalizationState::SHADOW === $this->documentInspector->getLocalizationState($document)) {
            $locale = $document->getOriginalLocale();
        }

        $propertyName = $this->getRoutePathPropertyName($document, $locale);
        $routePath = $event->getNode()->getPropertyValueWithDefault($propertyName, null);
        $document->setRoutePath($routePath);

        $route = $this->routeRepository->findByEntity($document->getClass(), $document->getUuid(), $locale);
        if ($route) {
            $document->setRoute($route);
        }
    }

    /**
     * Generate route and save route-path.
     *
     * @param AbstractMappingEvent $event
     */
    public function handlePersist(AbstractMappingEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutableBehavior) {
            return;
        }

        $document->setUuid($event->getNode()->getIdentifier());

        $propertyName = $this->getRoutePathPropertyName($document, $event->getLocale());
        $routePath = $event->getNode()->getPropertyValueWithDefault($propertyName, null);

        $route = $this->conflictResolver->resolve($this->chainRouteGenerator->generate($document, $routePath));
        $document->setRoutePath($route->getPath());

        $event->getNode()->setProperty($propertyName, $route->getPath());
    }

    /**
     * Removes route.
     *
     * @param RemoveEvent $event
     */
    public function handleRemove(RemoveEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutableBehavior) {
            return;
        }

        $locales = $this->documentInspector->getLocales($document);
        foreach ($locales as $locale) {
            $localizedDocument = $this->documentManager->find($document->getUuid(), $locale);

            $route = $this->routeRepository->findByEntity(
                $localizedDocument->getClass(),
                $localizedDocument->getUuid(),
                $locale
            );
            if (!$route) {
                continue;
            }

            $this->entityManager->remove($route);
        }

        $this->entityManager->flush();
    }

    /**
     * Handle publish event and generate route.
     *
     * @param PublishEvent $event
     *
     * @throws ResourceLocatorAlreadyExistsException
     */
    public function handlePublish(PublishEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutableBehavior) {
            return;
        }

        $node = $this->documentInspector->getNode($document);

        try {
            $route = $this->createOrUpdateRoute($document, $event->getLocale());
        } catch (RouteIsNotUniqueException $exception) {
            throw new ResourceLocatorAlreadyExistsException($exception->getRoute()->getPath(), $document->getPath());
        }

        $parentUuid = $node->getPropertyValueWithDefault(
            $this->getRoutePathPropertyName($document, $event->getLocale()) . '-page',
            null
        );

        $route->setParentUuid($parentUuid);

        $document->setRoutePath($route->getPath());
        $this->entityManager->persist($route);

        $node->setProperty(
            $this->getRoutePathPropertyName($document, $event->getLocale()),
            $route->getPath()
        );

        $this->entityManager->flush();
    }

    /**
     * Update routes for copied article.
     *
     * @param CopyEvent $event
     */
    public function handleCopy(CopyEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutableBehavior) {
            return;
        }

        $locales = $this->documentInspector->getLocales($document);
        foreach ($locales as $locale) {
            $localizedDocument = $this->documentManager->find($event->getCopiedPath(), $locale);

            if (!$localizedDocument instanceof RoutableBehavior) {
                return;
            }

            $route = $this->conflictResolver->resolve($this->chainRouteGenerator->generate($localizedDocument));
            $localizedDocument->setRoutePath($route->getPath());

            $node = $this->documentInspector->getNode($localizedDocument);
            $node->setProperty(
                $this->getRoutePathPropertyName($localizedDocument, $locale),
                $route->getPath()
            );

            $propertyName = $this->getRoutePathPropertyName($localizedDocument, $locale);
            $node = $this->documentInspector->getNode($localizedDocument);
            $node->setProperty($propertyName, $route->getPath());
        }
    }

    /**
     * Create or update for given document.
     *
     * @param RoutableBehavior $document
     * @param string $locale
     *
     * @return RouteInterface
     */
    private function createOrUpdateRoute(RoutableBehavior $document, $locale)
    {
        $route = $document->getRoute();

        if (!$route) {
            $route = $this->routeRepository->findByEntity($document->getClass(), $document->getUuid(), $locale);
        }

        if ($route) {
            $document->setRoute($route);

            return $this->routeManager->update($document, $document->getRoutePath(), false);
        }

        return $this->routeManager->create($document, $document->getRoutePath(), false);
    }

    /**
     * Returns encoded "routePath" property-name.
     *
     * @param RoutableBehavior $document
     * @param string $locale
     *
     * @return string
     */
    private function getRoutePathPropertyName(RoutableBehavior $document, $locale)
    {
        $metadata = $this->documentInspector->getStructureMetadata($document);

        if ($metadata->hasTag(self::TAG_NAME)) {
            return $this->getPropertyName($locale, $metadata->getPropertyByTagName(self::TAG_NAME)->getName());
        }

        return $this->getPropertyName($locale, self::ROUTE_FIELD);
    }

    /**
     * Returns encoded property-name.
     *
     * @param string $locale
     * @param string $field
     *
     * @return string
     */
    private function getPropertyName($locale, $field)
    {
        return $this->propertyEncoder->localizedSystemName($field, $locale);
    }
}
