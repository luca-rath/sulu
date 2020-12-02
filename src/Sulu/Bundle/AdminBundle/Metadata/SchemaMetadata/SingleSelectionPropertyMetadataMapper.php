<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata;

use Sulu\Component\Content\Metadata\PropertyMetadata as ContentPropertyMetadata;

class SingleSelectionPropertyMetadataMapper implements PropertyMetadataMapperInterface
{
    public function mapPropertyMetadata(ContentPropertyMetadata $propertyMetadata): PropertyMetadata
    {
        $mandatory = $propertyMetadata->isRequired();

        $anyOfs = [
            new StringMetadata(),
            new NumberMetadata(),
        ];

        if (!$mandatory) {
            $anyOfs[] = new NullMetadata();
        }

        return new PropertyMetadata(
            $propertyMetadata->getName(),
            $mandatory,
            new AnyOfsMetadata($anyOfs)
        );
    }
}
