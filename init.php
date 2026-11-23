<?php defined('SYSPATH') or die('No direct script access.');

defined('RESTMANCARD_VERSION') OR define('RESTMANCARD_VERSION', '2.0.0');

Kohana::$config->load('menu')
    ->set('restmancard', array(
        'title' => 'Организации и сотрудники (REST)',
        'url' => 'restmancard/index',
        'icon' => 'fa-plug',
        'order' => 26,
        'disabled' => false,
    ));

// Отдельная форма только для организаций: название, родительская организация
// и набор категорий доступа, без сотрудников.
Kohana::$config->load('menu')
    ->set('restmancard_orgs', array(
        'title' => 'Организации',
        'url' => 'restmancard/organizations',
        'icon' => 'fa-building',
        'order' => 27,
        'disabled' => false,
    ));
