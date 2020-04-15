<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\AdminBundle\Admin\View;

interface ResourceTabViewBuilderInterface extends TabViewBuilderInterface
{
    public function setResourceKey(string $resourceKey): self;

    /**
     * @param string[] $locales
     */
    public function addLocales(array $locales): self;

    public function setBackView(string $backView): self;

    /**
     * @param string[] $routerAttributesToBackView
     */
    public function addRouterAttributesToBackView(array $routerAttributesToBackView): self;

    public function setTitleProperty(string $titleProperty): self;
}
