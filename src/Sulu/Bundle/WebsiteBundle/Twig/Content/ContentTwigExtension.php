<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\WebsiteBundle\Twig\Content;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\WebsiteBundle\Resolver\StructureResolverInterface;
use Sulu\Bundle\WebsiteBundle\Twig\Exception\ParentNotFoundException;
use Sulu\Component\Content\Compat\StructureInterface;
use Sulu\Component\Content\Mapper\ContentMapperInterface;
use Sulu\Component\DocumentManager\Exception\DocumentNotFoundException;
use Sulu\Component\PHPCR\SessionManager\SessionManagerInterface;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides Interface to load content.
 */
class ContentTwigExtension extends AbstractExtension implements ContentTwigExtensionInterface
{
    /**
     * @var ContentMapperInterface
     */
    private $contentMapper;

    /**
     * @var StructureResolverInterface
     */
    private $structureResolver;

    /**
     * @var RequestAnalyzerInterface
     */
    private $requestAnalyzer;

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RequestStack|null
     */
    private $requestStack;

    /**
     * Constructor.
     */
    public function __construct(
        ContentMapperInterface $contentMapper,
        StructureResolverInterface $structureResolver,
        SessionManagerInterface $sessionManager,
        RequestAnalyzerInterface $requestAnalyzer,
        LoggerInterface $logger = null,
        RequestStack $requestStack = null
    ) {
        $this->contentMapper = $contentMapper;
        $this->structureResolver = $structureResolver;
        $this->sessionManager = $sessionManager;
        $this->requestAnalyzer = $requestAnalyzer;
        $this->logger = $logger ?: new NullLogger();
        $this->requestStack = $requestStack;

        if (null === $this->requestStack) {
            @\trigger_error(
                'Instantiating the "ContentTwigExtension" without the "$requestStack" parameter is deprecated',
                \E_USER_DEPRECATED
            );
        }
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('sulu_content_load', [$this, 'load']),
            new TwigFunction('sulu_content_load_parent', [$this, 'loadParent']),
        ];
    }

    public function load($uuid, array $properties = null)
    {
        if (!$uuid) {
            return;
        }

        try {
            $contentStructure = $this->contentMapper->load(
                $uuid,
                $this->requestAnalyzer->getWebspace()->getKey(),
                $this->requestAnalyzer->getCurrentLocalization()->getLocale()
            );
        } catch (DocumentNotFoundException $e) {
            $this->logger->error((string) $e);

            return;
        }

        if (null === $properties) {
            @\trigger_error(
                'Calling the "sulu_content_load" function without a properties parameter is deprecated and has a negative impact on performance.',
                \E_USER_DEPRECATED
            );

            return $this->resolveStructure($contentStructure);
        }

        return $this->resolveProperties($contentStructure, $properties);
    }

    public function loadParent($uuid, array $properties = null)
    {
        $session = $this->sessionManager->getSession();
        $contentsNode = $this->sessionManager->getContentNode($this->requestAnalyzer->getWebspace()->getKey());
        $node = $session->getNodeByIdentifier($uuid);

        if ($node->getDepth() <= $contentsNode->getDepth()) {
            throw new ParentNotFoundException($uuid);
        }

        return $this->load($node->getParent()->getIdentifier(), $properties);
    }

    private function resolveStructure(
        StructureInterface $structure,
        bool $loadExcerpt = true,
        array $includedProperties = null
    ): array {
        if (null === $this->requestStack) {
            return $this->structureResolver->resolve($structure, $loadExcerpt, $includedProperties);
        }

        $currentRequest = $this->requestStack->getCurrentRequest();

        // This sets query parameters, request parameters and files to an empty array
        $subRequest = $currentRequest->duplicate([], [], null, null, []);
        $this->requestStack->push($subRequest);

        try {
            return $this->structureResolver->resolve($structure, $loadExcerpt, $includedProperties);
        } finally {
            $this->requestStack->pop();
        }
    }

    private function resolveProperties(StructureInterface $contentStructure, array $properties): array
    {
        $contentProperties = [];
        $extensionProperties = [];

        foreach ($properties as $targetProperty => $sourceProperty) {
            if (!\is_string($targetProperty)) {
                $targetProperty = $sourceProperty;
            }

            if (!\strpos($sourceProperty, '.')) {
                $contentProperties[$targetProperty] = $sourceProperty;
            } else {
                $extensionProperties[$targetProperty] = $sourceProperty;
            }
        }

        $resolvedStructure = $this->resolveStructure(
            $contentStructure,
            !empty($extensionProperties),
            \array_values($contentProperties)
        );

        foreach ($contentProperties as $targetProperty => $sourceProperty) {
            if (isset($resolvedStructure['content'][$sourceProperty]) && $sourceProperty !== $targetProperty) {
                $resolvedStructure['content'][$targetProperty] = $resolvedStructure['content'][$sourceProperty];
                $resolvedStructure['view'][$targetProperty] = $resolvedStructure['view'][$sourceProperty] ?? [];

                unset($resolvedStructure['content'][$sourceProperty]);
                unset($resolvedStructure['view'][$sourceProperty]);
            }
        }

        foreach ($extensionProperties as $targetProperty => $sourceProperty) {
            [$extensionName, $propertyName] = \explode('.', $sourceProperty);
            $propertyValue = $resolvedStructure['extension'][$extensionName][$propertyName];

            $resolvedStructure['content'][$targetProperty] = $propertyValue;
            $resolvedStructure['view'][$targetProperty] = [];
        }
        unset($resolvedStructure['extension']);

        return $resolvedStructure;
    }
}
