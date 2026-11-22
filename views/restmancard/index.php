<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Главная страница модуля restmancard.
 *
 * Трёхпанельная форма, как mancard/index:
 *   Организации | Свойства | Категории доступа.
 * Данные берутся из Artonit REST API v2.
 *
 * @var array  $tree
 * @var string $error
 * @var array  $warnings
 * @var array  $stats
 * @var string $data_json
 * @var string $api_url
 * @var string $generated_at
 * @var string $refresh_url
 * @var array  $parent_options
 * @var array  $org_options
 * @var string $add_url
 * @var string $delete_url
 * @var string $add_person_url
 * @var string $delete_person_url
 * @var string $focus_divcode
 * @var string $focus_person
 * @var string $divcode_prefix
 */
?>
<?php echo HTML::style('static/css/restmancard.css?v=1.7.0'); ?>

<div class="panel panel-primary rc-root">
    <div class="panel-heading">
        <h3 class="panel-title">
            <span class="glyphicon glyphicon-th-list"></span>
            <?php echo __('Организации и сотрудники'); ?>
            <span class="label label-info rc-api-version">REST API v2</span>

            <span class="pull-right rc-header-actions">
                <span class="rc-api-url" title="<?php echo __('Источник данных'); ?>">
                    <span class="glyphicon glyphicon-cloud"></span>
                    <?php echo HTML::chars($api_url); ?>
                </span>
                <a class="btn btn-xs btn-default" href="<?php echo HTML::chars($refresh_url); ?>" title="<?php echo __('Сбросить кеш и перечитать данные'); ?>">
                    <span class="glyphicon glyphicon-refresh"></span> <?php echo __('Обновить'); ?>
                </a>
            </span>
        </h3>
    </div>

    <div class="panel-body">
        <?php if ( ! empty($error)): ?>
            <div class="alert alert-danger">
                <strong><?php echo __('Ошибка обращения к REST API.'); ?></strong>
                <?php echo HTML::chars($error); ?>
                <div class="small rc-error-hint">
                    <?php echo __('Проверьте, что сервис запущен:'); ?>
                    <code><?php echo HTML::chars($api_url); ?>/swagger/v2/swagger.json</code>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($warnings as $warning): ?>
            <div class="alert alert-warning">
                <span class="glyphicon glyphicon-warning-sign"></span>
                <?php echo HTML::chars($warning); ?>
            </div>
        <?php endforeach; ?>

        <div class="row rc-main">
            <!-- ===== Панель 1: организации и сотрудники ===== -->
            <div class="col-md-4">
                <div class="panel panel-default org-panel">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <span class="glyphicon glyphicon-home"></span>
                            <?php echo __('Организации'); ?>
                            <span class="pull-right">
                                <button class="btn btn-xs btn-success rc-add-org-btn" id="rc-add-org" title="<?php echo __('Добавить организацию'); ?>">
                                    <span class="glyphicon glyphicon-plus"></span> <?php echo __('Добавить'); ?>
                                </button>
                                <button class="btn btn-xs btn-success rc-add-person-btn" id="rc-add-person"
                                        title="<?php echo __('Добавить сотрудника в выбранную организацию'); ?>"
                                        <?php echo empty($org_options) ? 'disabled' : ''; ?>>
                                    <span class="glyphicon glyphicon-user"></span>
                                </button>
                                <button class="btn btn-xs btn-danger rc-delete-org-btn" id="rc-delete-item"
                                        title="<?php echo __('Удалить выбранный элемент'); ?>" disabled>
                                    <span class="glyphicon glyphicon-trash"></span> <?php echo __('Удалить'); ?>
                                </button>
                                <button class="btn btn-xs btn-info" id="rc-expand-all" title="<?php echo __('Развернуть всё'); ?>">
                                    <span class="glyphicon glyphicon-plus-sign"></span>
                                </button>
                                <button class="btn btn-xs btn-warning" id="rc-collapse-all" title="<?php echo __('Свернуть всё'); ?>">
                                    <span class="glyphicon glyphicon-minus-sign"></span>
                                </button>
                            </span>
                        </h4>
                    </div>

                    <div class="panel-body rc-org-body">
                        <div class="input-group input-group-sm rc-search">
                            <span class="input-group-addon"><span class="glyphicon glyphicon-search"></span></span>
                            <input type="text" class="form-control" id="rc-search"
                                   placeholder="<?php echo __('Поиск организаций и сотрудников...'); ?>"
                                   autocomplete="off">
                        </div>

                        <div class="rc-search-info" id="rc-search-info" style="display: none;">
                            <span class="badge" id="rc-search-count">0</span>
                            <?php echo __('найдено'); ?>
                            <button type="button" class="btn btn-xs btn-link" id="rc-search-clear"><?php echo __('сбросить'); ?></button>
                        </div>

                        <div class="move-list" id="rc-tree-container">
                            <?php if (empty($tree)): ?>
                                <div class="text-center text-muted rc-empty">
                                    <span class="glyphicon glyphicon-folder-open" style="font-size: 2.5em;"></span>
                                    <p><?php echo __('Организации не найдены'); ?></p>
                                </div>
                            <?php else: ?>
                                <ul class="file-tree" id="rc-tree">
                                    <?php foreach ($tree as $node): ?>
                                        <?php echo View::factory('restmancard/tree_node', array(
                                            'node'  => $node,
                                            'level' => 0,
                                        )); ?>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div class="org-footer">
                            <div class="row">
                                <div class="col-xs-7">
                                    <span class="id-info">
                                        <span class="glyphicon glyphicon-hdd"></span>
                                        <?php echo __('Всего организаций'); ?>:
                                        <strong><?php echo (int) $stats['organizations']; ?></strong>
                                    </span>
                                </div>
                                <div class="col-xs-5 text-right">
                                    <span class="id-info">
                                        <span class="glyphicon glyphicon-user"></span>
                                        <?php echo __('Сотрудников'); ?>:
                                        <strong><?php echo (int) $stats['people']; ?></strong>
                                    </span>
                                </div>
                            </div>

                            <div class="row rc-footer-row">
                                <div class="col-xs-12">
                                    <span class="id-info" id="rc-hover-info">
                                        <span class="glyphicon glyphicon-info-sign"></span>
                                        <span class="text-muted"><?php echo __('Наведите на элемент'); ?></span>
                                    </span>
                                </div>
                            </div>

                            <div class="row rc-footer-row">
                                <div class="col-xs-12">
                                    <span class="id-info" id="rc-selected-info">
                                        <span class="glyphicon glyphicon-ok-sign"></span>
                                        <span class="text-muted"><?php echo __('Выберите элемент'); ?></span>
                                    </span>
                                </div>
                            </div>

                            <?php if ( ! empty($stats['people_truncated']) OR ! empty($stats['identifiers_truncated'])): ?>
                                <div class="row rc-footer-row">
                                    <div class="col-xs-12">
                                        <span class="text-warning rc-limit-note">
                                            <span class="glyphicon glyphicon-warning-sign"></span>
                                            <?php if ( ! empty($stats['people_truncated'])): ?>
                                                <?php echo __('Список сотрудников ограничен настройкой max_pages.'); ?>
                                            <?php endif; ?>
                                            <?php if ( ! empty($stats['identifiers_truncated'])): ?>
                                                <?php echo __('Список идентификаторов ограничен настройкой max_pages.'); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($stats['people_map_enabled'])): ?>
                                <div class="row rc-footer-row">
                                    <div class="col-xs-12">
                                        <span class="id-info rc-limit-note">
                                            <span class="glyphicon glyphicon-info-sign"></span>
                                            <?php echo __('Привязка сотрудников к организациям отключена (people.org_map). Все сотрудники показаны в организации'); ?>
                                            <code><?php echo HTML::chars($stats['people_root']); ?></code>.
                                        </span>
                                    </div>
                                </div>
                            <?php elseif ((int) $stats['people_unmapped'] > 0): ?>
                                <div class="row rc-footer-row">
                                    <div class="col-xs-12">
                                        <span class="text-warning rc-limit-note">
                                            <span class="glyphicon glyphicon-warning-sign"></span>
                                            <?php echo __('Без привязки к организации'); ?>:
                                            <strong><?php echo (int) $stats['people_unmapped']; ?></strong>
                                            <?php echo __('— показаны в организации'); ?>
                                            <code><?php echo HTML::chars($stats['people_root']); ?></code>.
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Панель 2: свойства выбранного элемента ===== -->
            <div class="col-md-4">
                <div class="panel panel-info props-panel">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <span class="glyphicon glyphicon-list-alt"></span>
                            <?php echo __('Свойства'); ?>
                            <span class="label label-info" id="rc-entity-badge" style="display: none; font-size: 10px;"></span>
                        </h4>
                    </div>

                    <div class="panel-body" id="rc-properties">
                        <div class="text-center text-muted rc-placeholder">
                            <span class="glyphicon glyphicon-info-sign" style="font-size: 3em;"></span>
                            <p><?php echo __('Выберите организацию или сотрудника в дереве слева'); ?></p>
                        </div>
                    </div>

                    <div class="panel-footer">
                        <small class="text-muted">
                            <span class="glyphicon glyphicon-time"></span>
                            <?php echo __('Загружено'); ?>: <?php echo HTML::chars($generated_at); ?>
                            &nbsp;·&nbsp;
                            <?php echo __('Идентификаторов'); ?>: <?php echo (int) $stats['identifiers_loaded']; ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- ===== Панель 3: категории доступа ===== -->
            <div class="col-md-4">
                <div class="panel panel-warning access-panel">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <span class="glyphicon glyphicon-lock"></span>
                            <?php echo __('Категории доступа'); ?>
                            <span class="pull-right">
                                <span class="badge" id="rc-access-count">0</span>
                            </span>
                        </h4>
                    </div>

                    <div class="panel-body" id="rc-access">
                        <div class="text-center text-muted rc-placeholder">
                            <span class="glyphicon glyphicon-lock" style="font-size: 3em;"></span>
                            <p><?php echo __('Раздел «Категории доступа» нарисован, но не заполняется.'); ?></p>
                            <p class="small">
                                <?php echo __('В этой версии модуля категории доступа не загружаются из REST API.'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="panel-footer">
                        <small class="text-muted">
                            <span class="glyphicon glyphicon-info-sign"></span>
                            <?php echo __('Требование контракта: раздел отображается без данных.'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== Модальное окно: добавление организации ===== -->
<div class="modal fade" id="rc-add-org-modal" tabindex="-1" role="dialog" aria-labelledby="rc-add-org-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="rc-add-org-form" autocomplete="off">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title" id="rc-add-org-title">
                        <span class="glyphicon glyphicon-plus-sign"></span>
                        <?php echo __('Новая организация'); ?>
                    </h4>
                </div>

                <div class="modal-body">
                    <div id="rc-add-org-alert" class="alert" style="display: none;">
                        <span class="glyphicon glyphicon-warning-sign"></span>
                        <span id="rc-add-org-alert-text"></span>
                    </div>

                    <div class="form-group">
                        <label for="rc-add-org-name">
                            <?php echo __('Название организации'); ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="rc-add-org-name" name="name"
                               maxlength="50" placeholder="<?php echo __('Например: Отдел продаж'); ?>">
                        <p class="help-block"><?php echo __('От 1 до 50 символов.'); ?></p>
                    </div>

                    <div class="form-group rc-divcode-group" id="rc-add-org-divcode-group"
                         data-hint-auto="<?php echo HTML::chars(__('Код будет сформирован из названия организации.') . ' ' . __('Префикс:') . ' ' . $divcode_prefix); ?>"
                         data-hint-manual="<?php echo HTML::chars(__('От 1 до 50 символов: латиница, цифры, «-», «_», «.».').' '.__('Пусто — код будет сформирован автоматически.')); ?>">
                        <label for="rc-add-org-divcode">
                            <?php echo __('Код организации (divcode)'); ?>
                        </label>
                        <input type="text" class="form-control" id="rc-add-org-divcode" name="divcode"
                               maxlength="50" placeholder="<?php echo __('Например: dv_sales'); ?>"
                               autocomplete="off" disabled>

                        <div class="checkbox rc-divcode-auto">
                            <label for="rc-add-org-divcode-auto" title="<?php echo __('Сформировать код из названия организации'); ?>">
                                <input type="checkbox" id="rc-add-org-divcode-auto" name="divcode_auto" value="1" checked>
                                <?php echo __('Автоматически'); ?>
                            </label>
                        </div>

                        <p class="help-block" id="rc-add-org-divcode-hint">
                            <?php echo __('Код будет сформирован из названия организации.'); ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="rc-add-org-parent"><?php echo __('Родительская организация'); ?></label>
                        <select class="form-control" id="rc-add-org-parent" name="parent">
                            <option value=""><?php echo __('— Корневая организация —'); ?></option>
                            <?php foreach ($parent_options as $option): ?>
                                <option value="<?php echo HTML::chars($option['divcode']); ?>">
                                    <?php echo HTML::chars($option['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="help-block"><?php echo __('Пусто — создать корневую организацию.'); ?></p>
                    </div>

                    <div class="form-group">
                        <label for="rc-add-org-flag"><?php echo __('Флаг'); ?></label>
                        <select class="form-control" id="rc-add-org-flag" name="flag">
                            <option value="0"><?php echo __('0 — обычная'); ?></option>
                            <option value="1"><?php echo __('1 — гостевая'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo __('Отмена'); ?></button>
                    <button type="submit" class="btn btn-primary" id="rc-add-org-save">
                        <span class="glyphicon glyphicon-floppy-disk"></span>
                        <span id="rc-add-org-save-text"><?php echo __('Сохранить'); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== Модальное окно: добавление сотрудника ===== -->
<div class="modal fade" id="rc-add-person-modal" tabindex="-1" role="dialog" aria-labelledby="rc-add-person-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="rc-add-person-form" autocomplete="off">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title" id="rc-add-person-title">
                        <span class="glyphicon glyphicon-user"></span>
                        <?php echo __('Новый сотрудник'); ?>
                    </h4>
                </div>

                <div class="modal-body">
                    <div id="rc-add-person-alert" class="alert" style="display: none;">
                        <span class="glyphicon glyphicon-warning-sign"></span>
                        <span id="rc-add-person-alert-text"></span>
                    </div>

                    <p class="text-muted rc-form-note">
                        <?php echo __('Обязательна только фамилия. Сотрудник будет добавлен в выбранную организацию.'); ?>
                    </p>

                    <div class="form-group">
                        <label for="rc-add-person-org">
                            <?php echo __('Организация'); ?> <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="rc-add-person-org" name="id_org">
                            <?php foreach ($org_options as $option): ?>
                                <option value="<?php echo (int) $option['id']; ?>"
                                        data-divcode="<?php echo HTML::chars($option['divcode']); ?>">
                                    <?php echo HTML::chars($option['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="help-block"><?php echo __('Организация, в которой будет числиться сотрудник.'); ?></p>
                    </div>

                    <div class="form-group">
                        <label for="rc-add-person-surname">
                            <?php echo __('Фамилия'); ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="rc-add-person-surname" name="surname"
                               maxlength="50" placeholder="<?php echo __('Например: Иванов'); ?>">
                        <p class="help-block"><?php echo __('От 1 до 50 символов.'); ?></p>
                    </div>

                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="rc-add-person-name"><?php echo __('Имя'); ?></label>
                                <input type="text" class="form-control" id="rc-add-person-name" name="name"
                                       maxlength="50" placeholder="<?php echo __('Иван'); ?>">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="rc-add-person-patronymic"><?php echo __('Отчество'); ?></label>
                                <input type="text" class="form-control" id="rc-add-person-patronymic" name="patronymic"
                                       maxlength="50" placeholder="<?php echo __('Иванович'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group rc-tabnum-group" id="rc-add-person-tabnum-group"
                         data-hint-auto="<?php echo HTML::chars(__('Номер сформирует REST API автоматически: tn_<ID сотрудника>.')); ?>"
                         data-hint-manual="<?php echo HTML::chars(__('1–50 символов. Пустое поле — номер будет сформирован автоматически (tn_<ID>).')); ?>">
                        <label for="rc-add-person-tabnum">
                            <?php echo __('Табельный номер (tabnum)'); ?>
                        </label>
                        <input type="text" class="form-control" id="rc-add-person-tabnum" name="tabnum"
                               maxlength="50" placeholder="<?php echo __('Например: tn_123'); ?>"
                               autocomplete="off">

                        <div class="checkbox rc-tabnum-auto">
                            <label for="rc-add-person-tabnum-auto" title="<?php echo __('Сформировать номер автоматически'); ?>">
                                <input type="checkbox" id="rc-add-person-tabnum-auto" name="tabnum_auto" value="1">
                                <?php echo __('Автоматически'); ?>
                            </label>
                        </div>

                        <p class="help-block" id="rc-add-person-tabnum-hint">
                            <?php echo HTML::chars(__('1–50 символов. Пустое поле — номер будет сформирован автоматически (tn_<ID>).')); ?>
                        </p>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo __('Отмена'); ?></button>
                    <button type="submit" class="btn btn-primary" id="rc-add-person-save">
                        <span class="glyphicon glyphicon-floppy-disk"></span>
                        <span id="rc-add-person-save-text"><?php echo __('Сохранить'); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== Модальное окно: удаление выбранного элемента ===== -->
<div class="modal fade" id="rc-delete-modal" tabindex="-1" role="dialog" aria-labelledby="rc-delete-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="rc-delete-title">
                    <span class="glyphicon glyphicon-trash"></span>
                    <span id="rc-delete-title-text"><?php echo __('Удаление'); ?></span>
                </h4>
            </div>

            <div class="modal-body">
                <div id="rc-delete-alert" class="alert" style="display: none;">
                    <span class="glyphicon glyphicon-warning-sign"></span>
                    <span id="rc-delete-alert-text"></span>
                </div>

                <!-- Удаление организации -->
                <div id="rc-delete-org-block">
                    <p>
                        <?php echo __('Удалить организацию'); ?>
                        <strong id="rc-delete-org-name"></strong>
                        <span class="text-muted" id="rc-delete-org-code"></span>?
                    </p>

                    <div class="alert alert-warning" id="rc-delete-org-warning" style="display: none;">
                        <span class="glyphicon glyphicon-info-sign"></span>
                        <span id="rc-delete-org-warning-text"></span>
                    </div>

                    <p class="text-muted small rc-delete-hint">
                        <?php echo __('Действие необратимо. API не позволит удалить организацию, у которой есть дочерние организации или активные сотрудники.'); ?>
                    </p>
                </div>

                <!-- Удаление сотрудника -->
                <div id="rc-delete-person-block" style="display: none;">
                    <p>
                        <?php echo __('Удалить сотрудника'); ?>
                        <strong id="rc-delete-person-name"></strong>
                        <span class="text-muted" id="rc-delete-person-tabnum"></span>?
                    </p>

                    <p class="text-muted small rc-delete-hint">
                        <?php echo __('Сотрудник будет удалён из организации'); ?>
                        <strong id="rc-delete-person-org"></strong>.
                        <?php echo __('Вместе с сотрудником API удаляет все его идентификаторы (карты). Действие необратимо.'); ?>
                    </p>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo __('Отмена'); ?></button>
                <button type="button" class="btn btn-danger" id="rc-delete-confirm">
                    <span class="glyphicon glyphicon-trash"></span>
                    <span id="rc-delete-confirm-text"><?php echo __('Удалить'); ?></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.RESTMANCARD = <?php echo $data_json; ?>;
</script>

<script>
(function ($) {
    'use strict';

    var DATA = window.RESTMANCARD || { orgs: {}, people: {}, meta: {} };
    var META = DATA.meta || {};
    var $tree = $('#rc-tree');
    var $props = $('#rc-properties');
    var searchTimer = null;
    var selectedOrgUid = null;
    var selectedPersonUid = null;

    // ===== Вспомогательные функции =====

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function row(label, value) {
        var text = (value === null || value === undefined || value === '')
            ? '<span class="text-muted">—</span>'
            : esc(value);

        return '<div class="info-item">'
            + '<span class="label">' + esc(label) + '</span>'
            + '<span class="value">' + text + '</span>'
            + '</div>';
    }

    function rowHtml(label, html) {
        return '<div class="info-item">'
            + '<span class="label">' + esc(label) + '</span>'
            + '<span class="value">' + (html || '<span class="text-muted">—</span>') + '</span>'
            + '</div>';
    }

    function setBadge(text, cssClass) {
        $('#rc-entity-badge')
            .removeClass('label-info label-success label-default')
            .addClass(cssClass)
            .text(text)
            .show();
    }

    function setSelectedInfo(html) {
        $('#rc-selected-info').html('<span class="glyphicon glyphicon-ok-sign"></span> ' + html);
    }

    function cardsHtml(cards) {
        if (!cards || !cards.length) {
            return '<span class="text-muted">' + esc('нет') + '</span>';
        }

        var html = '';
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            html += '<span class="card-badge ' + esc(card.card_class) + '" title="'
                + esc(card.type_name + ': ' + card.id_card) + '">'
                + esc(card.type_short) + ' ' + esc(card.id_card)
                + '</span>';
        }

        return html;
    }

    function selectedOrg() {
        return selectedOrgUid ? (DATA.orgs[selectedOrgUid] || null) : null;
    }

    function selectedPerson() {
        return selectedPersonUid ? (DATA.people[selectedPersonUid] || null) : null;
    }

    // Кнопка «Удалить» работает с выбранным элементом: организацией или
    // сотрудником. Тип удаления запоминается в deleteTarget при открытии окна.
    function updateDeleteButton() {
        var o = selectedOrg();
        var p = selectedPerson();
        var title = <?php echo json_encode(__('Выберите организацию или сотрудника, чтобы удалить')); ?>;
        var enabled = false;

        if (o && o.divcode) {
            title = <?php echo json_encode(__('Удалить выбранную организацию')); ?>;
            enabled = true;
        } else if (p && p.tabnum) {
            title = <?php echo json_encode(__('Удалить выбранного сотрудника')); ?>;
            enabled = true;
        }

        $('#rc-delete-item')
            .prop('disabled', !enabled)
            .attr('title', title);
    }

    // ===== Панель «Свойства» =====

    function showOrg(uid) {
        var o = DATA.orgs[uid];
        if (!o) {
            return;
        }

        var html = '<span class="props-entity-title">'
            + '<span class="glyphicon glyphicon-folder-open"></span> ' + esc('Организация')
            + '</span>';

        html += row('Название', o.name);
        html += row('Код (divcode)', o.divcode);
        html += row('ID_ORG', o.id);
        html += row('Родитель', o.parent
            ? (o.parent_name ? o.parent_name + ' (' + o.parent + ')' : o.parent)
            : '— корневая');
        html += row('Флаг', o.flag === 1 ? '1 — гостевая' : '0 — обычная');
        html += row('Путь', o.path);

        var children = String(o.children_count);
        if (o.total_children > o.children_count) {
            children += ' (с вложенными: ' + o.total_children + ')';
        }
        html += row('Подразделений', children);

        var people = String(o.people_count);
        if (o.total_people > o.people_count) {
            people += ' (с вложенными: ' + o.total_people + ')';
        }
        html += row('Сотрудников', people);
        html += row('Создана', o.created_at);
        html += row('Изменена', o.updated_at);

        if (o.is_people_root) {
            var note = META.org_map_enabled
                ? <?php echo json_encode(__('Для этих сотрудников не определена организация: нет привязки в PEOPLE.ID_ORG или организация не найдена в REST API. Поэтому они показаны здесь.')); ?>
                : <?php echo json_encode(__('Привязка сотрудников к организациям отключена настройкой people.org_map: все сотрудники показаны здесь.')); ?>;

            html += '<div class="alert alert-info rc-note">'
                + '<span class="glyphicon glyphicon-info-sign"></span> '
                + esc(note)
                + '</div>';
        }

        // Действия с сотрудниками выбранной организации
        html += '<div class="rc-props-actions">'
            + '<button type="button" class="btn btn-sm btn-success rc-props-add-person"'
            + ' data-id-org="' + o.id + '" data-divcode="' + esc(o.divcode) + '">'
            + '<span class="glyphicon glyphicon-user"></span> '
            + esc(<?php echo json_encode(__('Добавить сотрудника')); ?>)
            + '</button>'
            + '</div>';

        $props.html(html);
        setBadge('Организация', 'label-info');
        setSelectedInfo('<strong>' + esc('Организация') + ':</strong> ' + esc(o.name)
            + ' <span class="text-muted">[' + esc(o.divcode) + ']</span>');

        selectedOrgUid = uid;
        selectedPersonUid = null;
        updateDeleteButton();
    }

    function showPerson(uid) {
        selectedOrgUid = null;
        selectedPersonUid = null;
        updateDeleteButton();

        var p = DATA.people[uid];
        if (!p) {
            return;
        }

        var html = '<span class="props-entity-title">'
            + '<span class="glyphicon glyphicon-user"></span> ' + esc('Сотрудник')
            + '</span>';

        html += row('ФИО', p.full_name);
        html += row('Табельный номер', p.tabnum);
        html += row('Организация', p.org_name);
        html += rowHtml('Идентификаторы', cardsHtml(p.identifiers));

        // Удаление сотрудника доступно и из панели свойств, и кнопкой
        // «Удалить» в заголовке панели «Организации».
        html += '<div class="rc-props-actions">'
            + '<button type="button" class="btn btn-sm btn-danger rc-props-delete-person">'
            + '<span class="glyphicon glyphicon-trash"></span> '
            + esc(<?php echo json_encode(__('Удалить сотрудника')); ?>)
            + '</button>'
            + '</div>';

        $props.html(html);
        setBadge('Сотрудник', 'label-success');
        setSelectedInfo('<strong>' + esc('Сотрудник') + ':</strong> ' + esc(p.full_name)
            + ' <span class="text-muted">[' + esc(p.tabnum) + ']</span>');

        selectedPersonUid = uid;
        updateDeleteButton();
    }

    // ===== Дерево =====

    function hasChildren($node) {
        var $ul = $node.children('ul.tree-children').first();
        return $ul.length > 0 && $ul.children().length > 0;
    }

    function setFolder($node, expanded) {
        $node.children('.tree-item').find('.rc-folder').first().text(expanded ? '📂' : '📁');
    }

    function toggleNode($node, force) {
        var $ul = $node.children('ul.tree-children').first();
        if (!$ul.length || !$ul.children().length) {
            return;
        }

        var expanded = $node.attr('data-expanded') === 'true';
        var next = (typeof force === 'boolean') ? force : !expanded;

        $node.attr('data-expanded', next ? 'true' : 'false');
        $ul.toggle(next);
        setFolder($node, next);
    }

    function selectNode($node) {
        $tree.find('.tree-item.rc-selected').removeClass('rc-selected');
        var $item = $node.children('.tree-item').first();
        $item.addClass('rc-selected');

        var type = $node.attr('data-type');
        var uid = $node.attr('data-uid');

        if (type === 'person') {
            showPerson(uid);
        } else {
            showOrg(uid);
        }
    }

    if ($tree.length) {
        $tree.on('click', '.tree-toggle', function (event) {
            event.stopPropagation();
            toggleNode($(this).closest('li.tree-node'));
        });

        $tree.on('click', '.tree-item', function (event) {
            if ($(event.target).closest('.tree-toggle').length) {
                return;
            }

            var $node = $(this).closest('li.tree-node');
            selectNode($node);

            // Клик по организации «открывает» её: в ветке показываются
            // дочерние организации и сотрудники этой организации.
            // Свернуть ветку можно кнопкой-стрелкой (tree-toggle).
            if ($node.attr('data-type') === 'org') {
                toggleNode($node, true);
            }
        });

        $tree.on('mouseenter', '.tree-item', function () {
            var $node = $(this).closest('li.tree-node');
            var type = $node.attr('data-type');
            var uid = $node.attr('data-uid');
            var text = '';

            if (type === 'person' && DATA.people[uid]) {
                text = 'Табельный номер: ' + esc(DATA.people[uid].tabnum);
            } else if (DATA.orgs[uid]) {
                text = 'ID_ORG: ' + esc(DATA.orgs[uid].id) + ' · divcode: ' + esc(DATA.orgs[uid].divcode);
            }

            $('#rc-hover-info').html('<span class="glyphicon glyphicon-info-sign"></span> ' + text);
        });

        $tree.on('mouseleave', '.tree-item', function () {
            $('#rc-hover-info').html('<span class="glyphicon glyphicon-info-sign"></span> '
                + '<span class="text-muted">' + esc('Наведите на элемент') + '</span>');
        });
    }

    $('#rc-expand-all').on('click', function () {
        $tree.find('li.tree-node').each(function () {
            var $node = $(this);
            if (hasChildren($node)) {
                $node.children('ul.tree-children').show();
                $node.attr('data-expanded', 'true');
                setFolder($node, true);
            }
        });
    });

    $('#rc-collapse-all').on('click', function () {
        $tree.find('li.tree-node').each(function () {
            var $node = $(this);
            if (hasChildren($node)) {
                $node.children('ul.tree-children').hide();
                $node.attr('data-expanded', 'false');
                setFolder($node, false);
            }
        });
    });

    // ===== Поиск по дереву =====

    function resetSearch() {
        $tree.removeClass('rc-searching');
        $tree.find('li.tree-node').removeClass('rc-visible');
        $('#rc-search-info').hide();
    }

    function applySearch() {
        var query = $.trim($('#rc-search').val()).toLowerCase();

        if (query === '') {
            resetSearch();
            return;
        }

        $tree.addClass('rc-searching');

        var $nodes = $tree.find('li.tree-node');
        $nodes.removeClass('rc-visible');

        var $matches = $nodes.filter(function () {
            var name = $(this).attr('data-name') || '';
            return name.toLowerCase().indexOf(query) !== -1;
        });

        $matches.each(function () {
            var $node = $(this);
            $node.addClass('rc-visible');
            $node.parents('li.tree-node').addClass('rc-visible');
        });

        // Раскрываем ветки у найденных элементов и их предков
        $tree.find('li.tree-node.rc-visible').each(function () {
            var $node = $(this);
            if (hasChildren($node)) {
                $node.children('ul.tree-children').show();
                $node.attr('data-expanded', 'true');
                setFolder($node, true);
            }
        });

        $('#rc-search-count').text($matches.length);
        $('#rc-search-info').show();
    }

    $('#rc-search').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applySearch, 150);
    });

    $('#rc-search-clear').on('click', function () {
        $('#rc-search').val('');
        resetSearch();
    });

    // ===== Добавление организации =====

    var $addModal    = $('#rc-add-org-modal');
    var $addForm     = $('#rc-add-org-form');
    var $addAlert    = $('#rc-add-org-alert');
    var $addAlertTxt = $('#rc-add-org-alert-text');
    var $addSave     = $('#rc-add-org-save');
    var $addSaveTxt  = $('#rc-add-org-save-text');
    var addUrl       = <?php echo json_encode($add_url); ?>;
    var deleteUrl    = <?php echo json_encode($delete_url); ?>;
    var indexUrl     = <?php echo json_encode(URL::site('restmancard/index')); ?>;
    var focusDivcode = <?php echo json_encode($focus_divcode); ?>;
    var focusPerson  = <?php echo json_encode($focus_person); ?>;
    var addBusy      = false;

    // Чекбокс «Автоматически» у поля divcode
    var $divcodeGroup  = $('#rc-add-org-divcode-group');
    var $divcodeAuto   = $('#rc-add-org-divcode-auto');
    var $divcodeInput  = $('#rc-add-org-divcode');
    var $divcodeHint   = $('#rc-add-org-divcode-hint');
    var divcodeHintAuto   = $divcodeGroup.attr('data-hint-auto') || '';
    var divcodeHintManual = $divcodeGroup.attr('data-hint-manual') || '';
    var divcodePlaceholderManual = $divcodeInput.attr('placeholder') || '';
    var divcodePlaceholderAuto   = '<?php echo __('Будет сформирован автоматически'); ?>';

    function divcodeAutoEnabled() {
        return $divcodeAuto.length > 0 && $divcodeAuto.prop('checked');
    }

    function syncDivcodeAuto() {
        var auto = divcodeAutoEnabled();

        $divcodeInput
            .prop('disabled', auto)
            .prop('placeholder', auto ? divcodePlaceholderAuto : divcodePlaceholderManual);

        $divcodeHint.text(auto ? divcodeHintAuto : divcodeHintManual);

        if (auto) {
            $divcodeGroup.removeClass('has-error');
        }
    }

    function showAddAlert(text, type) {
        $addAlert
            .removeClass('alert-danger alert-success')
            .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
            .show();
        $addAlertTxt.text(text || '');
    }

    function hideAddAlert() {
        $addAlert.removeClass('alert-danger alert-success').hide();
        $addAlertTxt.text('');
    }

    function clearAddErrors() {
        $addForm.find('.form-group').removeClass('has-error');
    }

    function markAddField(field) {
        $addForm.find('[name="' + field + '"]').closest('.form-group').addClass('has-error');
    }

    function showAddErrors(errors) {
        if (!errors) {
            return;
        }

        for (var field in errors) {
            if (Object.prototype.hasOwnProperty.call(errors, field)) {
                markAddField(field);
            }
        }
    }

    function setAddBusy(busy) {
        addBusy = busy;
        $addSave.prop('disabled', busy);
        $addSaveTxt.text(busy ? '<?php echo __('Сохранение...'); ?>' : '<?php echo __('Сохранить'); ?>');
    }

    function selectedOrgDivcode() {
        var $selected = $('#rc-tree .tree-item.rc-selected').first();
        if (!$selected.length) {
            return '';
        }

        var $node = $selected.closest('li.tree-node');
        if ($node.attr('data-type') !== 'org') {
            $node = $node.parents('li.tree-node[data-type="org"]').first();
        }

        return $node.length ? ($node.attr('data-divcode') || '') : '';
    }

    function openAddOrg() {
        setAddBusy(false);
        hideAddAlert();
        clearAddErrors();

        if ($addForm.length && $addForm[0].reset) {
            $addForm[0].reset();
        }

        // reset() возвращает чекбоксу значение по умолчанию (включён)
        syncDivcodeAuto();

        $('#rc-add-org-parent').val(selectedOrgDivcode());
        $addModal.modal('show');
    }

    function saveAddOrg() {
        if (addBusy) {
            return;
        }

        hideAddAlert();
        clearAddErrors();

        var name    = $.trim($('#rc-add-org-name').val());
        var auto    = divcodeAutoEnabled();
        var divcode = auto ? '' : $.trim($divcodeInput.val());
        var parent  = $('#rc-add-org-parent').val();
        var flag    = $('#rc-add-org-flag').val();
        var valid   = true;

        if (name === '' || name.length > 50) {
            markAddField('name');
            valid = false;
        }

        // Код необязателен: пустое поле сервер заполнит автоматически
        if (!auto && divcode !== '' && !/^[A-Za-z0-9._-]{1,50}$/.test(divcode)) {
            markAddField('divcode');
            valid = false;
        }

        if (!valid) {
            showAddAlert('<?php echo __('Проверьте заполнение полей'); ?>', 'error');
            return;
        }

        setAddBusy(true);

        $.ajax({
            url: addUrl,
            type: 'POST',
            data: {
                name: name,
                divcode: divcode,
                divcode_auto: auto ? 1 : 0,
                parent: parent,
                flag: flag
            },
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.success) {
                setAddBusy(false);
                showAddErrors(response && response.errors);
                showAddAlert((response && response.message) || '<?php echo __('Не удалось добавить организацию'); ?>', 'error');
                return;
            }

            // Автоматический код знает только сервер — берём его из ответа
            var savedDivcode = (response && response.divcode) ? response.divcode : divcode;

            $addSaveTxt.text('<?php echo __('Готово'); ?>');
            showAddAlert(response.message || '<?php echo __('Организация добавлена'); ?>', 'success');

            window.setTimeout(function () {
                window.location.href = indexUrl + '?refresh=1&focus=' + encodeURIComponent(savedDivcode);
            }, 700);
        }).fail(function (xhr) {
            setAddBusy(false);

            var message = '<?php echo __('Не удалось добавить организацию'); ?>';

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            } else if (xhr && xhr.status) {
                message += ' (HTTP ' + xhr.status + ')';
            }

            showAddAlert(message, 'error');
        });
    }

    $('#rc-add-org').on('click', openAddOrg);

    $divcodeAuto.on('change', syncDivcodeAuto);
    syncDivcodeAuto();

    $addForm.on('submit', function (event) {
        event.preventDefault();
        saveAddOrg();
    });

    $addModal.on('shown.bs.modal', function () {
        $('#rc-add-org-name').trigger('focus');
    });

    $addModal.on('hidden.bs.modal', function () {
        setAddBusy(false);
    });

    // ===== Добавление сотрудника =====

    var $addPersonModal    = $('#rc-add-person-modal');
    var $addPersonForm     = $('#rc-add-person-form');
    var $addPersonAlert    = $('#rc-add-person-alert');
    var $addPersonAlertTxt = $('#rc-add-person-alert-text');
    var $addPersonSave     = $('#rc-add-person-save');
    var $addPersonSaveTxt  = $('#rc-add-person-save-text');
    var addPersonUrl       = <?php echo json_encode($add_person_url); ?>;
    var deletePersonUrl    = <?php echo json_encode($delete_person_url); ?>;
    var peopleRoot         = (META && META.people_root) ? META.people_root : '';
    var addPersonBusy      = false;

    // Чекбокс «Автоматически» у поля tabnum
    var $tabnumGroup           = $('#rc-add-person-tabnum-group');
    var $tabnumAuto            = $('#rc-add-person-tabnum-auto');
    var $tabnumInput           = $('#rc-add-person-tabnum');
    var $tabnumHint            = $('#rc-add-person-tabnum-hint');
    var tabnumHintAuto         = $tabnumGroup.attr('data-hint-auto') || '';
    var tabnumHintManual       = $tabnumGroup.attr('data-hint-manual') || '';
    var tabnumPlaceholderManual = $tabnumInput.attr('placeholder') || '';
    var tabnumPlaceholderAuto  = '<?php echo __('Будет сформирован автоматически'); ?>';

    function tabnumAutoEnabled() {
        return $tabnumAuto.length > 0 && $tabnumAuto.prop('checked');
    }

    function syncTabnumAuto() {
        var auto = tabnumAutoEnabled();

        $tabnumInput
            .prop('disabled', auto)
            .prop('placeholder', auto ? tabnumPlaceholderAuto : tabnumPlaceholderManual);

        $tabnumHint.text(auto ? tabnumHintAuto : tabnumHintManual);

        if (auto) {
            $tabnumGroup.removeClass('has-error');
        }
    }

    function showAddPersonAlert(text, type) {
        $addPersonAlert
            .removeClass('alert-danger alert-success')
            .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
            .show();
        $addPersonAlertTxt.text(text || '');
    }

    function hideAddPersonAlert() {
        $addPersonAlert.removeClass('alert-danger alert-success').hide();
        $addPersonAlertTxt.text('');
    }

    function clearAddPersonErrors() {
        $addPersonForm.find('.form-group').removeClass('has-error');
    }

    function markAddPersonField(field) {
        $addPersonForm.find('[name="' + field + '"]').closest('.form-group').addClass('has-error');
    }

    function showAddPersonErrors(errors) {
        if (!errors) {
            return;
        }

        for (var field in errors) {
            if (Object.prototype.hasOwnProperty.call(errors, field)) {
                markAddPersonField(field);
            }
        }
    }

    function setAddPersonBusy(busy) {
        addPersonBusy = busy;
        $addPersonSave.prop('disabled', busy);
        $addPersonSaveTxt.text(busy ? '<?php echo __('Сохранение...'); ?>' : '<?php echo __('Сохранить'); ?>');
    }

    function findOrgByDivcode(divcode) {
        if (!divcode) {
            return null;
        }

        var needle = String(divcode).toLowerCase();
        var found = null;

        $.each(DATA.orgs, function (uid, org) {
            if (org && org.divcode && String(org.divcode).toLowerCase() === needle) {
                found = org;
                return false;
            }
        });

        return found;
    }

    // Организация по умолчанию: организация выбранного сотрудника, затем
    // выбранная организация в дереве, затем корень сотрудников
    // (people_root_divcode), затем первая организация списка.
    function defaultPersonOrgId() {
        var p = selectedPerson();

        if (p && p.org_divcode) {
            var personOrg = findOrgByDivcode(p.org_divcode);

            if (personOrg && personOrg.id) {
                return String(personOrg.id);
            }
        }

        var o = selectedOrg();

        if (o && o.id) {
            return String(o.id);
        }

        var rootOrg = findOrgByDivcode(peopleRoot);

        if (rootOrg && rootOrg.id) {
            return String(rootOrg.id);
        }

        var $first = $('#rc-add-person-org option').first();

        return $first.length ? String($first.val()) : '';
    }

    function openAddPerson(idOrg) {
        setAddPersonBusy(false);
        hideAddPersonAlert();
        clearAddPersonErrors();

        if ($addPersonForm.length && $addPersonForm[0].reset) {
            $addPersonForm[0].reset();
        }

        // reset() снимает чекбокс «Автоматически» — это состояние по умолчанию
        syncTabnumAuto();

        var value = idOrg ? String(idOrg) : defaultPersonOrgId();

        if (value === '' || $('#rc-add-person-org option[value="' + value + '"]').length === 0) {
            var $first = $('#rc-add-person-org option').first();
            value = $first.length ? String($first.val()) : '';
        }

        $('#rc-add-person-org').val(value);
        $addPersonModal.modal('show');
    }

    function saveAddPerson() {
        if (addPersonBusy) {
            return;
        }

        hideAddPersonAlert();
        clearAddPersonErrors();

        var surname    = $.trim($('#rc-add-person-surname').val());
        var name       = $.trim($('#rc-add-person-name').val());
        var patronymic = $.trim($('#rc-add-person-patronymic').val());
        var auto       = tabnumAutoEnabled();
        var tabnum     = auto ? '' : $.trim($tabnumInput.val());
        var idOrg      = $('#rc-add-person-org').val();
        var valid      = true;

        if (surname === '' || surname.length > 50) {
            markAddPersonField('surname');
            valid = false;
        }

        if (name.length > 50) {
            markAddPersonField('name');
            valid = false;
        }

        if (patronymic.length > 50) {
            markAddPersonField('patronymic');
            valid = false;
        }

        // Номер необязателен: пустое поле сервер заполнит автоматически
        if (!auto && tabnum !== '' && tabnum.length > 50) {
            markAddPersonField('tabnum');
            valid = false;
        }

        if (!idOrg) {
            markAddPersonField('id_org');
            valid = false;
        }

        if (!valid) {
            showAddPersonAlert('<?php echo __('Проверьте заполнение полей'); ?>', 'error');
            return;
        }

        setAddPersonBusy(true);

        $.ajax({
            url: addPersonUrl,
            type: 'POST',
            data: {
                id_org: idOrg,
                surname: surname,
                name: name,
                patronymic: patronymic,
                tabnum: tabnum,
                tabnum_auto: auto ? 1 : 0
            },
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.success) {
                setAddPersonBusy(false);
                showAddPersonErrors(response && response.errors);
                showAddPersonAlert((response && response.message) || '<?php echo __('Не удалось добавить сотрудника'); ?>', 'error');
                return;
            }

            // Автоматический номер знает только сервер — берём его из ответа
            var org = (response && response.organization) ? response.organization : {};
            var focus = org.divcode ? '&focus=' + encodeURIComponent(org.divcode) : '';
            var person = response.tabnum ? '&person=' + encodeURIComponent(response.tabnum) : '';

            $addPersonSaveTxt.text('<?php echo __('Готово'); ?>');
            showAddPersonAlert(response.message || '<?php echo __('Сотрудник добавлен'); ?>', 'success');

            window.setTimeout(function () {
                window.location.href = indexUrl + '?refresh=1' + focus + person;
            }, 700);
        }).fail(function (xhr) {
            setAddPersonBusy(false);

            var message = '<?php echo __('Не удалось добавить сотрудника'); ?>';

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            } else if (xhr && xhr.status) {
                message += ' (HTTP ' + xhr.status + ')';
            }

            showAddPersonAlert(message, 'error');
        });
    }

    $('#rc-add-person').on('click', function () {
        openAddPerson('');
    });

    $props.on('click', '.rc-props-add-person', function () {
        openAddPerson($(this).attr('data-id-org'));
    });

    $props.on('click', '.rc-props-delete-person', function () {
        openDeletePerson(selectedPerson());
    });

    $tabnumAuto.on('change', syncTabnumAuto);
    syncTabnumAuto();

    $addPersonForm.on('submit', function (event) {
        event.preventDefault();
        saveAddPerson();
    });

    $addPersonModal.on('shown.bs.modal', function () {
        $('#rc-add-person-surname').trigger('focus');
    });

    $addPersonModal.on('hidden.bs.modal', function () {
        setAddPersonBusy(false);
    });

    // ===== Удаление выбранного элемента =====
    // Кнопка «Удалить» в заголовке панели работает с тем, что выбрано:
    // организацией или сотрудником. Окно подтверждения меняет содержимое,
    // а тип удаления запоминается в deleteTarget при открытии окна.

    var $deleteModal       = $('#rc-delete-modal');
    var $deleteTitle       = $('#rc-delete-title-text');
    var $deleteAlert       = $('#rc-delete-alert');
    var $deleteAlertTxt    = $('#rc-delete-alert-text');
    var $deleteOrgBlock    = $('#rc-delete-org-block');
    var $deletePersonBlock = $('#rc-delete-person-block');
    var $deleteWarning     = $('#rc-delete-org-warning');
    var $deleteWarningTxt  = $('#rc-delete-org-warning-text');
    var $deleteConfirm     = $('#rc-delete-confirm');
    var $deleteConfirmTxt  = $('#rc-delete-confirm-text');
    var deleteBusy         = false;
    var deleteTarget       = null;

    function showDeleteAlert(text, type) {
        $deleteAlert
            .removeClass('alert-danger alert-success alert-warning')
            .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
            .show();
        $deleteAlertTxt.text(text || '');
    }

    function hideDeleteAlert() {
        $deleteAlert.removeClass('alert-danger alert-success alert-warning').hide();
        $deleteAlertTxt.text('');
    }

    function deleteResponseDetails(response) {
        var details = [];

        if (response && response.children && response.children.length) {
            details.push('<?php echo __('Дочерние организации'); ?>: ' + response.children.join(', '));
        }

        if (response && response.people && response.people.length) {
            details.push('<?php echo __('Сотрудники'); ?>: ' + response.people.join(', '));
        }

        return details.length ? ' ' + details.join('. ') + '.' : '';
    }

    function setDeleteBusy(busy) {
        deleteBusy = busy;
        $deleteConfirm.prop('disabled', busy);
        $deleteConfirmTxt.text(busy ? '<?php echo __('Удаление...'); ?>' : '<?php echo __('Удалить'); ?>');
    }

    function openDeleteOrg() {
        var o = selectedOrg();

        if (!o || !o.divcode) {
            return;
        }

        // Запоминаем организацию: подтверждение должно удалить именно её,
        // даже если выделение в дереве успеет измениться.
        deleteTarget = { type: 'org', data: o };

        setDeleteBusy(false);
        hideDeleteAlert();

        $deleteTitle.text('<?php echo __('Удаление организации'); ?>');
        $deleteOrgBlock.show();
        $deletePersonBlock.hide();

        $('#rc-delete-org-name').text('«' + o.name + '»');
        $('#rc-delete-org-code').text('[' + o.divcode + ']');

        // Локальные счётчики могут быть неполными, окончательное решение — за API
        var counts = [];

        if (o.children_count > 0) {
            counts.push('<?php echo __('подразделений'); ?>: ' + o.children_count);
        }

        if (o.people_count > 0) {
            counts.push('<?php echo __('сотрудников'); ?>: ' + o.people_count);
        }

        if (counts.length) {
            $deleteWarningTxt.text('<?php echo __('По данным дерева у организации есть'); ?> '
                + counts.join(', ') + '. '
                + '<?php echo __('API откажет в удалении, пока они не удалены или не перемещены.'); ?>');
            $deleteWarning.show();
        } else {
            $deleteWarningTxt.text('');
            $deleteWarning.hide();
        }

        $deleteModal.modal('show');
    }

    function openDeletePerson(p) {
        if (!p || !p.tabnum) {
            return;
        }

        deleteTarget = { type: 'person', data: p };

        setDeleteBusy(false);
        hideDeleteAlert();

        $deleteTitle.text('<?php echo __('Удаление сотрудника'); ?>');
        $deletePersonBlock.show();
        $deleteOrgBlock.hide();

        $('#rc-delete-person-name').text('«' + (p.full_name || '') + '»');
        $('#rc-delete-person-tabnum').text('[' + p.tabnum + ']');
        $('#rc-delete-person-org').text(p.org_name || '');

        $deleteModal.modal('show');
    }

    function openDeleteSelected() {
        var o = selectedOrg();

        if (o) {
            openDeleteOrg();
            return;
        }

        var p = selectedPerson();

        if (p) {
            openDeletePerson(p);
        }
    }

    function deleteOrgRequest(o) {
        $.ajax({
            url: deleteUrl,
            type: 'POST',
            data: { divcode: o.divcode },
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.success) {
                setDeleteBusy(false);
                showDeleteAlert(((response && response.message) || '<?php echo __('Не удалось удалить организацию'); ?>')
                    + deleteResponseDetails(response), 'error');
                return;
            }

            $deleteConfirmTxt.text('<?php echo __('Готово'); ?>');
            showDeleteAlert(response.message || '<?php echo __('Организация удалена'); ?>', 'success');

            window.setTimeout(function () {
                // Сбрасываем кеш и раскрываем ветку родителя удалённой организации
                var focus = o.parent ? '&focus=' + encodeURIComponent(o.parent) : '';
                window.location.href = indexUrl + '?refresh=1' + focus;
            }, 700);
        }).fail(function (xhr) {
            setDeleteBusy(false);

            var message = '<?php echo __('Не удалось удалить организацию'); ?>';

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            } else if (xhr && xhr.status) {
                message += ' (HTTP ' + xhr.status + ')';
            }

            showDeleteAlert(message, 'error');
        });
    }

    function deletePersonRequest(p) {
        $.ajax({
            url: deletePersonUrl,
            type: 'POST',
            data: { tabnum: p.tabnum },
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.success) {
                setDeleteBusy(false);
                showDeleteAlert((response && response.message) || '<?php echo __('Не удалось удалить сотрудника'); ?>', 'error');
                return;
            }

            $deleteConfirmTxt.text('<?php echo __('Готово'); ?>');
            showDeleteAlert(response.message || '<?php echo __('Сотрудник удалён'); ?>', 'success');

            window.setTimeout(function () {
                // Сбрасываем кеш и раскрываем ветку организации удалённого сотрудника
                var focus = p.org_divcode ? '&focus=' + encodeURIComponent(p.org_divcode) : '';
                window.location.href = indexUrl + '?refresh=1' + focus;
            }, 700);
        }).fail(function (xhr) {
            setDeleteBusy(false);

            var message = '<?php echo __('Не удалось удалить сотрудника'); ?>';

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            } else if (xhr && xhr.status) {
                message += ' (HTTP ' + xhr.status + ')';
            }

            showDeleteAlert(message, 'error');
        });
    }

    function deleteSelected() {
        if (deleteBusy || !deleteTarget) {
            return;
        }

        hideDeleteAlert();
        setDeleteBusy(true);

        if (deleteTarget.type === 'person') {
            deletePersonRequest(deleteTarget.data);
            return;
        }

        deleteOrgRequest(deleteTarget.data);
    }

    $('#rc-delete-item').on('click', openDeleteSelected);
    $deleteConfirm.on('click', deleteSelected);

    $deleteModal.on('hidden.bs.modal', function () {
        setDeleteBusy(false);
        deleteTarget = null;
    });

    updateDeleteButton();

    // ===== Организация, добавленная последней =====

    if (focusDivcode !== '') {
        var $focusNode = $tree.find('li.tree-node[data-type="org"]').filter(function () {
            return $(this).attr('data-divcode') === focusDivcode;
        }).first();

        if ($focusNode.length) {
            selectNode($focusNode);
            toggleNode($focusNode, true);

            var focusItem = $focusNode.children('.tree-item').get(0);
            if (focusItem && typeof focusItem.scrollIntoView === 'function') {
                focusItem.scrollIntoView(false);
            }
        }
    }

    // ===== Сотрудник, добавленный последним =====

    if (focusPerson !== '') {
        var $personNode = $tree.find('li.tree-node[data-type="person"]').filter(function () {
            return String($(this).attr('data-tabnum') || '').toLowerCase() === focusPerson.toLowerCase();
        }).first();

        if ($personNode.length) {
            // Раскрываем ветки предков, чтобы строка сотрудника была видна
            $personNode.parents('li.tree-node').each(function () {
                toggleNode($(this), true);
            });

            selectNode($personNode);

            var personItem = $personNode.children('.tree-item').get(0);
            if (personItem && typeof personItem.scrollIntoView === 'function') {
                personItem.scrollIntoView(false);
            }
        }
    }

    // ===== Первый выбор =====
    // Ничего не выбираем автоматически: пользователь начинает с подсказки,
    // как и в mancard/index.
})(jQuery);
</script>
