<?php defined('SYSPATH') or die('No direct script access.');

defined('RESTMANCARD_VERSION') OR define('RESTMANCARD_VERSION', '1.7.0');

Kohana::$config->load('menu')
    ->set('restmancard', array(
        'title' => 'Организации и сотрудники (REST)',
        'url' => 'restmancard/index',
        'icon' => 'fa-plug',
        'order' => 26,
        'disabled' => false,
    ));
