<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\DocumentManager\Subscriber\Phpcr;

use Sulu\Component\DocumentManager\DocumentRegistry;
use Sulu\Component\DocumentManager\Event\RemoveEvent;
use Sulu\Component\DocumentManager\Events;
use Sulu\Component\DocumentManager\NodeManager;
use Sulu\Component\DocumentManager\Subscriber\EventSubscriberInterface;

/**
 * Remove subscriber.
 */
class RemoveSubscriber implements EventSubscriberInterface
{
    /**
     * @var DocumentRegistry
     */
    private $documentRegistry;

    /**
     * @var NodeManager
     */
    private $nodeManager;

    public function __construct(
        DocumentRegistry $documentRegistry,
        NodeManager $nodeManager
    ) {
        $this->documentRegistry = $documentRegistry;
        $this->nodeManager = $nodeManager;
    }

    public static function getSubscribedEvents()
    {
        return [
            Events::REMOVE => ['handleRemove', 500],
        ];
    }

    /**
     * Remove the given documents node from PHPCR session and optionally
     * remove any references to the node.
     */
    public function handleRemove(RemoveEvent $event)
    {
        $document = $event->getDocument();
        $node = $this->documentRegistry->getNodeForDocument($document);

        $node->remove();
    }
}
