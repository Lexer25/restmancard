<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Отдельная форма для работы с организациями (пункт меню «Организации»).
 *
 * Форма состоит из трёх независимых панелей:
 *   1) дерево организаций;
 *   2) свойства выбранной организации (название, родитель, флаг);
 *   3) набор категорий доступа выбранной организации.
 *
 * Кнопка «Сохранить» панели свойств вызывает restmancard/save_organization
 * и меняет только свойства (PUT /api/v2/organizations/{divcode}). Кнопка
 * «Сохранить» панели категорий вызывает restmancard/save_access и меняет
 * только набор категорий (PUT /api/v2/organizations/{divcode}/accessnames).
 * Если набор изменён, перед сохранением открывается окно выбора области
 * применения: только организация или ещё дочерние организации и сотрудники
 * (scope=org|cascade).
 *
 * @var array  $tree
 * @var string $error
 * @var array  $warnings
 * @var array  $stats
 * @var string $data_json
 * @var string $api_url
 * @var string $generated_at
 * @var string $refresh_url
 * @var string $save_url
 * @var string $save_access_url
 * @var string $access_url
 * @var string $focus_divcode
 * @var array  $access_categories
 * @var string|NULL $access_error
 * @var bool   $access_truncated
 * @var bool   $access_editable
 */
?>
<?php echo HTML::style('static/css/restmancard.css?v=2.2.0&layout=3col'); ?>

