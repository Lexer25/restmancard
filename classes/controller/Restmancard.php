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
 * В форме «Организации» изменение набора можно применить только к ней или
 * каскадно — к дочерним организациям и сотрудникам выбранной организации.
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
        $root_divcode = NULL;
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
            $root_divcode = $org_model->rootDivcode();
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
        $this->_collect($tree, $orgs, $people, '', $root_divcode);

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
                'root_divcode'    => (string) $root_divcode,
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
     * Отдельная форма для работы с организациями (без сотрудников).
     *
     * Пункт меню «Организации» открывает форму из трёх панелей:
     *   1) дерево организаций;
     *   2) свойства выбранной организации (название, родитель, флаг);
     *   3) набор категорий доступа выбранной организации.
     *
     * Панели сохраняются независимо: «Сохранить» в свойствах вызывает
     * restmancard/save_organization (только PUT /api/v2/organizations),
     * «Сохранить» в категориях — restmancard/save_access (только
     * PUT /api/v2/organizations/{divcode}/accessnames).
     *
     * Сотрудники не читаются: используются только GET /api/v2/organizations
     * и Access/GetList.
     *
     * @return  void
     */
    public function action_organizations()
    {
        $this->set_title('Организации (REST)');

        $refresh = (bool) $this->request->query('refresh');
        $focus_divcode = trim((string) $this->request->query('focus'));

        if ($refresh)
        {
            $this->_api()->flush();
        }

        $error = NULL;
        $warnings = array();
        $tree = array();
        $root_divcode = NULL;
        $org_model = $this->_org();

        $stats = array(
            'organizations'  => 0,
            'roots'          => 0,
            'truncated_orgs' => FALSE,
        );

        try
        {
            $tree = $org_model->getOrganizationTree($focus_divcode);
            $root_divcode = $org_model->rootDivcode();
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

            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось получить организации: :message (файл :file, строка :line)' . PHP_EOL . $e->getTraceAsString(), array(
                ':message' => $e->getMessage(),
                ':file'    => $e->getFile(),
                ':line'    => $e->getLine(),
            ));
        }

        // Справочник категорий доступа: без него форма работает только с
        // названием и родителем, а сохранение набора категорий отключается.
        $access_model = $this->_access();
        $access_bundle = $access_model->getCategories();
        $access_categories = $access_bundle['categories'];
        $access_error = $access_bundle['error'];
        $access_truncated = ! empty($access_bundle['truncated']);

        if ($access_error !== NULL)
        {
            $warnings[] = 'Не удалось загрузить категории доступа: ' . $access_error;
        }

        // Плоские данные для формы: значения текущей организации и список
        // вариантов родителя.
        $orgs = array();
        $this->_collect_orgs($tree, $orgs, '', $root_divcode);

        $data = array(
            'orgs' => $orgs,
            'meta' => array(
                'root_divcode' => (string) $root_divcode,
                'focus'        => $focus_divcode,
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
            $data_json = '{"orgs":{},"meta":{"focus":""}}';
        }

        $api_config = Kohana::$config->load('restmancard.api');
        $api_url = is_array($api_config) ? (string) $api_config['base_url'] : '';

        $content = View::factory('restmancard/organizations', array(
            'tree'              => $tree,
            'error'             => $error,
            'warnings'          => $warnings,
            'stats'             => $stats,
            'data_json'         => $data_json,
            'api_url'           => $api_url,
            'generated_at'      => date('d.m.Y H:i:s'),
            'refresh_url'       => URL::site('restmancard/organizations') . '?refresh=1',
            'save_url'          => URL::site('restmancard/save_organization'),
            'save_access_url'   => URL::site('restmancard/save_access'),
            'access_url'        => URL::site('restmancard/access'),
            'focus_divcode'     => $focus_divcode,
            'access_categories' => $access_categories,
            'access_error'      => $access_error,
            'access_truncated'  => $access_truncated,
            'access_editable'   => ($access_error === NULL AND ! empty($access_categories)),
        ));

        $this->template->content = $content;
    }

    /**
     * Отдельная форма для работы с сотрудниками (пункт меню «Сотрудники»).
     *
     * Форма повторяет компоновку restmancard/organizations, но работает
     * с сотрудниками, а организации показаны только как группы дерева:
     * добавлять, изменять и удалять организации здесь нельзя.
     *
     *  1) дерево организаций с сотрудниками (без карт и без кнопок
     *     управления организациями);
     *  2) свойства сотрудника: ФИО и организация (родительская ссылка);
     *  3) набор категорий доступа сотрудника.
     *
     * «Сохранить» панели свойств вызывает restmancard/save_person:
     * PUT /api/v2/Person (ФИО) и, если организация изменена,
     * PATCH /api/v2/Person (id_org). «Сохранить» панели категорий вызывает
     * restmancard/save_person_access → PUT /api/v2/Person/Access.
     *
     * @return  void
     */
    public function action_people()
    {
        $this->set_title('Сотрудники (REST)');

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
        $root_divcode = NULL;
        $org_model = $this->_org();

        $stats = array(
            'organizations'      => 0,
            'people'             => 0,
            'people_truncated'   => FALSE,
            'people_root'        => '',
            'people_mapped'      => 0,
            'people_unmapped'    => 0,
            'people_map_enabled' => FALSE,
            'roots'              => 0,
        );

        try
        {
            $tree = $org_model->getPeopleTree($focus_divcode);
            $root_divcode = $org_model->rootDivcode();
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

            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось получить сотрудников: :message (файл :file, строка :line)' . PHP_EOL . $e->getTraceAsString(), array(
                ':message' => $e->getMessage(),
                ':file'    => $e->getFile(),
                ':line'    => $e->getLine(),
            ));
        }

        // Справочник категорий доступа: без него форма работает только
        // с ФИО и организацией, а сохранение набора отключается.
        $access_model = $this->_access();
        $access_bundle = $access_model->getCategories();
        $access_categories = $access_bundle['categories'];
        $access_error = $access_bundle['error'];
        $access_truncated = ! empty($access_bundle['truncated']);

        if ($access_error !== NULL)
        {
            $warnings[] = 'Не удалось загрузить категории доступа: ' . $access_error;
        }

        // Плоские данные для формы: сотрудники (с их организацией) и список
        // организаций для выбора.
        $people = array();
        $orgs = array();
        $this->_collect_people($tree, $people);
        $this->_collect_orgs($tree, $orgs, '', $root_divcode);

        $data = array(
            'people' => $people,
            'orgs'   => $orgs,
            'meta'   => array(
                'root_divcode' => (string) $root_divcode,
                'focus'        => $focus_divcode,
                'person'       => $focus_person,
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
            $data_json = '{"people":{},"orgs":{},"meta":{"focus":"","person":""}}';
        }

        $api_config = Kohana::$config->load('restmancard.api');
        $api_url = is_array($api_config) ? (string) $api_config['base_url'] : '';

        $refresh_query = array('refresh' => 1);

        if ($focus_divcode !== '')
        {
            $refresh_query['focus'] = $focus_divcode;
        }

        if ($focus_person !== '')
        {
            $refresh_query['person'] = $focus_person;
        }

        $content = View::factory('restmancard/people', array(
            'tree'              => $tree,
            'error'             => $error,
            'warnings'          => $warnings,
            'stats'             => $stats,
            'data_json'         => $data_json,
            'api_url'           => $api_url,
            'generated_at'      => date('d.m.Y H:i:s'),
            'refresh_url'       => URL::site('restmancard/people') . '?' . http_build_query($refresh_query),
            'save_person_url'   => URL::site('restmancard/save_person'),
            'save_access_url'   => URL::site('restmancard/save_person_access'),
            'access_url'        => URL::site('restmancard/access'),
            'focus_divcode'     => $focus_divcode,
            'focus_person'      => $focus_person,
            'access_categories' => $access_categories,
            'access_error'      => $access_error,
            'access_truncated'  => $access_truncated,
            'access_editable'   => ($access_error === NULL AND ! empty($access_categories)),
        ));

        $this->template->content = $content;
    }

    /**
     * AJAX: сохранить свойства сотрудника — ФИО и организацию.
     *
     * ФИО сохраняется через PUT /api/v2/Person (замена ФИО целиком:
     * фамилия обязательна, имя и отчество могут быть пустыми).
     *
     * Организация (родительская ссылка) сохраняется через
     * PATCH /api/v2/Person с id_org и только когда форма передала id_org:
     * если пользователь не менял список, запрос не отправляется и лишняя
     * запись в журнал PEOPLE не создаётся.
     *
     * @return  void
     */
    public function action_save_person()
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
        $people_model = $this->_people();
        $org_model = $this->_org();

        $errors = $people_model->validatePersonUpdate($post);

        $id_org = (int) Arr::get($post, 'id_org', 0);
        $organization = NULL;

        if ($id_org > 0)
        {
            $organization = $org_model->getOrganizationById($id_org);

            if ($organization === NULL)
            {
                $errors['id_org'] = 'Выберите организацию сотрудника';
            }
        }

        if ( ! empty($errors))
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => reset($errors),
                'errors'  => $errors,
                'tabnum'  => $tabnum,
            ));
            return;
        }

        $names_saved = FALSE;
        $person = NULL;

        try
        {
            // Сначала ФИО: это основное действие панели свойств.
            $person = $people_model->updatePerson($tabnum, $post);
            $names_saved = TRUE;

            $message = 'ФИО сотрудника сохранено';

            if ($organization !== NULL)
            {
                $updated = $people_model->assignToOrganization($tabnum, $id_org);

                if ($updated === 0)
                {
                    throw new Kohana_Exception(
                        'REST API не подтвердил смену организации сотрудника (id_org = :id)',
                        array(':id' => $id_org)
                    );
                }

                $message = 'Свойства сотрудника сохранены';
            }

            $this->_json(array(
                'success'      => TRUE,
                'message'      => $message,
                'tabnum'       => (string) $person['TABNUM'],
                'full_name'    => (string) $person['FULL_NAME'],
                'names_saved'  => TRUE,
                'person'       => array(
                    'tabnum'     => (string) $person['TABNUM'],
                    'surname'    => (string) $person['SURNAME'],
                    'name'       => (string) $person['NAME'],
                    'patronymic' => (string) $person['PATRONYMIC'],
                    'full_name'  => (string) $person['FULL_NAME'],
                ),
                'organization' => ($organization === NULL) ? NULL : array(
                    'id'      => (int) $organization['ID'],
                    'divcode' => (string) $organization['DIVCODE'],
                    'name'    => (string) $organization['NAME'],
                ),
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось сохранить сотрудника :tabnum: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':tabnum'  => $tabnum,
                ':message' => $e->getMessage(),
            ));

            $result = $this->_save_person_failure($e, $tabnum);

            // ФИО уже записано, а организация не сменилась: пользователь
            // должен знать, что часть изменений сохранена.
            if ($names_saved AND $id_org > 0)
            {
                $result['names_saved'] = TRUE;
                $result['message'] = rtrim($result['message'], '.') . '. ФИО при этом сохранено.';
            }

            $this->_json($result);
        }
    }

    /**
     * Ответ на ошибку сохранения сотрудника.
     *
     * @param   Exception  $e
     * @param   string     $tabnum
     * @return  array
     */
    protected function _save_person_failure(Exception $e, $tabnum)
    {
        $result = array(
            'success' => FALSE,
            'message' => $e->getMessage(),
            'tabnum'  => $tabnum,
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

        if ($status === 404)
        {
            $result['message'] = 'Сотрудник не найден — возможно, он уже удалён. Обновите страницу.';
        }
        elseif ($status === 403)
        {
            $result['message'] = 'Недостаточно прав для изменения сотрудника.';
        }
        elseif ($status === 409)
        {
            $api_message = $this->_api_message();

            $result['message'] = ($api_message !== '')
                ? $api_message
                : 'REST API отклонил изменение сотрудника.';
        }
        elseif ($status === 422 OR $status === 400)
        {
            $api_message = $this->_api_message();

            $result['message'] = ($api_message !== '')
                ? $api_message
                : 'REST API отклонил изменение сотрудника: проверьте ФИО и организацию.';

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
     * AJAX: сохранить набор категорий доступа сотрудника через
     * PUT /api/v2/Person/Access.
     *
     * Набор заменяется целиком: переданные категории выдаются, отсутствующие
     * снимаются, пустой массив очищает набор.
     *
     * @return  void
     */
    public function action_save_person_access()
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
        $values = Arr::get($post, 'access', array());

        if ($tabnum === '')
        {
            $this->_json(array(
                'success' => FALSE,
                'message' => 'Не выбран сотрудник для сохранения категорий доступа',
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
                'tabnum'  => $tabnum,
            ));
            return;
        }

        try
        {
            $saved = $this->_access()->savePersonAccess($tabnum, $ids);

            $this->_json(array(
                'success'  => TRUE,
                'message'  => 'Набор категорий доступа сохранён',
                'tabnum'   => $tabnum,
                'assigned' => $saved,
                'count'    => count($saved),
            ));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось сохранить категории доступа сотрудника :tabnum: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':tabnum'  => $tabnum,
                ':message' => $e->getMessage(),
            ));

            $this->_json($this->_save_person_access_failure($e, $tabnum));
        }
    }

    /**
     * Ответ на ошибку сохранения набора категорий доступа сотрудника.
     *
     * @param   Exception  $e
     * @param   string     $tabnum
     * @return  array
     */
    protected function _save_person_access_failure(Exception $e, $tabnum)
    {
        $result = array(
            'success' => FALSE,
            'message' => $e->getMessage(),
            'tabnum'  => $tabnum,
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
            $result['message'] = 'Сотрудник не найден — возможно, он уже удалён. Обновите страницу.';
        }
        elseif ($status === 403)
        {
            $result['message'] = 'Недостаточно прав для изменения категорий доступа сотрудника.';
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
     * AJAX: сохранить только свойства организации через
     * PUT /api/v2/organizations/{divcode}.
     *
     * Метод вызывается кнопкой «Сохранить» панели «Свойства организации»
     * формы restmancard/organizations и меняет только название, родительскую
     * организацию и флаг. Набор категорий доступа он не читает и не пишет:
     * за него отвечает отдельная панель «Набор категорий доступа» и метод
     * restmancard/save_access. Благодаря этому сохранение свойств не может
     * перезаписать или очистить выданные категории.
     *
     * @return  void
     */
    public function action_save_organization()
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

            // Только свойства организации: набор категорий доступа
            // сохраняется отдельным методом restmancard/save_access.
            $organization = $org_model->updateOrganization($divcode, $post);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось сохранить организацию :divcode: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':divcode' => $divcode,
                ':message' => $e->getMessage(),
            ));

            $this->_json($this->_edit_failure($e, $divcode));
            return;
        }

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

    /**
     * AJAX: сохранить набор категорий доступа организации через
     * PUT /api/v2/organizations/{divcode}/accessnames.
     *
     * Набор заменяется целиком: переданные категории выдаются, отсутствующие
     * снимаются, пустой массив очищает набор.
     *
     * Параметр scope управляет дальнейшим применением изменений:
     *   org     — изменить набор только у выбранной организации (по умолчанию;
     *             так работают прежние вызовы из restmancard/index);
     *   cascade — дополнительно добавить/снять ту же разницу у всех дочерних
     *             организаций (рекурсивно) и у сотрудников, входящих
     *             непосредственно в выбранную организацию.
     *
     * Разница «было / стало» считается до записи: текущий набор читается
     * через GET .../accessnames и сравнивается с присланным. Поэтому каскад
     * применяет только фактическую разницу, а остальные категории дочерних
     * организаций и сотрудников сохраняются без изменений.
     *
     * Для scope=cascade дочерние организации и сотрудники определяются до
     * сохранения набора: если список получить нельзя или он превышает
     * предохранители access.cascade_max_orgs / access.cascade_max_people,
     * запрос завершается ошибкой и ничего не меняет.
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
        $scope = strtolower(trim((string) Arr::get($post, 'scope', 'org')));

        if ($scope !== 'cascade')
        {
            $scope = 'org';
        }

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

        $access_model = $this->_access();
        $before_ids = NULL;
        $diff = array(
            'added'   => array(),
            'removed' => array(),
            'changed' => FALSE,
        );
        $targets = NULL;

        // Проверки до записи: при ошибке набор организации ещё не изменён.
        try
        {
            $before = $access_model->getOrganizationAccess($divcode);

            if ($before['error'] === NULL)
            {
                $before_ids = $before['ids'];
                $diff = $access_model->diffSets($before_ids, $ids);
            }
            elseif ($scope === 'cascade')
            {
                // Без текущего набора нельзя понять, что именно добавлять
                // и снимать у дочерних организаций и сотрудников.
                throw new Kohana_Exception(
                    'Не удалось прочитать текущий набор категорий доступа организации :divcode: :message',
                    array(
                        ':divcode' => $divcode,
                        ':message' => $before['error'],
                    )
                );
            }

            if ($scope === 'cascade' AND $diff['changed'])
            {
                $targets = $this->_access_cascade_targets($divcode, $access_model);
            }
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: каскадное изменение категорий доступа организации :divcode не начато: :message', array(
                ':divcode' => $divcode,
                ':message' => $e->getMessage(),
            ));

            $this->_json(array(
                'success' => FALSE,
                'message' => $e->getMessage(),
                'divcode' => $divcode,
            ));
            return;
        }

        try
        {
            $saved = $access_model->saveOrganizationAccess($divcode, $ids);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'restmancard: не удалось сохранить категории доступа организации :divcode: :message' . PHP_EOL . $e->getTraceAsString(), array(
                ':divcode' => $divcode,
                ':message' => $e->getMessage(),
            ));

            $this->_json($this->_save_access_failure($e, $divcode));
            return;
        }

        // Ответ PUT может быть пустым — для панели важен фактический набор.
        if (empty($saved) AND ! empty($ids))
        {
            $saved = $access_model->applyDiff(array(), $ids, array());
        }

        $result = array(
            'success'     => TRUE,
            'message'     => 'Набор категорий доступа сохранён',
            'divcode'     => $divcode,
            'scope'       => $scope,
            'assigned'    => $saved,
            'count'       => count($saved),
            'added'       => $diff['added'],
            'removed'     => $diff['removed'],
            'changed'     => $diff['changed'],
            'diff_loaded' => ($before_ids !== NULL),
        );

        if ($scope === 'cascade' AND $diff['changed'] AND $targets !== NULL)
        {
            try
            {
                $cascade = $this->_apply_access_cascade(
                    $access_model,
                    $targets,
                    $diff['added'],
                    $diff['removed']
                );

                $result['message'] = $this->_cascade_access_message($cascade['summary']);
                $result['cascade'] = $cascade['summary'];

                if ( ! empty($cascade['warnings']))
                {
                    $result['warnings'] = $cascade['warnings'];
                }

                if ( ! empty($cascade['errors']))
                {
                    $result['cascade_errors'] = $cascade['errors'];
                }
            }
            catch (Exception $e)
            {
                // Набор организации уже сохранён: сообщаем об успехе с
                // предупреждением, а не подменяем его ошибкой сохранения.
                Kohana::$log->add(Log::ERROR, 'restmancard: набор организации :divcode сохранён, но каскад не выполнен: :message' . PHP_EOL . $e->getTraceAsString(), array(
                    ':divcode' => $divcode,
                    ':message' => $e->getMessage(),
                ));

                $result['warnings'] = array(
                    'Набор организации сохранён, но применить его к дочерним организациям и сотрудникам не удалось: '
                    . $e->getMessage(),
                );
            }
        }
        elseif ($scope === 'cascade')
        {
            $result['message'] = 'Набор категорий доступа не изменился — применять к дочерним организациям и сотрудникам нечего.';
        }

        $this->_json($result);
    }

    /**
     * Цели каскадного изменения набора категорий организации.
     *
     * Возвращает все дочерние организации (рекурсивно) и табельные номера
     * сотрудников, закреплённых непосредственно за выбранной организацией.
     * Сотрудники дочерних организаций в список не попадают: их личный набор
     * по условию задачи не меняется.
     *
     * Список организаций перечитывается (кеш orgs_v2 сбрасывается), чтобы
     * каскад не пропустил подразделение, добавленное после открытия страницы.
     * Список сотрудников читается из Firebird по PEOPLE.ID_ORG.
     *
     * @param   string                        $divcode
     * @param   Model_Restmancard_Access      $access_model
     * @return  array  array(
     *                     'id'       => int,
     *                     'divcode'  => string,
     *                     'children' => array,
     *                     'tabnums'  => array,
     *                 )
     * @throws  Kohana_Exception
     */
    protected function _access_cascade_targets($divcode, $access_model)
    {
        $this->_api()->forget(Model_Restmancard_Api::CACHE_PREFIX . 'orgs_v2');

        $org_model = $this->_org();
        $organization = $org_model->getOrganizationByDivcode($divcode);

        if ($organization === NULL)
        {
            throw new Kohana_Exception(
                'Организация :divcode не найдена в списке организаций — обновите страницу и повторите сохранение',
                array(':divcode' => $divcode)
            );
        }

        $children = $org_model->descendantDivcodes($divcode);

        $people = $this->_people()->getTabnumsByOrganization((int) $organization['ID']);

        if ($people['error'] !== NULL)
        {
            throw new Kohana_Exception(
                'Не удалось получить сотрудников организации :divcode: :message. '
                . 'Каскадное изменение не выполнено, набор организации не изменён.',
                array(
                    ':divcode' => $divcode,
                    ':message' => $people['error'],
                )
            );
        }

        $limits = $access_model->cascadeLimits();

        if (count($children) > $limits['max_orgs'] OR count($people['tabnums']) > $limits['max_people'])
        {
            $details = array();

            if (count($children) > $limits['max_orgs'])
            {
                $details[] = 'дочерних организаций — ' . count($children)
                    . ' (лимит ' . $limits['max_orgs'] . ')';
            }

            if (count($people['tabnums']) > $limits['max_people'])
            {
                $details[] = 'сотрудников — ' . count($people['tabnums'])
                    . ' (лимит ' . $limits['max_people'] . ')';
            }

            throw new Kohana_Exception(
                'Каскадное изменение не выполнено: слишком много элементов — '
                . implode(', ', $details)
                . '. Увеличьте access.cascade_max_orgs / access.cascade_max_people '
                . 'или выберите «Только к организации». Набор организации не изменён.'
            );
        }

        return array(
            'id'       => (int) $organization['ID'],
            'divcode'  => (string) $organization['DIVCODE'],
            'children' => $children,
            'tabnums'  => $people['tabnums'],
        );
    }

    /**
     * Применить разницу набора категорий к дочерним организациям и сотрудникам.
     *
     * Для каждой дочерней организации и каждого сотрудника набор читается
     * целиком, из него добавляются/снимаются только категории из разницы,
     * после чего набор сохраняется через PUT. Если у элемента разницы нет
     * (категория уже была или её уже нет), запрос на запись не выполняется.
     *
     * Ошибка одного элемента не прерывает остальные: она попадает в
     * 'errors', а в 'warnings' выводится сводка. Итог всегда возвращается,
     * чтобы контроллер мог сообщить о частичном успехе.
     *
     * @param   Model_Restmancard_Access  $access_model
     * @param   array                     $targets
     * @param   array                     $added
     * @param   array                     $removed
     * @return  array  array(
     *                     'summary'  => array,
     *                     'warnings' => array,
     *                     'errors'   => array,
     *                 )
     */
    protected function _apply_access_cascade($access_model, array $targets, array $added, array $removed)
    {
        $summary = array(
            'children_total'     => count($targets['children']),
            'children_updated'   => 0,
            'children_unchanged' => 0,
            'children_failed'    => 0,
            'people_total'       => count($targets['tabnums']),
            'people_updated'     => 0,
            'people_unchanged'   => 0,
            'people_failed'      => 0,
        );

        $errors = array();

        foreach ($targets['children'] as $child_divcode)
        {
            try
            {
                $applied = $access_model->applyOrganizationAccessDiff($child_divcode, $added, $removed);

                if ($applied['changed'])
                {
                    $summary['children_updated']++;
                }
                else
                {
                    $summary['children_unchanged']++;
                }
            }
            catch (Exception $e)
            {
                $summary['children_failed']++;
                $errors[] = 'организация ' . $child_divcode . ': ' . $e->getMessage();

                Kohana::$log->add(Log::WARNING, 'restmancard: не удалось изменить категории доступа дочерней организации :divcode: :message', array(
                    ':divcode' => $child_divcode,
                    ':message' => $e->getMessage(),
                ));
            }
        }

        foreach ($targets['tabnums'] as $tabnum)
        {
            try
            {
                $applied = $access_model->applyPersonAccessDiff($tabnum, $added, $removed);

                if ($applied['changed'])
                {
                    $summary['people_updated']++;
                }
                else
                {
                    $summary['people_unchanged']++;
                }
            }
            catch (Exception $e)
            {
                $summary['people_failed']++;
                $errors[] = 'сотрудник ' . $tabnum . ': ' . $e->getMessage();

                Kohana::$log->add(Log::WARNING, 'restmancard: не удалось изменить категории доступа сотрудника :tabnum: :message', array(
                    ':tabnum'  => $tabnum,
                    ':message' => $e->getMessage(),
                ));
            }
        }

        $warnings = array();

        if ( ! empty($errors))
        {
            $warnings[] = 'Не удалось обновить часть элементов (' . count($errors) . '). '
                . 'Первые ошибки: ' . implode('; ', array_slice($errors, 0, 3))
                . (count($errors) > 3 ? ' …' : '');
        }

        return array(
            'summary'  => $summary,
            'warnings' => $warnings,
            'errors'   => array_slice($errors, 0, 20),
        );
    }

    /**
     * Текст успешного ответа каскадного сохранения.
     *
     * @param   array  $summary
     * @return  string
     */
    protected function _cascade_access_message(array $summary)
    {
        if ($summary['children_total'] === 0 AND $summary['people_total'] === 0)
        {
            return 'Набор категорий доступа сохранён. Дочерних организаций и сотрудников для обновления нет.';
        }

        $message = 'Набор категорий доступа сохранён. Дочерние организации: обновлено '
            . $summary['children_updated'] . ' из ' . $summary['children_total']
            . '; сотрудники: обновлено ' . $summary['people_updated']
            . ' из ' . $summary['people_total'] . '.';

        if ($summary['children_failed'] > 0 OR $summary['people_failed'] > 0)
        {
            $message .= ' Часть элементов обновить не удалось.';
        }

        return $message;
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
     * Родитель организации для формы и JSON.
     *
     * Главная корневая организация (organizations.root_divcode) всегда
     * показывается без родителя. Остальные организации, у которых в API
     * parent пустой, получают в форме главную организацию — так дерево и
     * форма свойств показывают одно и то же: «Все» стоит в корне, а
     * сотрудники и подразделения растут из неё.
     *
     * @param   array       $node
     * @param   string|NULL $root_divcode
     * @return  string|NULL
     */
    protected function _form_parent(array $node, $root_divcode)
    {
        $root = trim((string) $root_divcode);

        if ($root !== '' AND strcasecmp((string) $node['DIVCODE'], $root) === 0)
        {
            return NULL;
        }

        $parent = $node['PARENT'];

        if ($parent !== NULL AND trim((string) $parent) !== '')
        {
            return $parent;
        }

        return ($root !== '') ? $root : $parent;
    }

    /**
     * Собрать плоские данные для панели «Свойства».
     *
     * @param   array  $branch
     * @param   array  $orgs
     * @param   array  $people
     * @param   string $parent_name
     * @param   string|NULL $root_divcode
     */
    protected function _collect(array $branch, array & $orgs, array & $people, $parent_name, $root_divcode = NULL)
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
                'parent'         => $this->_form_parent($node, $root_divcode),
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

            $this->_collect($node['CHILDREN'], $orgs, $people, $node['NAME'], $root_divcode);
        }
    }

    /**
     * Собрать плоские данные сотрудников для формы restmancard/people.
     *
     * В отличие от _collect() здесь не нужны идентификаторы (карты) и список
     * сотрудников организации: важны ФИО, tabnum и организация сотрудника.
     *
     * @param   array  $branch
     * @param   array  $people
     */
    protected function _collect_people(array $branch, array & $people)
    {
        foreach ($branch as $node)
        {
            foreach ($node['PEOPLE'] as $person)
            {
                $people[$person['UID']] = array(
                    'uid'         => $person['UID'],
                    'tabnum'      => $person['TABNUM'],
                    'surname'     => $person['SURNAME'],
                    'name'        => $person['NAME'],
                    'patronymic'  => $person['PATRONYMIC'],
                    'full_name'   => $person['FULL_NAME'],
                    'org_id'      => (int) $node['ID'],
                    'org_divcode' => $node['DIVCODE'],
                    'org_name'    => $node['NAME'],
                    'org_path'    => $node['PATH'],
                    'org_known'   => empty($node['IS_PEOPLE_ROOT']),
                );
            }

            $this->_collect_people($node['CHILDREN'], $people);
        }
    }

    /**
     * Собрать плоские данные организаций для формы restmancard/organizations.
     *
     * @param   array  $branch
     * @param   array  $orgs
     * @param   string $parent_name
     * @param   string|NULL $root_divcode
     */
    protected function _collect_orgs(array $branch, array & $orgs, $parent_name, $root_divcode = NULL)
    {
        foreach ($branch as $node)
        {
            $orgs[$node['UID']] = array(
                'uid'            => $node['UID'],
                'id'             => (int) $node['ID'],
                'divcode'        => $node['DIVCODE'],
                'name'           => $node['NAME'],
                'parent'         => $this->_form_parent($node, $root_divcode),
                'parent_name'    => (string) $parent_name,
                'flag'           => (int) $node['FLAG'],
                'created_at'     => $this->_format_date($node['CREATED_AT']),
                'updated_at'     => $this->_format_date($node['UPDATED_AT']),
                'path'           => $node['PATH'],
                'level'          => (int) $node['LEVEL'],
                'children_count' => (int) $node['CHILDREN_COUNT'],
                'total_children' => (int) $node['TOTAL_CHILDREN'],
            );

            $this->_collect_orgs($node['CHILDREN'], $orgs, $node['NAME'], $root_divcode);
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
