<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Отдельная форма для работы с сотрудниками (пункт меню «Сотрудники»).
 *
 * Форма сложена по образцу restmancard/organizations, но работает только
 * с сотрудниками: организации показаны группами дерева, управлять ими
 * (добавлять, переименовывать, удалять) здесь нельзя.
 *
 * Три панели:
 *   1) дерево организаций с сотрудниками;
 *   2) свойства выбранного сотрудника: ФИО и организация (родительская
 *      ссылка сотрудника);
 *   3) набор категорий доступа выбранного сотрудника.
 *
 * «Сохранить» панели свойств вызывает restmancard/save_person:
 *   PUT  /api/v2/Person          — ФИО (замена целиком);
 *   PATCH /api/v2/Person         — id_org, только если организация изменена.
 * «Сохранить» панели категорий вызывает restmancard/save_person_access:
 *   PUT /api/v2/Person/Access    — набор категорий целиком.
 *
 * @var array  $tree
 * @var string $error
 * @var array  $warnings
 * @var array  $stats
 * @var string $data_json
 * @var string $api_url
 * @var string $generated_at
 * @var string $refresh_url
 * @var string $save_person_url
 * @var string $save_access_url
 * @var string $access_url
 * @var string $focus_divcode
 * @var string $focus_person
 * @var array  $access_categories
 * @var string|NULL $access_error
 * @var bool   $access_truncated
 * @var bool   $access_editable
 */
?>
<?php echo HTML::style('static/css/restmancard.css?v=2.2.0&layout=3col'); ?>

