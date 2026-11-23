<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Controller_Restmancard — форма «Организации и сотрудники» на данных
 * Artonit REST API v2 (http://localhost:5056/swagger/v2/swagger.json).
 *
 * Страница повторяет трёхпанельную компоновку mancard/index:
 *   1) дерево организаций и сотрудников;
 *   2) свойства выбранного элемента;
 *   3) категории доступа: справочник целиком, подсветка категорий
 *      выбранной организации/сотрудника и разница наборов.
 *
 * Поддерживается добавление, изменение свойств (название, родительская
 * организация, флаг) и удаление организаций, добавление и удаление
 * сотрудников, а также выдача набора категорий доступа организации.
 * Все изменения выполняются через REST API v2.
 */
class Controller_Restmancard extends Controller_Template
{
    /**
     * @var bool
     */
    public $auto_render = TRUE;

    public function before()
    {
        parent::before();

        $_SESSION['menu_active'] = 'restmancard';
        $this->set_full_width(TRUE);
        $this->set_title('Организации и сотрудники (REST)');
    }

    /**
     * @return  Model_Restmancard_Org
     */
    protected function _org()
    {
        return Model::factory('Restmancard_Org');
    }

    /**
     * @return  Model_Restmancard_Api
     */
    protected function _api()
    {
        return Model::factory('Restmancard_Api');
    }

    /**
     * @return  Model_Restmancard_People
     */
    protected function _people()
    {
        return Model::factory('Restmancard_People');
    }

    /**
     * @return  Model_Restmancard_Access
     */
    protected function _access()
    {
        return Model::factory('Restmancard_Access');
    }