<div class="panel panel-primary rc-root rc-orgs">
    <div class="panel-heading">
        <h3 class="panel-title">
            <span class="glyphicon glyphicon-building"></span>
            <?php echo __('Организации'); ?>
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
            <!-- ===== Панель 1: дерево организаций ===== -->
            <div class="col-md-4">
                <div class="panel panel-default org-panel">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <span class="glyphicon glyphicon-home"></span>
                            <?php echo __('Организации'); ?>
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
                                   placeholder="<?php echo __('Поиск организаций...'); ?>"
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
                                        <?php echo View::factory('restmancard/org_tree_node', array(
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
                                        <span class="glyphicon glyphicon-hdd"></span>
                                        <?php echo __('Всего организаций'); ?>:
                                        <strong><?php echo (int) $stats['organizations']; ?></strong>
                                    </span>
                                </div>
                                <div class="col-xs-6 text-right">
                                    <span class="id-info">
                                        <span class="glyphicon glyphicon-tree-conifer"></span>
                                        <?php echo __('Корневых'); ?>:
                                        <strong><?php echo (int) $stats['roots']; ?></strong>
                                    </span>
                                </div>
                            </div>

                            <div class="row rc-footer-row">
                                <div class="col-xs-12">
                                    <span class="id-info" id="rc-selected-info">
                                        <span class="glyphicon glyphicon-ok-sign"></span>
                                        <span class="text-muted"><?php echo __('Выберите организацию'); ?></span>
                                    </span>
                                </div>
                            </div>

                            <?php if ( ! empty($stats['truncated_orgs'])): ?>
                                <div class="row rc-footer-row">
                                    <div class="col-xs-12">
                                        <span class="text-warning rc-limit-note">
                                            <span class="glyphicon glyphicon-warning-sign"></span>
                                            <?php echo __('Список организаций ограничен настройкой organizations.max_pages.'); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Панель 2: свойства организации ===== -->
            <div class="col-md-4">
                <div class="panel panel-info props-panel rc-orgs-props">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <span class="glyphicon glyphicon-list-alt"></span>
                            <?php echo __('Свойства организации'); ?>
                            <span class="label label-info" id="rc-org-selected-badge" style="display: none; font-size: 10px;"></span>
                        </h4>
                    </div>

                    <div class="panel-body" id="rc-org-placeholder">
                        <div class="text-center text-muted rc-placeholder">
                            <span class="glyphicon glyphicon-info-sign" style="font-size: 3em;"></span>
                            <p><?php echo __('Выберите организацию в дереве слева'); ?></p>
                        </div>
                    </div>

                    <form id="rc-org-form" class="rc-orgs-form" autocomplete="off" style="display: none;">
                        <div class="panel-body">
                            <div id="rc-org-alert" class="alert" style="display: none;">
                                <span class="glyphicon glyphicon-warning-sign"></span>
                                <span id="rc-org-alert-text"></span>
                            </div>

                            <div class="form-group">
                                <label for="rc-org-name">
                                    <?php echo __('Название организации'); ?> <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="rc-org-name" name="name"
                                       maxlength="50" placeholder="<?php echo __('Например: Отдел продаж'); ?>">
                                <p class="help-block"><?php echo __('От 1 до 50 символов.'); ?></p>
                            </div>

                            <div class="form-group rc-orgs-divcode-group">
                                <label><?php echo __('Код организации (divcode)'); ?></label>
                                <p class="form-control-static rc-orgs-divcode" id="rc-org-divcode">—</p>
                                <p class="help-block"><?php echo __('Код неизменяем: REST API отклоняет его изменение.'); ?></p>
                            </div>

                            <div class="form-group">
                                <label for="rc-org-parent"><?php echo __('Родительская организация'); ?></label>
                                <select class="form-control" id="rc-org-parent" name="parent">
                                    <option value=""><?php echo __('— Корневая организация —'); ?></option>
                                </select>
                                <p class="help-block">
                                    <?php echo __('Пусто — организация будет в корне главной организации. Саму организацию и её подразделения выбрать нельзя.'); ?>
                                </p>
                            </div>

                            <div class="form-group">
                                <label for="rc-org-flag"><?php echo __('Флаг'); ?></label>
                                <select class="form-control" id="rc-org-flag" name="flag">
                                    <option value="0"><?php echo __('0 — обычная'); ?></option>
                                    <option value="1"><?php echo __('1 — гостевая'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="panel-footer rc-orgs-form-footer">
                            <button type="submit" class="btn btn-primary" id="rc-org-save">
                                <span class="glyphicon glyphicon-floppy-disk"></span>
                                <span id="rc-org-save-text"><?php echo __('Сохранить'); ?></span>
                            </button>
                            <button type="button" class="btn btn-default" id="rc-org-cancel">
                                <span class="glyphicon glyphicon-repeat"></span> <?php echo __('Отменить изменения'); ?>
                            </button>
                            <span class="rc-orgs-dirty" id="rc-org-dirty"></span>
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
                            <span class="badge pull-right" id="rc-org-access-count">0 / <?php echo count($access_categories); ?></span>
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
                                <button type="button" class="btn btn-xs btn-default" id="rc-org-access-all"
                                        <?php echo ($access_editable AND ! empty($access_categories)) ? '' : 'disabled'; ?>>
                                    <?php echo __('Выбрать все'); ?>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="rc-org-access-none"
                                        <?php echo ($access_editable AND ! empty($access_categories)) ? '' : 'disabled'; ?>>
                                    <?php echo __('Снять все'); ?>
                                </button>
                            </div>

                            <div class="rc-orgs-access-status text-muted" id="rc-org-access-status">
                                <?php echo __('Выберите организацию в дереве слева'); ?>
                            </div>

                            <?php if ($access_error !== NULL): ?>
                                <div class="alert alert-danger rc-access-error">
                                    <span class="glyphicon glyphicon-warning-sign"></span>
                                    <strong><?php echo __('Категории доступа недоступны.'); ?></strong>
                                    <div class="rc-access-error-text"><?php echo HTML::chars($access_error); ?></div>
                                    <div class="small">
                                        <?php echo __('Свойства организации сохраняются, набор категорий — нет.'); ?>
                                    </div>
                                </div>
                            <?php elseif (empty($access_categories)): ?>
                                <div class="text-muted rc-orgs-access-empty">
                                    <?php echo __('Категории доступа не найдены.'); ?>
                                </div>
                            <?php else: ?>
                                <div class="rc-orgs-access-list" id="rc-org-access-list">
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

<!-- ===== Модальное окно: как применить изменения категорий ===== -->
<div class="modal fade" id="rc-access-scope-modal" tabindex="-1" role="dialog" aria-labelledby="rc-access-scope-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="rc-access-scope-title">
                    <span class="glyphicon glyphicon-random"></span>
                    <?php echo __('Как применить изменения?'); ?>
                </h4>
            </div>

            <div class="modal-body">
                <p class="rc-access-scope-intro">
                    <?php echo __('Набор категорий доступа организации изменён:'); ?>
                    <strong id="rc-access-scope-org">—</strong>
                </p>

                <div class="rc-access-scope-diff" id="rc-access-scope-diff"></div>

                <div class="rc-access-scope-option">
                    <label for="rc-access-scope-cascade">
                        <input type="radio" name="rc-access-scope" id="rc-access-scope-cascade"
                               value="cascade">
                        <strong><?php echo __('Применить к группе и сотрудникам, входящим в организацию'); ?></strong>
                    </label>
                    <p class="help-block" id="rc-access-scope-cascade-hint">
                        <?php echo __('Добавить или снять категории у всех дочерних организаций и у сотрудников, входящих в выбранную организацию. Наборы сотрудников дочерних организаций не меняются.'); ?>
                    </p>
                </div>

                <div class="rc-access-scope-option">
                    <label for="rc-access-scope-org-only">
                        <input type="radio" name="rc-access-scope" id="rc-access-scope-org-only"
                               value="org">
                        <strong><?php echo __('Применить изменения только к организации'); ?></strong>
                    </label>
                    <p class="help-block">
                        <?php echo __('Изменить набор только у выбранной организации. Дочерние организации и сотрудники останутся без изменений.'); ?>
                    </p>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">
                    <?php echo __('Отмена'); ?>
                </button>
                <button type="button" class="btn btn-primary" id="rc-access-scope-apply" disabled>
                    <span class="glyphicon glyphicon-ok"></span>
                    <?php echo __('Применить'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.RESTMANCARD_ORGS = <?php echo $data_json; ?>;
</script>

<script>
window.RESTMANCARD_ORGS_CFG = <?php echo json_encode(array(
    'saveUrl'        => (string) $save_url,
    'saveAccessUrl'  => (string) $save_access_url,
    'accessUrl'      => (string) $access_url,
    'orgsUrl'        => (string) URL::site('restmancard/organizations'),
    'accessTotal'    => count($access_categories),
    'accessEditable' => (bool) $access_editable,
    'focus'          => (string) $focus_divcode,
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<script>
(function ($) {
    'use strict';

    var DATA = window.RESTMANCARD_ORGS || { orgs: {}, meta: {} };
    var CFG = window.RESTMANCARD_ORGS_CFG || {};
    var saveUrl = CFG.saveUrl || '';
    var saveAccessUrl = CFG.saveAccessUrl || '';
    var accessUrl = CFG.accessUrl || '';
    var orgsUrl = CFG.orgsUrl || '';
    var focusDivcode = CFG.focus || '';
    var accessTotal = parseInt(CFG.accessTotal, 10);
    if (isNaN(accessTotal) || accessTotal < 0) {
        accessTotal = 0;
    }
    var accessEditable = !!CFG.accessEditable;

    var $tree = $('#rc-tree');
    var $form = $('#rc-org-form');
    var $placeholder = $('#rc-org-placeholder');
    var $parent = $('#rc-org-parent');
    var $alert = $('#rc-org-alert');
    var $alertText = $('#rc-org-alert-text');
    var $saveBtn = $('#rc-org-save');
    var $saveText = $('#rc-org-save-text');
    var $accessList = $('#rc-org-access-list');
    var $accessCount = $('#rc-org-access-count');
    var $accessStatus = $('#rc-org-access-status');
    var $accessAlert = $('#rc-access-alert');
    var $accessAlertText = $('#rc-access-alert-text');
    var $accessSaveBtn = $('#rc-access-save');
    var $accessSaveText = $('#rc-access-save-text');
    var $scopeModal = $('#rc-access-scope-modal');
    var $scopeOrg = $('#rc-access-scope-org');
    var $scopeDiff = $('#rc-access-scope-diff');
    var $scopeCascadeHint = $('#rc-access-scope-cascade-hint');

    var selectedUid = null;
    var accessRequest = 0;
    var accessLoaded = false;
    var accessAssigned = [];
    var accessUnknown = [];
    var searchTimer = null;
    var saveBusy = false;
    var accessBusy = false;
    var pendingAccess = null;
    var scopeSubmitting = false;

    // ===== Вспомогательные функции =====

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function selectedOrg() {
        return selectedUid ? (DATA.orgs[selectedUid] || null) : null;
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
        $form.find('[name="' + field + '"]').closest('.form-group').addClass('has-error');
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
        $('#rc-org-cancel').prop('disabled', busy);
        $saveText.text(busy ? '<?php echo __('Сохранение...'); ?>' : '<?php echo __('Сохранить'); ?>');
    }

    function markDirty() {
        $('#rc-org-dirty').text('<?php echo __('Изменения не сохранены'); ?>');
    }

    function clearDirty() {
        $('#rc-org-dirty').text('');
    }

    function markAccessDirty() {
        $('#rc-access-dirty').text('<?php echo __('Изменения не сохранены'); ?>');
    }

    function clearAccessDirty() {
        $('#rc-access-dirty').text('');
    }

    function setSelectedInfo(org) {
        $('#rc-selected-info').html(
            '<span class="glyphicon glyphicon-ok-sign"></span> '
            + esc(org.name) + ' <span class="text-muted">[' + esc(org.divcode) + ']</span>'
        );
    }

    // ===== Дерево организаций =====

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
        // страницу с организацией, которую сохраняли.
        if (saveBusy || accessBusy) {
            return;
        }

        $tree.find('.tree-item.rc-selected').removeClass('rc-selected');
        $node.children('.tree-item').first().addClass('rc-selected');
        showOrg($node.attr('data-uid'));
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
            toggleNode($node, true);
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

    // ===== Список вариантов родительской организации =====

    // Коды, которые нельзя выбрать родителем: сама организация и все её
    // потомки (иначе в дереве получится кольцо). Сервер проверяет это ещё раз.
    function forbiddenParentCodes(divcode) {
        var result = {};
        var needle = String(divcode || '').toLowerCase();

        if (needle === '') {
            return result;
        }

        result[needle] = true;

        var children = {};

        $.each(DATA.orgs, function (uid, org) {
            if (!org || !org.divcode || !org.parent) {
                return;
            }

            var parent = String(org.parent).toLowerCase();

            if (!children[parent]) {
                children[parent] = [];
            }

            children[parent].push(String(org.divcode));
        });

        var queue = children[needle] ? children[needle].slice() : [];
        var guard = 0;

        while (queue.length && guard < 10000) {
            guard++;

            var code = queue.shift();
            var key = code.toLowerCase();

            if (result[key]) {
                continue;
            }

            result[key] = true;

            if (children[key]) {
                for (var i = 0; i < children[key].length; i++) {
                    queue.push(children[key][i]);
                }
            }
        }

        return result;
    }

    function fillParents(org) {
        var forbidden = forbiddenParentCodes(org.divcode);
        var list = [];
        var parent = org.parent ? String(org.parent) : '';

        $.each(DATA.orgs, function (uid, item) {
            if (!item || !item.divcode) {
                return;
            }

            if (forbidden[String(item.divcode).toLowerCase()]) {
                return;
            }

            list.push(item);
        });

        list.sort(function (a, b) {
            return String(a.path || a.name || '').localeCompare(String(b.path || b.name || ''));
        });

        var html = '<option value="">' + <?php echo json_encode(__('— Корневая организация —')); ?> + '</option>';
        var hasParent = false;

        for (var i = 0; i < list.length; i++) {
            var code = String(list[i].divcode);

            html += '<option value="' + esc(code) + '">'
                + esc(list[i].path || list[i].name) + '</option>';

            if (parent !== '' && code.toLowerCase() === parent.toLowerCase()) {
                hasParent = true;
            }
        }

        // Текущего родителя может не быть в загруженном дереве
        if (parent !== '' && !hasParent) {
            html += '<option value="' + esc(parent) + '">'
                + esc(parent) + ' (' + <?php echo json_encode(__('нет в дереве')); ?> + ')</option>';
        }

        $parent.html(html);
    }

    // ===== Панель свойств =====

    function fillProperties(org) {
        $('#rc-org-name').val(org.name || '');
        $('#rc-org-divcode').text(org.divcode || '—');
        $('#rc-org-flag').val(String(org.flag === 1 ? 1 : 0));
        $('#rc-org-selected-badge').text(org.divcode || '').show();

        fillParents(org);
        $parent.val(org.parent ? String(org.parent) : '');

        setSelectedInfo(org);
        hideAlert();
        clearErrors();
        clearDirty();
    }

    function showOrg(uid) {
        var org = uid ? DATA.orgs[uid] : null;

        if (!org) {
            return;
        }

        selectedUid = uid;

        $placeholder.hide();
        $form.show();

        setSaveBusy(false);
        fillProperties(org);
        loadAccess(org);
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

        // Категории, выданные организации, но отсутствующие в справочнике:
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

        // Подсветка строки повторяет состояние флажка: чекбокс — единственный
        // орган управления, а строка целиком остаётся кликабельной.
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
        $('#rc-org-access-all').prop('disabled', !enabled);
        $('#rc-org-access-none').prop('disabled', !enabled);
        $accessSaveBtn.prop('disabled', !enabled);
        $('#rc-access-cancel').prop('disabled', !enabled);
    }

    function setAccessBusy(busy) {
        accessBusy = busy;
        $accessSaveBtn.prop('disabled', busy);
        $accessSaveText.text(busy ? '<?php echo __('Сохранение...'); ?>' : '<?php echo __('Сохранить'); ?>');
    }

    // ===== Выбор области применения изменений =====

    // Название категории по её идентификатору: строка списка флажков.
    function categoryName(id) {
        var key = String(id);
        var name = '';
        var $input = $accessList.find('input.rc-access-check').filter(function () {
            return String($(this).val()) === key;
        }).first();

        if ($input.length) {
            name = $.trim($input.closest('.rc-access-item').find('.rc-access-name').text());
        }

        return name !== '' ? name : ('ID ' + key);
    }

    // Разница «было / стало» для набора, который отправляется в API.
    function diffIds(oldIds, newIds) {
        var oldMap = {};
        var newMap = {};
        var added = [];
        var removed = [];
        var i;

        for (i = 0; i < oldIds.length; i++) {
            oldMap[String(oldIds[i])] = true;
        }

        for (i = 0; i < newIds.length; i++) {
            newMap[String(newIds[i])] = true;
        }

        for (i = 0; i < newIds.length; i++) {
            if (!oldMap[String(newIds[i])]) {
                added.push(Number(newIds[i]));
            }
        }

        for (i = 0; i < oldIds.length; i++) {
            if (!newMap[String(oldIds[i])]) {
                removed.push(Number(oldIds[i]));
            }
        }

        added.sort(function (a, b) { return a - b; });
        removed.sort(function (a, b) { return a - b; });

        return {
            added: added,
            removed: removed,
            changed: (added.length + removed.length) > 0
        };
    }

    // Сколько дочерних организаций (всех уровней) у выбранной организации.
    function childrenCount(divcode) {
        var needle = String(divcode || '').toLowerCase();

        if (needle === '') {
            return 0;
        }

        var children = {};

        $.each(DATA.orgs, function (uid, org) {
            if (!org || !org.divcode || !org.parent) {
                return;
            }

            var parent = String(org.parent).toLowerCase();

            if (!children[parent]) {
                children[parent] = [];
            }

            children[parent].push(String(org.divcode));
        });

        var queue = children[needle] ? children[needle].slice() : [];
        var seen = {};
        var count = 0;
        var guard = 0;

        while (queue.length && guard < 10000) {
            guard++;

            var code = queue.shift();
            var key = code.toLowerCase();

            if (seen[key]) {
                continue;
            }

            seen[key] = true;
            count++;

            if (children[key]) {
                queue = queue.concat(children[key]);
            }
        }

        return count;
    }

    function renderScopeDiff(diff) {
        var html = '';
        var i;

        if (diff.added.length) {
            html += '<div class="rc-access-scope-group">'
                + '<span class="label label-success">'
                + esc(<?php echo json_encode(__('Будет добавлено')); ?>)
                + '</span><ul>';

            for (i = 0; i < diff.added.length; i++) {
                html += '<li>' + esc(categoryName(diff.added[i]))
                    + ' <span class="text-muted">(ID ' + diff.added[i] + ')</span></li>';
            }

            html += '</ul></div>';
        }

        if (diff.removed.length) {
            html += '<div class="rc-access-scope-group">'
                + '<span class="label label-danger">'
                + esc(<?php echo json_encode(__('Будет снято')); ?>)
                + '</span><ul>';

            for (i = 0; i < diff.removed.length; i++) {
                html += '<li>' + esc(categoryName(diff.removed[i]))
                    + ' <span class="text-muted">(ID ' + diff.removed[i] + ')</span></li>';
            }

            html += '</ul></div>';
        }

        $scopeDiff.html(html);
    }

    function openAccessScopeModal(org, nextIds, diff) {
        var count = childrenCount(org.divcode);
        var hint = <?php echo json_encode(__('Добавить или снять категории у всех дочерних организаций и у сотрудников, входящих в выбранную организацию. Наборы сотрудников дочерних организаций не меняются.')); ?>;

        if (count > 0) {
            hint += ' '
                + <?php echo json_encode(__('Дочерних организаций в дереве:')); ?>
                + ' ' + count + '.';
        }

        $scopeOrg.text(org.name + ' [' + org.divcode + ']');
        $scopeCascadeHint.text(hint);
        renderScopeDiff(diff);

        // Пока действие не выбрано, «Применить» заблокирована: каскад не
        // должен срабатывать по умолчанию.
        $('input[name="rc-access-scope"]').prop('checked', false);
        $('#rc-access-scope-apply').prop('disabled', true);

        pendingAccess = {
            org: org,
            ids: nextIds,
            diff: diff
        };

        scopeSubmitting = false;
        $scopeModal.modal('show');
    }

    function loadAccess(org) {
        var seq = ++accessRequest;

        accessLoaded = false;
        accessAssigned = [];
        accessUnknown = [];

        $accessList.find('input.rc-access-check').prop('checked', false);
        updateAccessCount();
        setAccessStatus('');
        hideAccessAlert();
        clearAccessDirty();
        $('#rc-access-selected-badge').text(org.divcode || '').show();

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
            data: { type: 'org', divcode: org.divcode },
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

    $('#rc-org-access-all').on('click', function () {
        if (!accessEditable || !accessLoaded) {
            return;
        }

        $accessList.find('input.rc-access-check').prop('checked', true);
        updateAccessCount();
        markAccessDirty();
    });

    $('#rc-org-access-none').on('click', function () {
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

    // ===== Сохранение свойств организации =====

    function saveOrganization() {
        if (saveBusy) {
            return;
        }

        var org = selectedOrg();

        if (!org) {
            return;
        }

        hideAlert();
        clearErrors();

        var name = $.trim($('#rc-org-name').val());
        var parent = $parent.val() || '';
        var flag = $('#rc-org-flag').val() || '0';

        if (name === '' || name.length > 50) {
            markField('name');
            showAlert(<?php echo json_encode(__('Проверьте заполнение полей')); ?>, 'error');
            return;
        }

        // Только свойства организации: набор категорий доступа сохраняется
        // отдельной кнопкой в третьей панели (restmancard/save_access).
        var payload = {
            divcode: org.divcode,
            name: name,
            parent: parent,
            flag: flag
        };

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
                    || <?php echo json_encode(__('Не удалось сохранить организацию')); ?>, 'error');
                return;
            }

            $saveText.text('<?php echo __('Готово'); ?>');
            showAlert(response.message
                || <?php echo json_encode(__('Свойства организации сохранены')); ?>, 'success');
            clearDirty();

            var focus = (response && response.divcode) ? response.divcode : org.divcode;

            window.setTimeout(function () {
                window.location.href = orgsUrl + '?refresh=1&focus=' + encodeURIComponent(focus);
            }, 700);
        }).fail(function (xhr) {
            setSaveBusy(false);

            var message = <?php echo json_encode(__('Не удалось сохранить организацию')); ?>;

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
        saveOrganization();
    });

    $('#rc-org-cancel').on('click', function () {
        var org = selectedOrg();

        if (org) {
            // Отменяем только правку свойств: набор категорий доступа
            // остаётся в том состоянии, в котором его открыли.
            fillProperties(org);
        }
    });

    $form.on('input change', 'input, select', function () {
        markDirty();
    });

    // ===== Сохранение набора категорий доступа =====

    // Вызывается кнопкой «Сохранить»: сначала считает разницу и, если набор
    // изменился, открывает окно выбора области применения.
    function requestAccessSave() {
        if (accessBusy || !accessEditable || !accessLoaded) {
            return;
        }

        var org = selectedOrg();

        if (!org) {
            return;
        }

        var nextIds = checkedAccessIds();
        var diff = diffIds(accessAssigned, nextIds);

        if (!diff.changed) {
            // Набор не изменился: каскад не нужен, сохраняем как есть.
            pendingAccess = { org: org, ids: nextIds, diff: diff };
            submitAccessSave('org');
            return;
        }

        openAccessScopeModal(org, nextIds, diff);
    }

    // Отправляет набор с выбранной областью применения:
    //   org     — только организация;
    //   cascade — организация + дочерние организации + сотрудники.
    function submitAccessSave(scope) {
        if (accessBusy || !pendingAccess) {
            return;
        }

        if (scope !== 'cascade') {
            scope = 'org';
        }

        var pending = pendingAccess;
        pendingAccess = null;
        scopeSubmitting = true;

        $scopeModal.modal('hide');
        hideAccessAlert();
        setAccessBusy(true);
        setAccessControlsEnabled(false);

        $.ajax({
            url: saveAccessUrl,
            type: 'POST',
            data: {
                divcode: pending.org.divcode,
                access: pending.ids,
                scope: scope
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

            // Ответ PUT может не содержать набора — тогда оставляем отправленный.
            accessAssigned = (response.assigned && response.assigned.length)
                ? response.assigned
                : pending.ids.slice();
            applyAccessAssigned();
            setAccessControlsEnabled(true);
            clearAccessDirty();

            var warnings = response.warnings || [];
            var message = response.message
                || <?php echo json_encode(__('Набор категорий доступа сохранён')); ?>;

            if (warnings.length) {
                showAccessAlert(message + ' ' + warnings.join(' '), 'warning');
            } else {
                showAccessAlert(message, 'success');
            }

            if (accessUnknown.length) {
                setAccessStatus(
                    esc(<?php echo json_encode(__('В наборе есть категории, которых нет в справочнике')); ?>)
                    + ': +' + accessUnknown.length + '. '
                    + esc(<?php echo json_encode(__('Они сохранены без изменений.')); ?>),
                    'warning'
                );
            } else if (warnings.length) {
                setAccessStatus(esc(message), 'warning');
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
        }).always(function () {
            scopeSubmitting = false;
        });
    }

    $('#rc-access-scope-apply').on('click', function () {
        var scope = $('input[name="rc-access-scope"]:checked').val();

        if (!scope) {
            return;
        }

        submitAccessSave(scope === 'cascade' ? 'cascade' : 'org');
    });

    $scopeModal.on('change', 'input[name="rc-access-scope"]', function () {
        $('#rc-access-scope-apply').prop(
            'disabled',
            $('input[name="rc-access-scope"]:checked').length === 0
        );
    });

    // Закрытие окна без применения сбрасывает подготовленный набор.
    $scopeModal.on('hidden.bs.modal', function () {
        if (!scopeSubmitting) {
            pendingAccess = null;
        }
    });

    $('#rc-access-form').on('submit', function (event) {
        event.preventDefault();
        requestAccessSave();
    });

    // ===== Организация, изменённая последней =====

    if (focusDivcode !== '') {
        var $focusNode = $tree.find('li.tree-node[data-type="org"]').filter(function () {
            return String($(this).attr('data-divcode')) === focusDivcode;
        }).first();

        if ($focusNode.length) {
            $focusNode.parents('li.tree-node').each(function () {
                toggleNode($(this), true);
            });

            toggleNode($focusNode, true);
            selectNode($focusNode);

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