<div class="panel panel-primary rc-root rc-orgs rc-people">
    <div class="panel-heading">
        <h3 class="panel-title">
            <span class="glyphicon glyphicon-user"></span>
            <?php echo __('Сотрудники'); ?>
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
            <!-- ===== Панель 1: дерево сотрудников по организациям ===== -->
            <div class="col-md-4">
                <div class="panel panel-default org-panel">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <span class="glyphicon glyphicon-home"></span>
                            <?php echo __('Сотрудники по организациям'); ?>
                            <span class="pull-right">
                                <button class="btn btn-xs btn-info" id="rc-expand-all" title="<?php echo __('Развернуть всё'); ?>">
                                    <span class="glyphicon glyphicon-plus-sign"></span>
                                </button>
                                <button class="btn btn-xs btn-warning" id="rc-collapse-all" title="<?php echo __('Свернуть всё'); ?>">
                                    <span class="glyphicon glyphicon-minus-sign"></span>
                                </button>
                            </span>
                        </h4>
                    </div>

                    <div class="panel-body rc-org-body rc-orgs-tree">
                        <div class="input-group input-group-sm rc-search">
                            <span class="input-group-addon"><span class="glyphicon glyphicon-search"></span></span>
                            <input type="text" class="form-control" id="rc-search"
                                   placeholder="<?php echo __('Поиск по ФИО, tabnum или организации...'); ?>"
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
                                    <span class="glyphicon glyphicon-user" style="font-size: 2.5em;"></span>
                                    <p><?php echo __('Сотрудники не найдены'); ?></p>
                                </div>
                            <?php else: ?>
                                <ul class="file-tree" id="rc-tree">
                                    <?php foreach ($tree as $node): ?>
                                        <?php echo View::factory('restmancard/people_tree_node', array(
                                            'node'  => $node,
                                            'level' => 0,
                                        )); ?>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div class="org-footer">
                            <div class="row">
                                <div class="col-xs-6">
                                    <span class="id-info">
                                        <span class="glyphicon glyphicon-user"></span>
                                        <?php echo __('Всего сотрудников'); ?>:
                                        <strong><?php echo (int) $stats['people']; ?></strong>
                                    </span>
                                </div>
                                <div class="col-xs-6 text-right">
                                    <span class="id-info">
                                        <span class="glyphicon glyphicon-home"></span>
                                        <?php echo __('Организаций'); ?>:
                                        <strong><?php echo (int) $stats['organizations']; ?></strong>
                                    </span>
                                </div>
                            </div>

                            <div class="row rc-footer-row">
                                <div class="col-xs-12">
                                    <span class="id-info" id="rc-selected-info">
                                        <span class="glyphicon glyphicon-ok-sign"></span>
                                        <span class="text-muted"><?php echo __('Выберите сотрудника'); ?></span>
                                    </span>
                                </div>
                            </div>

                            <?php if ( ! empty($stats['people_unmapped'])): ?>
                                <div class="row rc-footer-row">
                                    <div class="col-xs-12">
                                        <span class="text-warning rc-limit-note">
                                            <span class="glyphicon glyphicon-warning-sign"></span>
                                            <?php echo __('Организация не определена у сотрудников'); ?>: <?php echo (int) $stats['people_unmapped']; ?>.
                                            <?php echo __('Они показаны в организации из настройки organizations.people_root_divcode.'); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ( ! empty($stats['people_truncated'])): ?>
                                <div class="row rc-footer-row">
                                    <div class="col-xs-12">
                                        <span class="text-warning rc-limit-note">
                                            <span class="glyphicon glyphicon-warning-sign"></span>
                                            <?php echo __('Список сотрудников ограничен настройкой people.max_pages.'); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Панель 2: свойства сотрудника ===== -->
            <div class="col-md-4">
                <div class="panel panel-info props-panel rc-orgs-props">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <span class="glyphicon glyphicon-list-alt"></span>
                            <?php echo __('Свойства сотрудника'); ?>
                            <span class="label label-info" id="rc-person-selected-badge" style="display: none; font-size: 10px;"></span>
                        </h4>
                    </div>

                    <div class="panel-body" id="rc-person-placeholder">
                        <div class="text-center text-muted rc-placeholder">
                            <span class="glyphicon glyphicon-info-sign" style="font-size: 3em;"></span>
                            <p><?php echo __('Выберите сотрудника в дереве слева'); ?></p>
                        </div>
                    </div>

                    <form id="rc-person-form" class="rc-orgs-form" autocomplete="off" style="display: none;">
                        <div class="panel-body">
                            <div id="rc-person-alert" class="alert" style="display: none;">
                                <span class="glyphicon glyphicon-warning-sign"></span>
                                <span id="rc-person-alert-text"></span>
                            </div>

                            <div class="form-group rc-orgs-divcode-group">
                                <label><?php echo __('Табельный номер (tabnum)'); ?></label>
                                <p class="form-control-static rc-orgs-divcode" id="rc-person-tabnum">—</p>
                                <p class="help-block"><?php echo __('Табельный номер неизменяем: он служит ключом сотрудника в REST API.'); ?></p>
                            </div>

                            <div class="form-group">
                                <label for="rc-person-surname">
                                    <?php echo __('Фамилия'); ?> <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="rc-person-surname" name="surname"
                                       maxlength="50" placeholder="<?php echo __('Например: Иванов'); ?>">
                                <p class="help-block"><?php echo __('От 1 до 50 символов.'); ?></p>
                            </div>

                            <div class="form-group">
                                <label for="rc-person-name"><?php echo __('Имя'); ?></label>
                                <input type="text" class="form-control" id="rc-person-name" name="name"
                                       maxlength="50" placeholder="<?php echo __('Например: Пётр'); ?>">
                                <p class="help-block"><?php echo __('Необязательно, до 50 символов.'); ?></p>
                            </div>

                            <div class="form-group">
                                <label for="rc-person-patronymic"><?php echo __('Отчество'); ?></label>
                                <input type="text" class="form-control" id="rc-person-patronymic" name="patronymic"
                                       maxlength="50" placeholder="<?php echo __('Например: Сергеевич'); ?>">
                                <p class="help-block"><?php echo __('Необязательно, до 50 символов.'); ?></p>
                            </div>

                            <div class="form-group">
                                <label for="rc-person-org"><?php echo __('Организация (родительская)'); ?> <span class="text-danger">*</span></label>
                                <select class="form-control" id="rc-person-org" name="id_org">
                                    <option value=""><?php echo __('— Выберите организацию —'); ?></option>
                                </select>
                                <p class="help-block">
                                    <?php echo __('Организация, в которой числится сотрудник. Список из справочника организаций; в нём нет управления организациями.'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="panel-footer rc-orgs-form-footer">
                            <button type="submit" class="btn btn-primary" id="rc-person-save">
                                <span class="glyphicon glyphicon-floppy-disk"></span>
                                <span id="rc-person-save-text"><?php echo __('Сохранить'); ?></span>
                            </button>
                            <button type="button" class="btn btn-default" id="rc-person-cancel">
                                <span class="glyphicon glyphicon-repeat"></span> <?php echo __('Отменить изменения'); ?>
                            </button>
                            <span class="rc-orgs-dirty" id="rc-person-dirty"></span>
                        </div>
                    </form>
                </div>

                <p class="text-muted small rc-orgs-hint">
                    <span class="glyphicon glyphicon-time"></span>
                    <?php echo __('Загружено'); ?>: <?php echo HTML::chars($generated_at); ?>
                </p>
            </div>

            <!-- ===== Панель 3: набор категорий доступа ===== -->
            <div class="col-md-4">
                <div class="panel panel-warning props-panel rc-orgs-access-panel">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <span class="glyphicon glyphicon-lock"></span>
                            <?php echo __('Набор категорий доступа'); ?>
                            <span class="label label-info" id="rc-access-selected-badge" style="display: none; font-size: 10px;"></span>
                            <span class="badge pull-right" id="rc-person-access-count">0 / <?php echo count($access_categories); ?></span>
                        </h4>
                    </div>

                    <form id="rc-access-form" class="rc-orgs-form rc-orgs-access-form" autocomplete="off">
                        <div class="panel-body">
                            <div id="rc-access-alert" class="alert" style="display: none;">
                                <span class="glyphicon glyphicon-warning-sign"></span>
                                <span id="rc-access-alert-text"></span>
                            </div>

                            <div class="rc-orgs-access-note">
                                <span class="glyphicon glyphicon-info-sign"></span>
                                <?php echo __('Набор заменяется целиком: отмеченные категории будут выданы, неотмеченные — сняты.'); ?>
                            </div>

                            <div class="rc-orgs-access-toolbar">
                                <button type="button" class="btn btn-xs btn-default" id="rc-person-access-all"
                                        <?php echo ($access_editable AND ! empty($access_categories)) ? '' : 'disabled'; ?>>
                                    <?php echo __('Выбрать все'); ?>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="rc-person-access-none"
                                        <?php echo ($access_editable AND ! empty($access_categories)) ? '' : 'disabled'; ?>>
                                    <?php echo __('Снять все'); ?>
                                </button>
                            </div>

                            <div class="rc-orgs-access-status text-muted" id="rc-access-status">
                                <?php echo __('Выберите сотрудника в дереве слева'); ?>
                            </div>

                            <?php if ($access_error !== NULL): ?>
                                <div class="alert alert-danger rc-access-error">
                                    <span class="glyphicon glyphicon-warning-sign"></span>
                                    <strong><?php echo __('Категории доступа недоступны.'); ?></strong>
                                    <div class="rc-access-error-text"><?php echo HTML::chars($access_error); ?></div>
                                    <div class="small">
                                        <?php echo __('ФИО и организация сохраняются, набор категорий — нет.'); ?>
                                    </div>
                                </div>
                            <?php elseif (empty($access_categories)): ?>
                                <div class="text-muted rc-orgs-access-empty">
                                    <?php echo __('Категории доступа не найдены.'); ?>
                                </div>
                            <?php else: ?>
                                <div class="rc-orgs-access-list" id="rc-person-access-list">
                                    <?php foreach ($access_categories as $category): ?>
                                        <label class="rc-access-item"
                                               data-access-id="<?php echo (int) $category['ID']; ?>"
                                               title="ID_ACCESSNAME: <?php echo (int) $category['ID']; ?>">
                                            <input type="checkbox" class="rc-access-check"
                                                   value="<?php echo (int) $category['ID']; ?>"
                                                   data-access-id="<?php echo (int) $category['ID']; ?>"
                                                   disabled>
                                            <span class="rc-access-icon glyphicon glyphicon-lock"></span>
                                            <span class="rc-access-name"><?php echo HTML::chars($category['NAME']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($access_truncated): ?>
                                    <div class="text-warning rc-limit-note">
                                        <span class="glyphicon glyphicon-warning-sign"></span>
                                        <?php echo __('Список категорий доступа ограничен настройкой access.max_pages: часть категорий не показана.'); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="panel-footer rc-orgs-form-footer">
                            <button type="submit" class="btn btn-primary" id="rc-access-save" disabled>
                                <span class="glyphicon glyphicon-floppy-disk"></span>
                                <span id="rc-access-save-text"><?php echo __('Сохранить'); ?></span>
                            </button>
                            <button type="button" class="btn btn-default" id="rc-access-cancel" disabled>
                                <span class="glyphicon glyphicon-repeat"></span> <?php echo __('Отменить изменения'); ?>
                            </button>
                            <span class="rc-orgs-dirty" id="rc-access-dirty"></span>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.RESTMANCARD_PEOPLE = <?php echo $data_json; ?>;
</script>

<script>
window.RESTMANCARD_PEOPLE_CFG = <?php echo json_encode(array(
    'saveUrl'        => (string) $save_person_url,
    'saveAccessUrl'  => (string) $save_access_url,
    'accessUrl'      => (string) $access_url,
    'peopleUrl'      => (string) URL::site('restmancard/people'),
    'accessTotal'    => count($access_categories),
    'accessEditable' => (bool) $access_editable,
    'focus'          => (string) $focus_divcode,
    'person'         => (string) $focus_person,
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<script>
(function ($) {
    'use strict';

    var DATA = window.RESTMANCARD_PEOPLE || { people: {}, orgs: {}, meta: {} };
    var CFG = window.RESTMANCARD_PEOPLE_CFG || {};
    var saveUrl = CFG.saveUrl || '';
    var saveAccessUrl = CFG.saveAccessUrl || '';
    var accessUrl = CFG.accessUrl || '';
    var peopleUrl = CFG.peopleUrl || '';
    var accessTotal = parseInt(CFG.accessTotal, 10);
    if (isNaN(accessTotal) || accessTotal < 0) {
        accessTotal = 0;
    }
    var accessEditable = !!CFG.accessEditable;

    var $tree = $('#rc-tree');
    var $form = $('#rc-person-form');
    var $placeholder = $('#rc-person-placeholder');
    var $org = $('#rc-person-org');
    var $alert = $('#rc-person-alert');
    var $alertText = $('#rc-person-alert-text');
    var $saveBtn = $('#rc-person-save');
    var $saveText = $('#rc-person-save-text');
    var $accessList = $('#rc-person-access-list');
    var $accessCount = $('#rc-person-access-count');
    var $accessStatus = $('#rc-access-status');
    var $accessAlert = $('#rc-access-alert');
    var $accessAlertText = $('#rc-access-alert-text');
    var $accessSaveBtn = $('#rc-access-save');
    var $accessSaveText = $('#rc-access-save-text');

    var selectedUid = null;
    var accessRequest = 0;
    var accessLoaded = false;
    var accessAssigned = [];
    var accessUnknown = [];
    var searchTimer = null;
    var saveBusy = false;
    var accessBusy = false;
    var orgDirty = false;

    // ===== Вспомогательные функции =====

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function selectedPerson() {
        return selectedUid ? (DATA.people[selectedUid] || null) : null;
    }

    function showAlert(text, type) {
        var className = 'alert-danger';

        if (type === 'warning') {
            className = 'alert-warning';
        } else if (type === 'success') {
            className = 'alert-success';
        } else if (type === 'info') {
            className = 'alert-info';
        }

        $alert
            .removeClass('alert-danger alert-warning alert-success alert-info')
            .addClass(className)
            .show();
        $alertText.text(text || '');
    }

    function hideAlert() {
        $alert.removeClass('alert-danger alert-warning alert-success alert-info').hide();
        $alertText.text('');
    }

    function showAccessAlert(text, type) {
        var className = 'alert-danger';

        if (type === 'warning') {
            className = 'alert-warning';
        } else if (type === 'success') {
            className = 'alert-success';
        } else if (type === 'info') {
            className = 'alert-info';
        }

        $accessAlert
            .removeClass('alert-danger alert-warning alert-success alert-info')
            .addClass(className)
            .show();
        $accessAlertText.text(text || '');
    }

    function hideAccessAlert() {
        $accessAlert.removeClass('alert-danger alert-warning alert-success alert-info').hide();
        $accessAlertText.text('');
    }

    function clearErrors() {
        $form.find('.form-group').removeClass('has-error');
    }

    function markField(field) {
        var $field = $form.find('[name="' + field + '"]');

        if ($field.length) {
            $field.closest('.form-group').addClass('has-error');
        } else if (field === 'id_org') {
            $org.closest('.form-group').addClass('has-error');
        }
    }

    function showFieldErrors(errors) {
        if (!errors) {
            return;
        }

        for (var field in errors) {
            if (Object.prototype.hasOwnProperty.call(errors, field)) {
                markField(field);
            }
        }
    }

    function setSaveBusy(busy) {
        saveBusy = busy;
        $form.find('input, select').prop('disabled', busy);
        $saveBtn.prop('disabled', busy);
        $('#rc-person-cancel').prop('disabled', busy);
        $saveText.text(busy ? '<?php echo __('Сохранение...'); ?>' : '<?php echo __('Сохранить'); ?>');
    }

    function markDirty() {
        $('#rc-person-dirty').text('<?php echo __('Изменения не сохранены'); ?>');
    }

    function clearDirty() {
        $('#rc-person-dirty').text('');
    }

    function markAccessDirty() {
        $('#rc-access-dirty').text('<?php echo __('Изменения не сохранены'); ?>');
    }

    function clearAccessDirty() {
        $('#rc-access-dirty').text('');
    }

    function setSelectedInfo(person) {
        $('#rc-selected-info').html(
            '<span class="glyphicon glyphicon-ok-sign"></span> '
            + esc(person.full_name || person.tabnum)
            + ' <span class="text-muted">[' + esc(person.tabnum) + ']</span>'
        );
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
        // Во время сохранения выделение не меняем: ответ AJAX перезагрузит
        // страницу с сотрудником, которого сохраняли.
        if (saveBusy || accessBusy) {
            return;
        }

        if ($node.attr('data-type') !== 'person') {
            return;
        }

        $tree.find('.tree-item.rc-selected').removeClass('rc-selected');
        $node.children('.tree-item').first().addClass('rc-selected');
        showPerson($node.attr('data-uid'));
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

            if ($node.attr('data-type') === 'person') {
                selectNode($node);
            } else {
                // Организация — только группа дерева: клик раскрывает или
                // сворачивает ветку, свойства сотрудника не меняются.
                toggleNode($node);
            }
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

    // ===== Панель свойств =====

    // Список организаций строится из загруженного дерева: управлять
    // организациями в этой форме нельзя, нужен только выбор.
    function fillOrgs(person) {
        var list = [];

        $.each(DATA.orgs, function (uid, item) {
            if (item && item.id > 0) {
                list.push(item);
            }
        });

        list.sort(function (a, b) {
            return String(a.path || a.name || '').localeCompare(String(b.path || b.name || ''));
        });

        var html = '<option value="">' + <?php echo json_encode(__('— Выберите организацию —')); ?> + '</option>';
        var current = String(person.org_id || '');
        var found = false;

        for (var i = 0; i < list.length; i++) {
            var id = String(list[i].id);

            html += '<option value="' + esc(id) + '">'
                + esc(list[i].path || list[i].name) + '</option>';

            if (id === current) {
                found = true;
            }
        }

        // Организации может не быть в загруженном дереве (например, список
        // организаций обрезан) — показываем её отдельным вариантом.
        if (current !== '' && !found) {
            html += '<option value="' + esc(current) + '">'
                + esc((person.org_name || '') + ' [' + (person.org_divcode || '') + ']')
                + ' (' + <?php echo json_encode(__('нет в дереве')); ?> + ')</option>';
        }

        $org.html(html);

        if (current !== '') {
            $org.val(current);
        }
    }

    function fillProperties(person) {
        $('#rc-person-tabnum').text(person.tabnum || '—');
        $('#rc-person-surname').val(person.surname || '');
        $('#rc-person-name').val(person.name || '');
        $('#rc-person-patronymic').val(person.patronymic || '');
        $('#rc-person-selected-badge').text(person.tabnum || '').show();

        fillOrgs(person);

        orgDirty = false;
        setSelectedInfo(person);
        hideAlert();
        clearErrors();
        clearDirty();
    }

    function showPerson(uid) {
        var person = uid ? DATA.people[uid] : null;

        if (!person) {
            return;
        }

        selectedUid = uid;

        $placeholder.hide();
        $form.show();

        setSaveBusy(false);
        fillProperties(person);
        loadAccess(person);
    }

    // ===== Категории доступа =====

    function applyAccessAssigned() {
        var assigned = {};

        for (var i = 0; i < accessAssigned.length; i++) {
            assigned[String(accessAssigned[i])] = true;
        }

        accessUnknown = [];

        $accessList.find('input.rc-access-check').each(function () {
            var $check = $(this);
            var id = String($check.val());

            if (Object.prototype.hasOwnProperty.call(assigned, id)) {
                $check.prop('checked', true);
                assigned[id] = false;
            } else {
                $check.prop('checked', false);
            }
        });

        // Категории, выданные сотруднику, но отсутствующие в справочнике:
        // сохраняем их в наборе, иначе полная замена PUT сняла бы их.
        for (var key in assigned) {
            if (Object.prototype.hasOwnProperty.call(assigned, key) && assigned[key]) {
                accessUnknown.push(Number(key));
            }
        }

        updateAccessCount();
    }

    function checkedAccessIds() {
        var ids = [];
        var seen = {};

        $accessList.find('input.rc-access-check:checked').each(function () {
            var id = Number($(this).val());

            if (isFinite(id) && !seen[String(id)]) {
                seen[String(id)] = true;
                ids.push(id);
            }
        });

        for (var i = 0; i < accessUnknown.length; i++) {
            var extra = Number(accessUnknown[i]);

            if (isFinite(extra) && !seen[String(extra)]) {
                seen[String(extra)] = true;
                ids.push(extra);
            }
        }

        ids.sort(function (a, b) {
            return a - b;
        });

        return ids;
    }

    function updateAccessCount() {
        var checked = 0;

        $accessList.find('input.rc-access-check').each(function () {
            var $check = $(this);
            var on = $check.prop('checked');

            $check.closest('.rc-access-item').toggleClass('rc-access-checked', on);

            if (on) {
                checked++;
            }
        });

        var text = checked + ' / ' + accessTotal;

        if (accessUnknown.length) {
            text += ' (+' + accessUnknown.length + ')';
        }

        $accessCount.text(text);
    }

    function setAccessStatus(text, type) {
        if (!text) {
            $accessStatus.hide().empty();
            return;
        }

        $accessStatus
            .removeClass('text-success text-warning text-danger text-muted')
            .addClass('text-' + (type || 'muted'))
            .html(text)
            .show();
    }

    function setAccessControlsEnabled(enabled) {
        $accessList.find('input.rc-access-check').prop('disabled', !enabled);
        $('#rc-person-access-all').prop('disabled', !enabled);
        $('#rc-person-access-none').prop('disabled', !enabled);
        $accessSaveBtn.prop('disabled', !enabled);
        $('#rc-access-cancel').prop('disabled', !enabled);
    }

    function setAccessBusy(busy) {
        accessBusy = busy;
        $accessSaveBtn.prop('disabled', busy);
        $accessSaveText.text(busy ? '<?php echo __('Сохранение...'); ?>' : '<?php echo __('Сохранить'); ?>');
    }

    function loadAccess(person) {
        var seq = ++accessRequest;

        accessLoaded = false;
        accessAssigned = [];
        accessUnknown = [];

        $accessList.find('input.rc-access-check').prop('checked', false);
        updateAccessCount();
        setAccessStatus('');
        hideAccessAlert();
        clearAccessDirty();
        $('#rc-access-selected-badge').text(person.tabnum || '').show();

        if (!accessEditable || !accessUrl || !$accessList.length) {
            setAccessControlsEnabled(false);
            setAccessStatus('');
            return;
        }

        setAccessControlsEnabled(false);
        setAccessStatus(<?php echo json_encode(__('Загрузка категорий доступа...')); ?>, 'muted');

        $.ajax({
            url: accessUrl,
            type: 'POST',
            data: {
                type: 'person',
                tabnum: person.tabnum,
                divcode: person.org_divcode || ''
            },
            dataType: 'json'
        }).done(function (response) {
            if (seq !== accessRequest) {
                return;
            }

            if (!response || !response.success) {
                setAccessControlsEnabled(false);
                setAccessStatus(esc((response && response.message)
                    || <?php echo json_encode(__('Не удалось загрузить категории доступа')); ?>), 'danger');
                return;
            }

            accessLoaded = true;
            accessAssigned = response.assigned || [];

            applyAccessAssigned();
            setAccessControlsEnabled(true);

            if (accessUnknown.length) {
                var shown = [];

                for (var i = 0; i < accessUnknown.length && i < 10; i++) {
                    shown.push('#' + accessUnknown[i]);
                }

                setAccessStatus(
                    esc(<?php echo json_encode(__('В наборе есть категории, которых нет в справочнике')); ?>)
                    + ': ' + esc(shown.join(', '))
                    + (accessUnknown.length > shown.length ? ' …' : '')
                    + '. ' + esc(<?php echo json_encode(__('Они сохранятся без изменений.')); ?>),
                    'warning'
                );
            } else {
                setAccessStatus('');
            }
        }).fail(function (xhr) {
            if (seq !== accessRequest) {
                return;
            }

            var message = <?php echo json_encode(__('Не удалось загрузить категории доступа')); ?>;

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            } else if (xhr && xhr.status) {
                message += ' (HTTP ' + xhr.status + ')';
            }

            setAccessStatus(esc(message), 'danger');
            setAccessControlsEnabled(false);
        });
    }

    $accessList.on('change', 'input.rc-access-check', function () {
        updateAccessCount();
        markAccessDirty();
    });

    $('#rc-person-access-all').on('click', function () {
        if (!accessEditable || !accessLoaded) {
            return;
        }

        $accessList.find('input.rc-access-check').prop('checked', true);
        updateAccessCount();
        markAccessDirty();
    });

    $('#rc-person-access-none').on('click', function () {
        if (!accessEditable || !accessLoaded) {
            return;
        }

        $accessList.find('input.rc-access-check').prop('checked', false);
        updateAccessCount();
        markAccessDirty();
    });

    $('#rc-access-cancel').on('click', function () {
        if (!accessLoaded) {
            return;
        }

        applyAccessAssigned();
        hideAccessAlert();
        setAccessStatus('');
        clearAccessDirty();
    });

    // ===== Сохранение свойств сотрудника =====

    function savePerson() {
        if (saveBusy) {
            return;
        }

        var person = selectedPerson();

        if (!person) {
            return;
        }

        hideAlert();
        clearErrors();

        var surname = $.trim($('#rc-person-surname').val());
        var name = $.trim($('#rc-person-name').val());
        var patronymic = $.trim($('#rc-person-patronymic').val());

        if (surname === '' || surname.length > 50) {
            markField('surname');
            showAlert(<?php echo json_encode(__('Проверьте заполнение полей')); ?>, 'error');
            return;
        }

        if (name.length > 50 || patronymic.length > 50) {
            markField(name.length > 50 ? 'name' : 'patronymic');
            showAlert(<?php echo json_encode(__('Проверьте заполнение полей')); ?>, 'error');
            return;
        }

        if (orgDirty && ($org.val() || '') === '') {
            markField('id_org');
            showAlert(<?php echo json_encode(__('Выберите организацию сотрудника')); ?>, 'error');
            return;
        }

        // ФИО сохраняется всегда, организация — только если её меняли:
        // лишний PATCH создаёт запись в журнале PEOPLE.
        var payload = {
            tabnum: person.tabnum,
            surname: surname,
            name: name,
            patronymic: patronymic
        };

        if (orgDirty) {
            payload.id_org = $org.val();
        }

        setSaveBusy(true);

        $.ajax({
            url: saveUrl,
            type: 'POST',
            data: payload,
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.success) {
                setSaveBusy(false);
                showFieldErrors(response && response.errors);
                showAlert((response && response.message)
                    || <?php echo json_encode(__('Не удалось сохранить сотрудника')); ?>, 'error');
                return;
            }

            $saveText.text('<?php echo __('Готово'); ?>');
            showAlert(response.message
                || <?php echo json_encode(__('Свойства сотрудника сохранены')); ?>, 'success');
            clearDirty();

            var target = (response && response.tabnum) ? response.tabnum : person.tabnum;
            var focus = (response && response.organization && response.organization.divcode)
                ? response.organization.divcode
                : (person.org_divcode || '');

            window.setTimeout(function () {
                window.location.href = peopleUrl + '?refresh=1&person=' + encodeURIComponent(target)
                    + (focus !== '' ? '&focus=' + encodeURIComponent(focus) : '');
            }, 700);
        }).fail(function (xhr) {
            setSaveBusy(false);

            var message = <?php echo json_encode(__('Не удалось сохранить сотрудника')); ?>;

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            } else if (xhr && xhr.status) {
                message += ' (HTTP ' + xhr.status + ')';
            }

            showAlert(message, 'error');
        });
    }

    $form.on('submit', function (event) {
        event.preventDefault();
        savePerson();
    });

    $('#rc-person-cancel').on('click', function () {
        var person = selectedPerson();

        if (person) {
            // Отменяем только правку свойств: набор категорий доступа
            // остаётся в том состоянии, в котором его открыли.
            fillProperties(person);
        }
    });

    $form.on('input change', 'input', function () {
        markDirty();
    });

    $org.on('change', function () {
        orgDirty = true;
        markDirty();
    });

    // ===== Сохранение набора категорий доступа =====

    function saveAccessSet() {
        if (accessBusy || !accessEditable || !accessLoaded) {
            return;
        }

        var person = selectedPerson();

        if (!person) {
            return;
        }

        hideAccessAlert();
        setAccessBusy(true);
        setAccessControlsEnabled(false);

        $.ajax({
            url: saveAccessUrl,
            type: 'POST',
            data: {
                tabnum: person.tabnum,
                access: checkedAccessIds()
            },
            dataType: 'json'
        }).done(function (response) {
            setAccessBusy(false);

            if (!response || !response.success) {
                setAccessControlsEnabled(true);
                showAccessAlert((response && response.message)
                    || <?php echo json_encode(__('Не удалось сохранить набор категорий доступа')); ?>, 'danger');
                return;
            }

            accessAssigned = response.assigned || [];
            applyAccessAssigned();
            setAccessControlsEnabled(true);
            clearAccessDirty();
            showAccessAlert(response.message
                || <?php echo json_encode(__('Набор категорий доступа сохранён')); ?>, 'success');

            if (accessUnknown.length) {
                setAccessStatus(
                    esc(<?php echo json_encode(__('В наборе есть категории, которых нет в справочнике')); ?>)
                    + ': +' + accessUnknown.length + '. '
                    + esc(<?php echo json_encode(__('Они сохранены без изменений.')); ?>),
                    'warning'
                );
            } else {
                setAccessStatus(
                    esc(<?php echo json_encode(__('Набор сохранён. Категорий')); ?>)
                    + ': ' + accessAssigned.length + '.',
                    'success'
                );
            }
        }).fail(function (xhr) {
            setAccessBusy(false);
            setAccessControlsEnabled(true);

            var message = <?php echo json_encode(__('Не удалось сохранить набор категорий доступа')); ?>;

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            } else if (xhr && xhr.status) {
                message += ' (HTTP ' + xhr.status + ')';
            }

            showAccessAlert(message, 'danger');
        });
    }

    $('#rc-access-form').on('submit', function (event) {
        event.preventDefault();
        saveAccessSet();
    });

    // ===== Сотрудник, изменённый последним =====

    function findPersonNode(tabnum) {
        var needle = String(tabnum || '');
        var $found = $();

        if (needle === '') {
            return $found;
        }

        $tree.find('li.tree-node[data-type="person"]').each(function () {
            if (String($(this).attr('data-tabnum')) === needle) {
                $found = $(this);
                return false;
            }
        });

        return $found;
    }

    if ($tree.length && CFG.person) {
        var $personNode = findPersonNode(CFG.person);

        if ($personNode.length) {
            $personNode.parents('li.tree-node').each(function () {
                toggleNode($(this), true);
            });

            selectNode($personNode);

            var personItem = $personNode.children('.tree-item').get(0);
            if (personItem && typeof personItem.scrollIntoView === 'function') {
                personItem.scrollIntoView(false);
            }
        }
    } else if ($tree.length && CFG.focus) {
        var $focusNode = $tree.find('li.tree-node[data-type="org"]').filter(function () {
            return String($(this).attr('data-divcode')) === CFG.focus;
        }).first();

        if ($focusNode.length) {
            $focusNode.parents('li.tree-node').each(function () {
                toggleNode($(this), true);
            });

            toggleNode($focusNode, true);

            var focusItem = $focusNode.children('.tree-item').get(0);
            if (focusItem && typeof focusItem.scrollIntoView === 'function') {
                focusItem.scrollIntoView(false);
            }
        }
    }

    // ===== Первый выбор =====
    // Ничего не выбираем автоматически: пользователь начинает с подсказки.
})(jQuery);
</script>
