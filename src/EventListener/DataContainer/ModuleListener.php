<?php

/**
 * PCA login bundle for Contao Open Source CMS
 *
 * @author    Benny Born <benny.born@numero2.de>
 * @license   LGPL
 * @copyright Copyright (c) 2023, numero2 - Agentur für digitales Marketing GbR
 */


namespace numero2\PCALoginBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\CoreBundle\ServiceAnnotation\Callback;


class ModuleListener {


    /**
     * Get a list of available PCA fields used for password validation
     *
     * @param Contao\DataContainer|Contao\DC_Table $dc
     *
     * @return array
     *
     * @Callback(table="tl_module", target="fields.pca_password_field.options")
     */
    public function getPasswordFields( $dc ): array {
        
        return [
            'pin'
        ,   'birthDate'
        ];
    }
}