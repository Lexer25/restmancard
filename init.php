<?php defined('SYSPATH') or die('No direct script access.');

defined('RESTMANCARD_VERSION') OR define('RESTMANCARD_VERSION', '2.1.0');

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

// Отдельная форма только для сотрудников (пиплов): ФИО, организация
// (родительская ссылка) и набор категорий доступа, без управления
// организациями.
Kohana::$config->load('menu')
    ->set('restmancard_people', array(
        'title' => 'Сотрудники',
        'url' => 'restmancard/people',
        'icon' => 'fa-users',
        'order' => 28,
        'disabled' => false,
    ));
