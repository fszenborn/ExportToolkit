<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Elements\Bundle\ExportToolkitBundle\ExportService\Interpreter;

use Elements\Bundle\ExportToolkitBundle\ExportService\IInterpreter;
use Pimcore\Model\Element\ElementInterface;

class ElementToPath implements IInterpreter
{
    public static function interpret($value, $config = null)
    {
        if ($value instanceof ElementInterface) {
            return (string) $value;
        }

        return '';
    }
}
