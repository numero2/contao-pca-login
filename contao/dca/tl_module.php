<?php

/**
 * PCA login bundle for Contao Open Source CMS
 *
 * @author    Benny Born <benny.born@numero2.de>
 * @license   LGPL
 * @copyright Copyright (c) 2023, numero2 - Agentur für digitales Marketing GbR
 */


$GLOBALS['TL_DCA']['tl_module']['palettes']['loginPCA'] = '{title_legend},name,headline,type;{config_legend},pca_client_id,pca_client_secret,pca_api_endpoint,pca_password_field;{account_legend},reg_groups;{redirect_legend},jumpTo,redirectBack;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';


$GLOBALS['TL_DCA']['tl_module']['fields']['pca_client_id'] = [
    'exclude'     => true
,   'inputType'   => 'text'
,   'eval'        => ['mandatory'=>true, 'maxlength'=>255, 'tl_class'=>'w50']
,   'sql'         => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['pca_client_secret'] = [
    'exclude'     => true
,   'inputType'   => 'text'
,   'eval'        => ['mandatory'=>true, 'maxlength'=>255, 'hideInput'=>true, 'tl_class'=>'w50']
,   'sql'         => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['pca_api_endpoint'] = [
    'exclude'     => true
,   'inputType'   => 'text'
,   'eval'        => ['mandatory'=>true, 'maxlength'=>255, 'placeholder'=>'example.com', 'tl_class'=>'w50']
,   'sql'         => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['pca_password_field'] = [
    'exclude'       => true
,   'inputType'     => 'select'
,   'reference'     => &$GLOBALS['TL_LANG']['tl_module']['pcaPasswordOptions']
,   'eval'          => [ 'mandatory'=>true, 'includeBlankOption'=>true, 'tl_class'=>'w50' ]
,   'sql'           => "varchar(64) COLLATE ascii_bin NOT NULL default ''"
];