    /**
     * Главная страница модуля.
     */
    public function action_index()
    {
        $refresh = (bool) $this->request->query('refresh');
        $focus_divcode = trim((string) $this->request->query('focus'));
        $focus_person = trim((string) $this->request->query('person'));

        if ($refresh)
        {
            $this->_api()->flush();
        }

        $error = NULL;
        $warnings = array();
        $tree = array();
        $org_model = $this->_org();

        $stats = array(
            'organizations'         => 0,
            'people'                => 0,
            'people_truncated'      => FALSE,
            'identifiers_loaded'    => 0,
            'identifiers_truncated' => FALSE,
            'identifiers_error'     => NULL,
            'people_root'           => '',
            'people_mapped'         => 0,
            'people_unmapped'       => 0,
            'people_map_enabled'    => FALSE,
            'people_map_error'      => NULL,
            'people_map_loaded'     => 0,
            'roots'                 => 0,
        );

        try
        {
            $tree = $org_model->getTree($focus_divcode);
            $warnings = $org_model->getWarnings();

            $model_stats = $org_model->getStats();
            if (is_array($model_stats) AND ! empty($model_stats))
            {
                $stats = array_merge($stats, $model_stats);
            }
        }
        catch (Exception $e)
        {
            $error = $e->getMessage();

            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось получить данные REST API: :message (файл :file, строка :line)' . PHP_EOL . $e->getTraceAsString(), array(
                ':message' => $e->getMessage(),
                ':file'    => $e->getFile(),
                ':line'    => $e->getLine(),
            ));
        }

        // Справочник категорий доступа нужен панели сразу, без выбора
        // элемента. Ошибка Access/GetList не должна ломать дерево: панель
        // покажет сообщение, остальные данные останутся доступны.
        $access_model = $this->_access();
        $access_bundle = $access_model->getCategories();
        $access_categories = $access_bundle['categories'];
        $access_error = $access_bundle['error'];
        $access_truncated = ! empty($access_bundle['truncated']);

        if ($access_error !== NULL)
        {
            $warnings[] = 'Не удалось загрузить категории доступа: ' . $access_error;
        }

        // Данные для панели «Свойства» (JSON для JS)
        $orgs = array();
        $people = array();
        $this->_collect($tree, $orgs, $people, '');

        // Варианты для выбора родительской организации в форме добавления
        $parent_options = array();

        // Варианты для выбора организации в форме добавления сотрудника
        $org_options = array();

        foreach ($orgs as $org)
        {
            $parent_options[] = array(
                'divcode' => $org['divcode'],
                'label'   => $org['path'],
            );

            $org_options[] = array(
                'id'      => (int) $org['id'],
                'divcode' => $org['divcode'],
                'label'   => $org['path'],
            );
        }

        $data = array(
            'orgs'   => $orgs,
            'people' => $people,
            'meta'   => array(
                'org_map_enabled' => ! empty($stats['people_map_enabled']),
                'people_root'     => (string) Arr::get($stats, 'people_root', ''),
                'focus'           => $focus_divcode,
            ),
        );

        $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $data_json = json_encode($data, $flags);

        if ($data_json === FALSE)
        {
            $data_json = json_encode($data);
        }

        if ($data_json === FALSE)
        {
            $data_json = '{"orgs":{},"people":{},"meta":{"org_map_enabled":false,"people_root":"","focus":""}}';
        }

        $api_config = Kohana::$config->load('restmancard.api');
        $api_url = is_array($api_config) ? (string) $api_config['base_url'] : '';

        $content = View::factory('restmancard/index', array(
            'tree'            => $tree,
            'error'           => $error,
            'warnings'        => $warnings,
            'stats'           => $stats,
            'data_json'       => $data_json,
            'api_url'         => $api_url,
            'generated_at'    => date('d.m.Y H:i:s'),
            'refresh_url'     => URL::site('restmancard/index') . '?refresh=1',
            'parent_options'  => $parent_options,
            'org_options'     => $org_options,
            'add_url'         => URL::site('restmancard/add_organization'),
            'edit_url'        => URL::site('restmancard/edit_organization'),
            'delete_url'      => URL::site('restmancard/delete_organization'),
            'add_person_url'  => URL::site('restmancard/add_person'),
            'delete_person_url' => URL::site('restmancard/delete_person'),
            'focus_divcode'   => $focus_divcode,
            'focus_person'    => $focus_person,
            'divcode_prefix'  => $org_model->getDivcodePrefix(),
            'access_categories' => $access_categories,
            'access_error'      => $access_error,
            'access_truncated'  => $access_truncated,
            'access_url'        => URL::site('restmancard/access'),
            'save_access_url'   => URL::site('restmancard/save_access'),
        ));

        $this->template->content = $content;
    }

    /**
     * AJAX: категории доступа выбранной организации или сотрудника.
     *
     * Параметры (GET или POST):
     *   type     — org | person;
     *   divcode  — организация (для type=org; для type=person — организация
     *              сотрудника, нужна только для сравнения наборов);
     *   tabnum   — сотрудник (для type=person).
     *
     * Ответ для организации: assigned = ID_ACCESSNAME организации.
     * Ответ для сотрудника: assigned = его категории, org_assigned =
     * категории его организации, common / only_person / only_org — разница
     * наборов, different — наборы различаются.
     *
     * @return  void
     */
    public function action_access()
    {
        $this->auto_render = FALSE;

        $method = strtoupper((string) $this->request->method());

        if ($method !== 'GET' AND $method !== 'POST')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Метод не поддерживается',
            ), 405);
            return;
        }

        $params = ($method === 'POST') ? $this->request->post() : $this->request->query();
        $type = strtolower(trim((string) Arr::get($params, 'type', '')));
        $access_model = $this->_access();

        try
        {
            if ($type === 'org')
            {
                $divcode = trim((string) Arr::get($params, 'divcode', ''));

                if ($divcode === '')
                {
                    $this->_json(array(
                        'success' => FALSE,
                        'message' => 'Не выбрана организация для показа категорий доступа',
                        'type'    => 'org',
                    ));
                    return;
                }

                $org_access = $access_model->getOrganizationAccess($divcode);

                if ($org_access['error'] !== NULL)
                {
                    $this->_json(array(
                        'success' => FALSE,
                        'message' => 'Не удалось получить категории доступа организации: '
                            . $org_access['error'],
                        'type'    => 'org',
                        'divcode' => $divcode,
                    ));
                    return;
                }

                $this->_json(array(
                    'success'      => TRUE,
                    'type'         => 'org',
                    'divcode'      => $divcode,
                    'assigned'     => $org_access['ids'],
                    'org_assigned' => array(),
                    'common'       => array(),
                    'only_person'  => array(),
                    'only_org'     => array(),
                    'different'    => FALSE,
                    'org_loaded'   => TRUE,
                    'warnings'     => array(),
                ));
                return;
            }

            if ($type === 'person')
            {
                $tabnum = trim((string) Arr::get($params, 'tabnum', ''));
                $divcode = trim((string) Arr::get($params, 'divcode', ''));

                if ($tabnum === '')
                {
                    $this->_json(array(
                        'success' => FALSE,
                        'message' => 'Не выбран сотрудник для показа категорий доступа',
                        'type'    => 'person',
                    ));
                    return;
                }

                $person_access = $access_model->getPersonAccess($tabnum);

                if ($person_access['error'] !== NULL)
                {
                    $this->_json(array(
                        'success' => FALSE,
                        'message' => 'Не удалось получить категории доступа сотрудника: '
                            . $person_access['error'],
                        'type'    => 'person',
                        'tabnum'  => $tabnum,
                    ));
                    return;
                }

                $warnings = $person_access['warnings'];
                $org_ids = array();
                $org_loaded = FALSE;
                $org_error = NULL;

                if ($divcode !== '')
                {
                    $org_access = $access_model->getOrganizationAccess($divcode);

                    if ($org_access['error'] !== NULL)
                    {
                        $org_error = $org_access['error'];
                        $warnings[] = 'Категории организации не загружены, сравнение наборов '
                            . 'недоступно: ' . $org_error;
                    }
                    else
                    {
                        $org_ids = $org_access['ids'];
                        $org_loaded = TRUE;
                    }
                }
                else
                {
                    $org_error = 'Организация сотрудника не определена';
                    $warnings[] = 'Организация сотрудника не определена: сравнить наборы '
                        . 'категорий невозможно.';
                }

                $diff = $access_model->compareSets($person_access['ids'], $org_ids);

                // Без категорий организации разница не считается: иначе все
                // категории сотрудника выглядели бы «только его».
                if ( ! $org_loaded)
                {
                    $diff['common'] = array();
                    $diff['only_person'] = array();
                    $diff['only_org'] = array();
                    $diff['different'] = FALSE;
                }

                $this->_json(array(
                    'success'      => TRUE,
                    'type'         => 'person',
                    'tabnum'       => $tabnum,
                    'divcode'      => $divcode,
                    'assigned'     => $diff['person'],
                    'org_assigned' => $diff['org'],
                    'common'       => $diff['common'],
                    'only_person'  => $diff['only_person'],
                    'only_org'     => $diff['only_org'],
                    'different'    => $diff['different'],
                    'org_loaded'   => $org_loaded,
                    'org_error'    => $org_error,
                    'warnings'     => $warnings,
                ));
                return;
            }

            $this->_json(array(
                'success' => FALSE,
                'message' => 'Неверный тип элемента: ожидается org или person',
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось получить категории доступа: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':message' => $e->getMessage(),
            ));

            $this->_json(array(
                'success' => FALSE,
                'message' => $e->getMessage(),
                'type'    => $type,
            ));
        }
    }

    /**
     * AJAX: добавить организацию через POST /api/v2/organizations.
     */
    public function action_add_organization()
    {
        $this->auto_render = FALSE;

        if ($this->request->method() !== 'POST')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Метод не поддерживается',
            ), 405);
            return;
        }

        $post = $this->request->post();
        $org_model = $this->_org();
        $errors = $org_model->validateOrganization($post);

        if ( ! empty($errors))
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => reset($errors),
                'errors'  => $errors,
            ));
            return;
        }

        try
        {
            $organization = $org_model->createOrganization($post);
            $divcode = (string) Arr::get($organization, 'DIVCODE', '');
            $generated = $org_model->divcodeWasGenerated();

            $message = 'Организация добавлена';

            if ($generated AND $divcode !== '')
            {
                $message .= '. Код сформирован автоматически: ' . $divcode;
            }

            $this->_json(array(
                'success'           => TRUE,
                'message'           => $message,
                'divcode'           => $divcode,
                'divcode_generated' => $generated,
                'organization'      => array(
                    'id'      => (int) Arr::get($organization, 'ID', 0),
                    'divcode' => $divcode,
                    'name'    => (string) Arr::get($organization, 'NAME', ''),
                    'parent'  => Arr::get($organization, 'PARENT', NULL),
                    'flag'    => (int) Arr::get($organization, 'FLAG', 0),
                ),
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось добавить организацию: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':message' => $e->getMessage(),
            ));

            $this->_json(array(
                'success' => FALSE,
                'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * AJAX: изменить свойства организации через
     * PUT /api/v2/organizations/{divcode}.
     *
     * Меняются только название, родительская организация и флаг. Код divcode
     * неизменяем (API: DIVCODE_IMMUTABLE), поэтому в тело запроса не входит.
     * Кольца в дереве отсекаются до запроса (сам себя / свой потомок), а
     * окончательную проверку всё равно выполняет API (409 CYCLE).
     */
    public function action_edit_organization()
    {
        $this->auto_render = FALSE;

        if ($this->request->method() !== 'POST')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Метод не поддерживается',
            ), 405);
            return;
        }

        $post = $this->request->post();
        $divcode = trim((string) Arr::get($post, 'divcode', ''));
        $org_model = $this->_org();

        try
        {
            $errors = $org_model->validateOrganizationUpdate($post);

            if ( ! empty($errors))
            {
                $this->_json(array(
                    'success' => FALSE,
                    'message' => reset($errors),
                    'errors'  => $errors,
                    'divcode' => $divcode,
                ));
                return;
            }

            $organization = $org_model->updateOrganization($divcode, $post);

            $this->_json(array(
                'success'      => TRUE,
                'message'      => 'Свойства организации сохранены',
                'divcode'      => (string) $organization['DIVCODE'],
                'organization' => array(
                    'id'      => (int) $organization['ID'],
                    'divcode' => (string) $organization['DIVCODE'],
                    'name'    => (string) $organization['NAME'],
                    'parent'  => $organization['PARENT'],
                    'flag'    => (int) $organization['FLAG'],
                ),
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось изменить организацию :divcode: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':divcode' => $divcode,
                ':message' => $e->getMessage(),
            ));

            $this->_json($this->_edit_failure($e, $divcode));
        }
    }

    /**
     * Ответ на ошибку изменения организации.
     *
     * @param   Exception  $e
     * @param   string     $divcode
     * @return  array
     */
    protected function _edit_failure(Exception $e, $divcode)
    {
        $result = array(
            'success' => FALSE,
            'message' => $e->getMessage(),
            'divcode' => $divcode,
        );

        $status = $this->_api_status($e);
        $reason = $this->_api_reason();

        if ($status > 0)
        {
            $result['status'] = $status;
        }

        if ($reason !== '')
        {
            $result['reason'] = $reason;
        }

        if ($status === 409 AND $reason === 'CYCLE')
        {
            $result['message'] = 'Нельзя выбрать родителем организацию-потомка: '
                . 'в дереве получится кольцо. Выберите другую организацию.';
            $result['errors'] = array('parent' => 'Потомок не может быть родителем');
        }
        elseif ($status === 422 AND $reason === 'PARENT_NOT_FOUND')
        {
            $result['message'] = 'Родительская организация не найдена — возможно, '
                . 'её удалили. Обновите страницу.';
            $result['errors'] = array('parent' => 'Родительская организация не найдена');
        }
        elseif ($status === 404)
        {
            $result['message'] = 'Организация не найдена — возможно, она уже удалена. Обновите страницу.';
        }
        elseif ($status === 403)
        {
            $result['message'] = 'Недостаточно прав для изменения организации.';
        }
        elseif ($status === 400 AND $reason === 'DIVCODE_IMMUTABLE')
        {
            $result['message'] = 'Код организации (divcode) изменить нельзя. '
                . 'Создайте новую организацию с нужным кодом.';
        }
        elseif ($status === 422 OR $status === 400)
        {
            $api_message = $this->_api_message();

            $result['message'] = ($api_message !== '')
                ? $api_message
                : 'REST API отклонил изменения: проверьте заполнение полей.';

            $field_errors = $this->_api_field_errors();

            if ( ! empty($field_errors))
            {
                $result['errors'] = $field_errors;
            }
        }
        elseif ($status === 0)
        {
            $result['message'] = 'REST API недоступен. Проверьте, что сервис запущен.';
        }

        return $result;
    }

    /**
     * AJAX: сохранить набор категорий доступа организации через
     * PUT /api/v2/organizations/{divcode}/accessnames.
     *
     * Набор заменяется целиком: переданные категории выдаются, отсутствующие
     * снимаются, пустой массив очищает набор.
     *
     * @return  void
     */
    public function action_save_access()
    {
        $this->auto_render = FALSE;

        if ($this->request->method() !== 'POST')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Метод не поддерживается',
            ), 405);
            return;
        }

        $post = $this->request->post();
        $divcode = trim((string) Arr::get($post, 'divcode', ''));
        $values = Arr::get($post, 'access', array());

        if ($divcode === '')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Не выбрана организация для сохранения категорий доступа',
            ));
            return;
        }

        if ( ! is_array($values))
        {
            $values = array($values);
        }

        $ids = array();
        $invalid = array();

        foreach ($values as $value)
        {
            if (is_array($value) OR $value === NULL)
            {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '')
            {
                continue;
            }

            // Идентификатор ACCESSNAME — неотрицательное целое;
            // 0 — рабочая системная категория (Check Time)
            if ( ! preg_match('/^\d{1,9}$/', $value))
            {
                $invalid[] = $value;
                continue;
            }

            $ids[] = (int) $value;
        }

        if ( ! empty($invalid))
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Некорректный идентификатор категории доступа: '
                    . implode(', ', array_slice($invalid, 0, 5)),
                'errors'  => array(
                    'access' => 'Ожидаются числовые идентификаторы категорий',
                ),
                'divcode' => $divcode,
            ));
            return;
        }

        try
        {
            $saved = $this->_access()->saveOrganizationAccess($divcode, $ids);

            $this->_json(array(
                'success'  => TRUE,
                'message'  => 'Набор категорий доступа сохранён',
                'divcode'  => $divcode,
                'assigned' => $saved,
                'count'    => count($saved),
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось сохранить категории доступа организации :divcode: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':divcode' => $divcode,
                ':message' => $e->getMessage(),
            ));

            $this->_json($this->_save_access_failure($e, $divcode));
        }
    }

    /**
     * Ответ на ошибку сохранения набора категорий доступа.
     *
     * @param   Exception  $e
     * @param   string     $divcode
     * @return  array
     */
    protected function _save_access_failure(Exception $e, $divcode)
    {
        $result = array(
            'success' => FALSE,
            'message' => $e->getMessage(),
            'divcode' => $divcode,
        );

        $status = $this->_api_status($e);
        $reason = $this->_api_reason();

        if ($status > 0)
        {
            $result['status'] = $status;
        }

        if ($reason !== '')
        {
            $result['reason'] = $reason;
        }

        if ($status === 422 AND $reason === 'ACCESSNAME_NOT_FOUND')
        {
            $api_message = $this->_api_message();

            $result['message'] = (($api_message !== '') ? $api_message : 'Категории доступа не найдены.')
                . ' Справочник категорий изменился — обновите страницу и повторите выбор.';
            $result['errors'] = array(
                'access' => 'Часть категорий отсутствует в базе',
            );
        }
        elseif ($status === 404)
        {
            $result['message'] = 'Организация не найдена — возможно, она уже удалена. Обновите страницу.';
        }
        elseif ($status === 403)
        {
            $result['message'] = 'Недостаточно прав для изменения категорий доступа организации.';
        }
        elseif ($status === 422 OR $status === 400)
        {
            $api_message = $this->_api_message();

            $result['message'] = ($api_message !== '')
                ? $api_message
                : 'REST API отклонил набор категорий доступа.';
        }
        elseif ($status === 0)
        {
            $result['message'] = 'REST API недоступен. Проверьте, что сервис запущен.';
        }

        return $result;
    }

    /**
     * AJAX: удалить выбранную организацию через
     * DELETE /api/v2/organizations/{divcode}.
     *
     * API удаляет только организацию без дочерних подразделений и активных
     * сотрудников; при отказе возвращается 409 OrganizationDeleteConflict,
     * который разбирается в _delete_failure().
     */
    public function action_delete_organization()
    {
        $this->auto_render = FALSE;

        if ($this->request->method() !== 'POST')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Метод не поддерживается',
            ), 405);
            return;
        }

        $post = $this->request->post();
        $divcode = trim((string) Arr::get($post, 'divcode', ''));

        if ($divcode === '')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Не выбрана организация для удаления',
            ));
            return;
        }

        try
        {
            $this->_org()->deleteOrganization($divcode);

            $this->_json(array(
                'success' => TRUE,
                'message' => 'Организация удалена',
                'divcode' => $divcode,
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось удалить организацию :divcode: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':divcode' => $divcode,
                ':message' => $e->getMessage(),
            ));

            $this->_json($this->_delete_failure($e, $divcode));
        }
    }

    /**
     * Ответ на ошибку удаления организации.
     *
     * @param   Exception  $e
     * @param   string     $divcode
     * @return  array
     */
    protected function _delete_failure(Exception $e, $divcode)
    {
        $result = array(
            'success' => FALSE,
            'message' => $e->getMessage(),
            'divcode' => $divcode,
        );

        // Api хранит разобранный ответ последней ошибки в статическом поле,
        // поэтому сведения доступны независимо от экземпляра модели.
        $error = $this->_api()->lastError();

        if ( ! is_array($error) OR empty($error['status']))
        {
            return $result;
        }

        $status = (int) $error['status'];
        $data = (isset($error['data']) AND is_array($error['data'])) ? $error['data'] : array();

        $result['status'] = $status;
        $result['reason'] = (string) Arr::get($data, 'error', '');

        if ($status === 409)
        {
            $children = (int) Arr::get($data, 'childrenCount', 0);
            $people = (int) Arr::get($data, 'peopleCount', 0);

            $result['children_count'] = $children;
            $result['people_count'] = $people;
            $result['children'] = array_slice((array) Arr::get($data, 'children', array()), 0, 20);
            $result['people'] = array_slice((array) Arr::get($data, 'people', array()), 0, 20);

            $reasons = array();

            if ($children > 0)
            {
                $reasons[] = 'дочерние организации (' . $children . ')';
            }

            if ($people > 0)
            {
                $reasons[] = 'активные сотрудники (' . $people . ')';
            }

            if ( ! empty($reasons))
            {
                $message = 'Нельзя удалить организацию: у неё есть ' . implode(' и ', $reasons) . '.';
            }
            else
            {
                $message = trim((string) Arr::get($data, 'message', ''));

                if ($message !== '')
                {
                    $message = rtrim($message, '.') . '.';
                }
                else
                {
                    $message = 'Нельзя удалить организацию: она не пуста.';
                }
            }

            if ($children > 0)
            {
                $message .= ' Сначала удалите или переместите дочерние организации.';
            }

            if ($people > 0)
            {
                $message .= ' Сначала переведите или удалите сотрудников.';
            }

            $result['message'] = $message;
        }
        elseif ($status === 404)
        {
            $result['message'] = 'Организация не найдена — возможно, она уже удалена. Обновите страницу.';
        }
        elseif ($status === 403)
        {
            $result['message'] = 'Недостаточно прав для удаления организации.';
        }
        else
        {
            $api_message = trim((string) Arr::get($data, 'message', ''));

            if ($api_message !== '')
            {
                $result['message'] = $api_message;
            }
        }

        return $result;
    }

    /**
     * AJAX: добавить сотрудника в выбранную организацию.
     *
     * Обязательна только фамилия. Табельный номер можно не указывать:
     * при включённом чекбоксе «Автоматически» (tabnum_auto=1) или пустом
     * поле номер формирует REST API как tn_<ID сотрудника>.
     *
     * Сотрудник создаётся через POST /api/v2/Person и привязывается к
     * организации через PATCH (id_org): в теле POST организация не
     * передаётся по контракту API.
     */
    public function action_add_person()
    {
        $this->auto_render = FALSE;

        if ($this->request->method() !== 'POST')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Метод не поддерживается',
            ), 405);
            return;
        }

        $post = $this->request->post();
        $people_model = $this->_people();
        $org_model = $this->_org();

        try
        {
            $errors = $people_model->validatePerson($post);

            $organization = $org_model->getOrganizationById((int) Arr::get($post, 'id_org', 0));

            if ($organization === NULL)
            {
                $errors['id_org'] = 'Выберите организацию для нового сотрудника';
            }

            if ( ! empty($errors))
            {
                $this->_json(array(
                    'success' => FALSE,
                    'message' => reset($errors),
                    'errors'  => $errors,
                ));
                return;
            }

            $person = $people_model->createPersonWithOrganization(array(
                'surname'     => trim((string) Arr::get($post, 'surname', '')),
                'name'        => trim((string) Arr::get($post, 'name', '')),
                'patronymic'  => trim((string) Arr::get($post, 'patronymic', '')),
                'tabnum'      => trim((string) Arr::get($post, 'tabnum', '')),
                'tabnum_auto' => $people_model->isTabnumAutoRequested($post) ? 1 : 0,
                'id_org'      => (int) $organization['ID'],
            ));

            $generated = ! empty($person['TABNUM_GENERATED']);
            $message = 'Сотрудник добавлен';

            if ($generated)
            {
                $message .= '. Табельный номер сформирован автоматически: ' . $person['TABNUM'];
            }

            $this->_json(array(
                'success'          => TRUE,
                'message'          => $message,
                'tabnum'           => (string) $person['TABNUM'],
                'tabnum_generated' => $generated,
                'full_name'        => (string) $person['FULL_NAME'],
                'organization'     => array(
                    'id'      => (int) $organization['ID'],
                    'divcode' => (string) $organization['DIVCODE'],
                    'name'    => (string) $organization['NAME'],
                ),
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось добавить сотрудника: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':message' => $e->getMessage(),
            ));

            $this->_json($this->_add_person_failure($e, $post));
        }
    }

    /**
     * AJAX: удалить сотрудника через DELETE /api/v2/Person?tabnum=...
     *
     * Вместе с сотрудником API удаляет его идентификаторы (карты).
     */
    public function action_delete_person()
    {
        $this->auto_render = FALSE;

        if ($this->request->method() !== 'POST')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Метод не поддерживается',
            ), 405);
            return;
        }

        $post = $this->request->post();
        $tabnum = trim((string) Arr::get($post, 'tabnum', ''));

        if ($tabnum === '')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Не выбран сотрудник для удаления',
            ));
            return;
        }

        try
        {
            $this->_people()->deletePerson($tabnum);

            $this->_json(array(
                'success' => TRUE,
                'message' => 'Сотрудник удалён',
                'tabnum'  => $tabnum,
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось удалить сотрудника :tabnum: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':tabnum'  => $tabnum,
                ':message' => $e->getMessage(),
            ));

            $this->_json($this->_delete_person_failure($e, $tabnum));
        }
    }

    /**
     * Ответ на ошибку добавления сотрудника.
     *
     * @param   Exception  $e
     * @param   array      $data  отправленные поля формы
     * @return  array
     */
    protected function _add_person_failure(Exception $e, array $data)
    {
        $result = array(
            'success' => FALSE,
            'message' => $e->getMessage(),
        );

        $status = $this->_api_status($e);

        if ($status > 0)
        {
            $result['status'] = $status;
        }

        $manual_tabnum = ( ! $this->_people()->isTabnumAutoRequested($data)
            AND trim((string) Arr::get($data, 'tabnum', '')) !== '');

        if ($status === 409)
        {
            if ($manual_tabnum)
            {
                $result['message'] = 'Сотрудник с таким табельным номером уже существует. '
                    . 'Укажите другой номер или включите «Автоматически».';
                $result['errors'] = array(
                    'tabnum' => 'Сотрудник с таким табельным номером уже существует',
                );
            }
            else
            {
                $result['message'] = 'Не удалось подобрать свободный табельный номер. Повторите попытку.';
            }
        }
        elseif ($status === 400)
        {
            $api_message = $this->_api_message();

            if ($api_message !== '')
            {
                $result['message'] = $api_message;
            }
        }
        elseif ($status === 403)
        {
            $result['message'] = 'Недостаточно прав для добавления сотрудника.';
        }
        elseif ($status === 0)
        {
            $result['message'] = 'REST API недоступен. Проверьте, что сервис запущен.';
        }

        return $result;
    }

    /**
     * Ответ на ошибку удаления сотрудника.
     *
     * @param   Exception  $e
     * @param   string     $tabnum
     * @return  array
     */
    protected function _delete_person_failure(Exception $e, $tabnum)
    {
        $result = array(
            'success' => FALSE,
            'message' => $e->getMessage(),
            'tabnum'  => $tabnum,
        );

        $status = $this->_api_status($e);

        if ($status > 0)
        {
            $result['status'] = $status;
        }

        if ($status === 404)
        {
            $result['message'] = 'Сотрудник не найден — возможно, он уже удалён. Обновите страницу.';
        }
        elseif ($status === 403)
        {
            $result['message'] = 'Недостаточно прав для удаления сотрудника.';
        }
        elseif ($status === 409)
        {
            $api_message = $this->_api_message();

            $result['message'] = ($api_message !== '')
                ? $api_message
                : 'Нельзя удалить сотрудника: он используется в других данных API.';
        }
        elseif ($status === 0)
        {
            $result['message'] = 'REST API недоступен. Проверьте, что сервис запущен.';
        }

        return $result;
    }

    /**
     * HTTP-статус последней ошибки API или статус из текста исключения.
     *
     * Ответ на ошибку может быть обёрнут (например, при откате создания
     * сотрудника), поэтому кроме lastError() разбирается и текст сообщения.
     *
     * @param   Exception  $e
     * @return  int  0 — статус неизвестен (в том числе ошибка сети)
     */
    protected function _api_status(Exception $e)
    {
        $error = $this->_api()->lastError();

        if (is_array($error) AND ! empty($error['status']))
        {
            return (int) $error['status'];
        }

        if (preg_match('/HTTP\s+(\d{3})/', $e->getMessage(), $matches))
        {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Текст ошибки API из разобранного ответа (message / error).
     *
     * @return  string
     */
    protected function _api_message()
    {
        $error = $this->_api()->lastError();

        if ( ! is_array($error) OR ! isset($error['data']) OR ! is_array($error['data']))
        {
            return '';
        }

        foreach (array('message', 'error', 'Message', 'Error') as $key)
        {
            $value = trim((string) Arr::get($error['data'], $key, ''));

            if ($value !== '')
            {
                return $value;
            }
        }

        return '';
    }

    /**
     * Машиночитаемый код последней ошибки API (поле error).
     *
     * @return  string  пустая строка — код не пришёл
     */
    protected function _api_reason()
    {
        $error = $this->_api()->lastError();

        if ( ! is_array($error) OR ! isset($error['data']) OR ! is_array($error['data']))
        {
            return '';
        }

        return strtoupper(trim((string) Arr::get($error['data'], 'error', '')));
    }

    /**
     * Ошибки по полям из details[] ответа API.
     *
     * Ответ вида {"error":"VALIDATION_ERROR","details":[{"field":"name",...}]}
     * превращается в field => message, чтобы форма подсветила нужные поля.
     * Имя поля вида accessnames[0] приводится к accessnames.
     *
     * @return  array
     */
    protected function _api_field_errors()
    {
        $error = $this->_api()->lastError();

        if ( ! is_array($error) OR ! isset($error['data']) OR ! is_array($error['data']))
        {
            return array();
        }

        $details = Arr::get($error['data'], 'details', array());

        if ( ! is_array($details))
        {
            return array();
        }

        $message = $this->_api_message();
        $result = array();

        foreach ($details as $detail)
        {
            if ( ! is_array($detail))
            {
                continue;
            }

            $field = trim((string) Arr::get($detail, 'field', ''));

            if ($field === '')
            {
                continue;
            }

            $bracket = strpos($field, '[');

            if ($bracket !== FALSE)
            {
                $field = substr($field, 0, $bracket);
            }

            $result[$field] = ($message !== '') ? $message : 'Некорректное значение';
        }

        return $result;
    }

    /**
     * Отдать JSON-ответ.
     *
     * @param   array  $data
     * @param   int    $status
     */
    protected function _json(array $data, $status = 200)
    {
        $this->response->status($status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');

        $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $body = json_encode($data, $flags);

        if ($body === FALSE)
        {
            $body = json_encode($data);
        }

        $this->response->body($body);
    }

    /**
     * Собрать плоские данные для панели «Свойства».
     *
     * @param   array  $branch
     * @param   array  $orgs
     * @param   array  $people
     * @param   string $parent_name
     */
    protected function _collect(array $branch, array & $orgs, array & $people, $parent_name)
    {
        foreach ($branch as $node)
        {
            $person_uids = array();

            foreach ($node['PEOPLE'] as $person)
            {
                $person_uids[] = $person['UID'];

                $people[$person['UID']] = array(
                    'uid'         => $person['UID'],
                    'tabnum'      => $person['TABNUM'],
                    'surname'     => $person['SURNAME'],
                    'name'        => $person['NAME'],
                    'patronymic'  => $person['PATRONYMIC'],
                    'full_name'   => $person['FULL_NAME'],
                    'org_name'    => $node['NAME'],
                    'org_divcode' => $node['DIVCODE'],
                    'org_known'   => empty($node['IS_PEOPLE_ROOT']),
                    'identifiers' => $this->_identifiers_for_json($person['IDENTIFIERS']),
                );
            }

            $orgs[$node['UID']] = array(
                'uid'            => $node['UID'],
                'id'             => (int) $node['ID'],
                'divcode'        => $node['DIVCODE'],
                'name'           => $node['NAME'],
                'parent'         => $node['PARENT'],
                'parent_name'    => (string) $parent_name,
                'flag'           => (int) $node['FLAG'],
                'created_at'     => $this->_format_date($node['CREATED_AT']),
                'updated_at'     => $this->_format_date($node['UPDATED_AT']),
                'path'           => $node['PATH'],
                'level'          => (int) $node['LEVEL'],
                'children_count' => (int) $node['CHILDREN_COUNT'],
                'people_count'   => (int) $node['PEOPLE_COUNT'],
                'total_children' => (int) $node['TOTAL_CHILDREN'],
                'total_people'   => (int) $node['TOTAL_PEOPLE'],
                'is_people_root' => (bool) $node['IS_PEOPLE_ROOT'],
                'people'         => $person_uids,
            );

            $this->_collect($node['CHILDREN'], $orgs, $people, $node['NAME']);
        }
    }

    /**
     * Идентификаторы для JSON панели свойств.
     *
     * @param   array  $cards
     * @return  array
     */
    protected function _identifiers_for_json(array $cards)
    {
        $result = array();

        foreach ($cards as $card)
        {
            $result[] = array(
                'id_card'    => $card['ID_CARD'],
                'type_id'    => (int) $card['TYPE_ID'],
                'type_name'  => $card['TYPE_NAME'],
                'type_short' => $card['TYPE_SHORT'],
                'card_class' => $card['CARD_CLASS'],
            );
        }

        return $result;
    }

    /**
     * Дата ISO 8601 (UTC) в человекочитаемый вид.
     *
     * @param   mixed  $value
     * @return  string
     */
    protected function _format_date($value)
    {
        if ($value === NULL OR $value === '')
        {
            return '';
        }

        $value = (string) $value;
        $is_utc = (substr($value, -1) === 'Z');
        $timestamp = strtotime($value);

        if ($timestamp === FALSE)
        {
            return $value;
        }

        return gmdate('d.m.Y H:i', $timestamp) . ($is_utc ? ' UTC' : '');
    }
}
