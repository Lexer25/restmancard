<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Model_Restmancard_Access — категории доступа из REST API v2.
 *
 * Контракт:
 *   GET /api/v2/Access/GetList?pageIndex=0&pageSize=1000
 *       -> {context: [{id_accessname, name}], pageCount, ...}
 *   GET /api/v2/organizations/{divcode}/accessnames
 *       -> [id_accessname, ...]
 *   PUT /api/v2/organizations/{divcode}/accessnames
 *       <- {accessnames: [id_accessname, ...]}
 *       -> [id_accessname, ...]   (полная замена связей в SS_ACCESSORG)
 *   GET /api/v2/Access/GetPeople?id_accessname=...&pageIndex=0&pageSize=1000
 *       -> {context: [{tabnum}], pageCount, ...}
 *   GET /api/v2/Person/Access?tabnum=...
 *       -> [id_accessname, ...]   (набор сотрудника, сервис 1.6.0)
 *   PUT /api/v2/Person/Access
 *       <- {tabnum, accessnames: [id_accessname, ...]}
 *       -> [id_accessname, ...]   (полная замена связей в SS_ACCESSUSER)
 *
 * У API v1.5.0 не было метода «категории одного сотрудника», поэтому
 * обратная карта «tabnum -> [ID_ACCESSNAME]» строится один раз: для каждой
 * категории запрашивается Access/GetPeople и из ответа собираются tabnum.
 * Карта кешируется, чтобы выбор сотрудника в дереве не делал десятки запросов
 * на каждый клик. С версии сервиса 1.6.0 карта используется только как
 * резерв: набор читается одним запросом GET /api/v2/Person/Access.
 *
 * Идентификатор категории 0 — допустимое значение (системная категория
 * «Check Time»), поэтому нигде не используется проверка «id > 0».
 *
 * Ошибки API не прерывают страницу: методы возвращают пустой набор и текст
 * ошибки, панель «Категории доступа» показывает его пользователю.
 */
class Model_Restmancard_Access extends Model
{
    /** Максимальный размер страницы Access/GetList и Access/GetPeople */
    const MAX_PAGE_SIZE = 1000;

    /** @var Model_Restmancard_Api */
    protected $_api;

    /** @var Config_Group */
    protected $_config;

    public function __construct()
    {
        $this->_api = Model::factory('Restmancard_Api');
        $this->_config = Kohana::$config->load('restmancard.access');
    }

    /**
     * Все категории доступа.
     *
     * @return  array  array(
     *                     'categories' => array(array('ID' => int, 'NAME' => string), ...),
     *                     'loaded'     => int,
     *                     'truncated'  => bool,
     *                     'error'      => string|NULL,
     *                 )
     */
    public function getCategories()
    {
        $key = Model_Restmancard_Api::CACHE_PREFIX . 'access_categories';
        $lifetime = (int) $this->_setting('cache_lifetime', 60);

        try
        {
            $bundle = $this->_api->remember(
                $key,
                $lifetime,
                array($this, 'fetchCategories')
            );
        }
        catch (Exception $e)
        {
            return $this->_empty_categories($e->getMessage());
        }

        $result = $this->_normalize_categories_bundle($bundle);

        // Ошибку не кешируем: при следующем открытии страницы повторим запрос
        if ($result['error'] !== NULL)
        {
            $this->_api->forget($key);
        }

        return $result;
    }

    /**
     * Загрузить все страницы GET /api/v2/Access/GetList.
     *
     * @return  array
     * @throws  Kohana_Exception
     */
    public function fetchCategories()
    {
        $page_size = $this->_page_size('page_size');
        $max_pages = $this->_max_pages('max_pages');

        $categories = array();
        $page = 0;
        $page_count = 1;
        $truncated = FALSE;

        do
        {
            $response = $this->_api->get('/api/v2/Access/GetList', array(
                'pageIndex' => $page,
                'pageSize'  => $page_size,
            ));

            if ( ! is_array($response))
            {
                break;
            }

            $rows = $this->_rows($response);

            foreach ($rows as $row)
            {
                $category = $this->_normalize_category($row);

                if ($category !== NULL)
                {
                    $categories[] = $category;
                }
            }

            $page_count = (int) Arr::get($response, 'pageCount', 0);
            $page++;
        }
        while ($page < $page_count AND $page < $max_pages AND ! empty($rows));

        if ($page < $page_count)
        {
            $truncated = TRUE;
        }

        return array(
            'categories' => $categories,
            'loaded'     => count($categories),
            'truncated'  => $truncated,
        );
    }

    /**
     * Категории доступа, привязанные к организации.
     *
     * Ответ API — JSON-массив чисел, поэтому 404 (организация не найдена)
     * и прочие ошибки возвращаются как текст в 'error'.
     *
     * @param   string  $divcode
     * @return  array  array('ids' => array, 'error' => string|NULL)
     */
    public function getOrganizationAccess($divcode)
    {
        $divcode = trim((string) $divcode);

        if ($divcode === '')
        {
            return array(
                'ids'   => array(),
                'error' => 'Не указан код организации (divcode)',
            );
        }

        try
        {
            $response = $this->_api->get(
                '/api/v2/organizations/' . rawurlencode($divcode) . '/accessnames'
            );
        }
        catch (Exception $e)
        {
            return array(
                'ids'   => array(),
                'error' => $e->getMessage(),
            );
        }

        return array(
            'ids'   => $this->_normalize_ids($response),
            'error' => NULL,
        );
    }

    /**
     * Сохранить набор категорий доступа организации.
     *
     * PUT /api/v2/organizations/{divcode}/accessnames заменяет набор целиком:
     * недостающие связи добавляются, лишние удаляются, пустой массив очищает
     * набор. Дубликаты API игнорирует, но они отсеиваются и здесь.
     *
     * Метод намеренно выбрасывает исключение: контроллеру нужен HTTP-статус
     * (404 — нет организации, 422 — в наборе неизвестная категория, 403 — нет
     * прав), чтобы показать причину пользователю.
     *
     * @param   string  $divcode
     * @param   array   $ids  идентификаторы ACCESSNAME.ID_ACCESSNAME
     * @return  array   сохранённый набор (нормализованные целые ID)
     * @throws  Kohana_Exception
     */
    public function saveOrganizationAccess($divcode, array $ids)
    {
        $divcode = trim((string) $divcode);

        if ($divcode === '')
        {
            throw new Kohana_Exception('Не указан код организации (divcode)');
        }

        $normalized = array();

        foreach ($ids as $id)
        {
            if ($id === NULL OR is_bool($id) OR ! is_numeric($id))
            {
                continue;
            }

            $id = (int) $id;
            $normalized[$id] = $id;
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        $response = $this->_api->put(
            '/api/v2/organizations/' . rawurlencode($divcode) . '/accessnames',
            array('accessnames' => $normalized)
        );

        return $this->_normalize_ids($response);
    }

    /**
     * Категории доступа, выданные сотруднику (по tabnum).
     *
     * Сначала читается GET /api/v2/Person/Access?tabnum=... — метод сервиса
     * REST API v2 версии 1.6.0, который отдаёт набор сотрудника одним
     * запросом. Если сервис старее и метода нет (404/405 без JSON-ошибки),
     * модуль откатывается на обратную карту Access/GetPeople.
     *
     * @param   string  $tabnum
     * @return  array  array('ids' => array, 'error' => string|NULL, 'warnings' => array)
     */
    public function getPersonAccess($tabnum)
    {
        $tabnum = trim((string) $tabnum);

        if ($tabnum === '')
        {
            return array(
                'ids'      => array(),
                'error'    => 'Не указан табельный номер сотрудника (tabnum)',
                'warnings' => array(),
            );
        }

        $direct = $this->getPersonAccessDirect($tabnum);

        if ($direct['error'] === NULL)
        {
            return array(
                'ids'      => $direct['ids'],
                'error'    => NULL,
                'warnings' => array(),
            );
        }

        // Ошибка нового метода означает реальную проблему (нет сотрудника,
        // нет прав, сервис недоступен) — подменять её пустым набором нельзя.
        if ( ! $this->_person_access_endpoint_absent())
        {
            return array(
                'ids'      => array(),
                'error'    => $direct['error'],
                'warnings' => array(),
            );
        }

        // Старый сервис: метода нет — строим карту по Access/GetPeople.
        $map = $this->getPersonAccessMap();

        if ($map['error'] !== NULL)
        {
            return array(
                'ids'      => array(),
                'error'    => $map['error'],
                'warnings' => $map['warnings'],
            );
        }

        $key = $this->_lower($tabnum);
        $ids = isset($map['by_tabnum_lower'][$key])
            ? $map['by_tabnum_lower'][$key]
            : array();

        return array(
            'ids'      => $ids,
            'error'    => NULL,
            'warnings' => $map['warnings'],
        );
    }

    /**
     * Набор категорий доступа сотрудника одним запросом.
     *
     * GET /api/v2/Person/Access?tabnum=... — ответ JSON-массив
     * идентификаторов ID_ACCESSNAME.
     *
     * @param   string  $tabnum
     * @return  array  array('ids' => array, 'error' => string|NULL)
     */
    public function getPersonAccessDirect($tabnum)
    {
        $tabnum = trim((string) $tabnum);

        if ($tabnum === '')
        {
            return array(
                'ids'   => array(),
                'error' => 'Не указан табельный номер сотрудника (tabnum)',
            );
        }

        try
        {
            $response = $this->_api->get('/api/v2/Person/Access', array(
                'tabnum' => $tabnum,
            ));
        }
        catch (Exception $e)
        {
            return array(
                'ids'   => array(),
                'error' => $e->getMessage(),
            );
        }

        return array(
            'ids'   => $this->_normalize_ids($response),
            'error' => NULL,
        );
    }

    /**
     * Сохранить набор категорий доступа сотрудника.
     *
     * PUT /api/v2/Person/Access заменяет набор целиком:
     * недостающие связи добавляются, лишние удаляются, пустой массив
     * очищает набор. Дубликаты отсеиваются и здесь.
     *
     * Метод намеренно выбрасывает исключение: контроллеру нужен HTTP-статус
     * (404 — нет сотрудника, 422 — в наборе неизвестная категория, 403 — нет
     * прав), чтобы показать причину пользователю.
     *
     * @param   string  $tabnum
     * @param   array   $ids  идентификаторы ACCESSNAME.ID_ACCESSNAME
     * @return  array   сохранённый набор (нормализованные целые ID)
     * @throws  Kohana_Exception
     */
    public function savePersonAccess($tabnum, array $ids)
    {
        $tabnum = trim((string) $tabnum);

        if ($tabnum === '')
        {
            throw new Kohana_Exception('Не указан табельный номер сотрудника (tabnum)');
        }

        $normalized = array();

        foreach ($ids as $id)
        {
            if ($id === NULL OR is_bool($id) OR ! is_numeric($id))
            {
                continue;
            }

            $id = (int) $id;

            // Отрицательных категорий нет; 0 — «Check Time» и допустим.
            if ($id < 0)
            {
                continue;
            }

            $normalized[$id] = $id;
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        $response = $this->_api->put('/api/v2/Person/Access', array(
            'tabnum'      => $tabnum,
            'accessnames' => $normalized,
        ));

        // Обратная карта «tabnum -> категории» устарела: в новых сервисах
        // она не используется, но старая версия могла её закешировать.
        $this->_api->forget(Model_Restmancard_Api::CACHE_PREFIX . 'access_people_map');

        return $this->_normalize_ids($response);
    }

    /**
     * Метод GET /api/v2/Person/Access отсутствует в сервисе?
     *
     * Старый сервис отвечает на неизвестный маршрут 404 без JSON-ошибки,
     * новый — 404 с кодом NOT_FOUND (сотрудник не найден). Различаем их,
     * чтобы откатиться на карту только при отсутствии метода.
     *
     * @return  bool
     */
    protected function _person_access_endpoint_absent()
    {
        $error = $this->_api->lastError();

        if ( ! is_array($error))
        {
            return FALSE;
        }

        $status = (int) Arr::get($error, 'status', 0);

        if ($status !== 404 AND $status !== 405)
        {
            return FALSE;
        }

        $data = Arr::get($error, 'data', NULL);

        if (is_array($data) AND trim((string) Arr::get($data, 'error', '')) !== '')
        {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Обратная карта «tabnum -> [ID_ACCESSNAME]».
     *
     * @return  array  array(
     *                     'by_tabnum_lower' => array(tabnum_lower => array(int, ...)),
     *                     'people'          => int,
     *                     'loaded'          => int,
     *                     'warnings'        => array,
     *                     'error'           => string|NULL,
     *                 )
     */
    public function getPersonAccessMap()
    {
        $key = Model_Restmancard_Api::CACHE_PREFIX . 'access_people_map';
        $lifetime = (int) $this->_setting('cache_lifetime', 60);

        try
        {
            $bundle = $this->_api->remember(
                $key,
                $lifetime,
                array($this, 'fetchPersonAccessMap')
            );
        }
        catch (Exception $e)
        {
            return $this->_empty_person_map($e->getMessage());
        }

        $result = $this->_normalize_person_map_bundle($bundle);

        // Полный отказ API не кешируем, чтобы следующая попытка была свежей
        if ($result['error'] !== NULL)
        {
            $this->_api->forget($key);
        }

        return $result;
    }

    /**
     * Построить карту «tabnum -> категории» по Access/GetPeople.
     *
     * @return  array
     * @throws  Kohana_Exception
     */
    public function fetchPersonAccessMap()
    {
        $categories = $this->getCategories();

        if ($categories['error'] !== NULL)
        {
            return array(
                'by_tabnum_lower' => array(),
                'people'          => 0,
                'loaded'          => 0,
                'warnings'        => array(),
                'error'           => $categories['error'],
            );
        }

        $list = $categories['categories'];
        $warnings = array();
        $max_categories = (int) $this->_setting('max_categories', 500);
        $truncated = FALSE;

        if ($categories['truncated'])
        {
            $warnings[] = 'Список категорий доступа ограничен настройкой access.max_pages: '
                . 'часть сотрудников может быть показана без категорий.';
            $truncated = TRUE;
        }

        if ($max_categories > 0 AND count($list) > $max_categories)
        {
            $list = array_slice($list, 0, $max_categories);
            $warnings[] = 'Карта категорий сотрудников ограничена настройкой access.max_categories ('
                . $max_categories . '): часть категорий не проверялась.';
            $truncated = TRUE;
        }

        $by_tabnum_lower = array();
        $loaded = 0;
        $requests = 0;
        $failed = 0;
        $last_error = NULL;

        foreach ($list as $category)
        {
            $requests++;

            try
            {
                $people = $this->_fetch_category_people($category['ID']);
            }
            catch (Exception $e)
            {
                $failed++;
                $last_error = $e->getMessage();
                $warnings[] = 'Не удалось прочитать категорию «' . $category['NAME'] . '»: '
                    . $last_error;
                continue;
            }

            if ( ! empty($people['truncated']))
            {
                $warnings[] = 'Список сотрудников категории «' . $category['NAME']
                    . '» ограничен настройкой access.people_max_pages.';
                $truncated = TRUE;
            }

            foreach ($people['tabnums'] as $tabnum)
            {
                $lower = $this->_lower($tabnum);

                if ($lower === '')
                {
                    continue;
                }

                if ( ! isset($by_tabnum_lower[$lower]))
                {
                    $by_tabnum_lower[$lower] = array();
                }

                $by_tabnum_lower[$lower][] = (int) $category['ID'];
                $loaded++;
            }
        }

        // Внутри сотрудника категории не дублируются и идут по возрастанию ID
        foreach (array_keys($by_tabnum_lower) as $lower)
        {
            $ids = array_values(array_unique($by_tabnum_lower[$lower]));
            sort($ids, SORT_NUMERIC);
            $by_tabnum_lower[$lower] = $ids;
        }

        $error = NULL;

        // Все запросы к Access/GetPeople завершились ошибкой — сравнивать не с чем
        if ($requests > 0 AND $failed === $requests)
        {
            $error = ($last_error !== NULL)
                ? $last_error
                : 'REST API не вернул сотрудников ни по одной категории доступа';
        }

        return array(
            'by_tabnum_lower' => $by_tabnum_lower,
            'people'          => count($by_tabnum_lower),
            'loaded'          => $loaded,
            'warnings'        => $warnings,
            'truncated'       => $truncated,
            'error'           => $error,
        );
    }

    /**
     * Сравнить наборы категорий сотрудника и организации.
     *
     * @param   array  $person_ids
     * @param   array  $org_ids
     * @return  array  array(
     *                     'person'      => array,
     *                     'org'         => array,
     *                     'common'      => array,
     *                     'only_person' => array,
     *                     'only_org'    => array,
     *                     'different'   => bool,
     *                 )
     */
    public function compareSets(array $person_ids, array $org_ids)
    {
        $person = array();
        $org = array();

        foreach ($person_ids as $id)
        {
            if (is_numeric($id))
            {
                $id = (int) $id;
                $person[$id] = $id;
            }
        }

        foreach ($org_ids as $id)
        {
            if (is_numeric($id))
            {
                $id = (int) $id;
                $org[$id] = $id;
            }
        }

        $common = array();
        $only_person = array();
        $only_org = array();

        foreach ($person as $id => $value)
        {
            if (isset($org[$id]))
            {
                $common[] = $id;
            }
            else
            {
                $only_person[] = $id;
            }
        }

        foreach ($org as $id => $value)
        {
            if ( ! isset($person[$id]))
            {
                $only_org[] = $id;
            }
        }

        sort($common, SORT_NUMERIC);
        sort($only_person, SORT_NUMERIC);
        sort($only_org, SORT_NUMERIC);

        $person_list = array_values($person);
        $org_list = array_values($org);
        sort($person_list, SORT_NUMERIC);
        sort($org_list, SORT_NUMERIC);

        return array(
            'person'      => $person_list,
            'org'         => $org_list,
            'common'      => $common,
            'only_person' => $only_person,
            'only_org'    => $only_org,
            'different'   => ( ! empty($only_person) OR ! empty($only_org)),
        );
    }

    /**
     * Сотрудники (tabnum), которым выдана категория.
     *
     * @param   int  $id_accessname
     * @return  array  array('tabnums' => array, 'truncated' => bool)
     * @throws  Kohana_Exception
     */
    protected function _fetch_category_people($id_accessname)
    {
        $page_size = $this->_page_size('people_page_size');
        $max_pages = $this->_max_pages('people_max_pages');

        $tabnums = array();
        $page = 0;
        $page_count = 1;
        $truncated = FALSE;

        do
        {
            $response = $this->_api->get('/api/v2/Access/GetPeople', array(
                'id_accessname' => (int) $id_accessname,
                'pageIndex'     => $page,
                'pageSize'      => $page_size,
            ));

            if ( ! is_array($response))
            {
                break;
            }

            $rows = $this->_rows($response);

            foreach ($rows as $row)
            {
                $tabnum = is_array($row)
                    ? Arr::get($row, 'tabnum', Arr::get($row, 'TABNUM', Arr::get($row, 'TabNum', '')))
                    : $row;

                $tabnum = trim($this->_utf((string) $tabnum));

                if ($tabnum !== '')
                {
                    $tabnums[$tabnum] = TRUE;
                }
            }

            $page_count = (int) Arr::get($response, 'pageCount', 0);
            $page++;
        }
        while ($page < $page_count AND $page < $max_pages AND ! empty($rows));

        if ($page < $page_count)
        {
            $truncated = TRUE;
        }

        return array(
            'tabnums'   => array_keys($tabnums),
            'truncated' => $truncated,
        );
    }

    /**
     * Строки ответа GetList: context / items / data / плоский массив.
     *
     * @param   array  $response
     * @return  array
     */
    protected function _rows(array $response)
    {
        foreach (array('context', 'items', 'data') as $key)
        {
            if (isset($response[$key]) AND is_array($response[$key]))
            {
                return $response[$key];
            }
        }

        // Ответ может быть сразу массивом (например, accessnames)
        if (isset($response[0]) AND (is_array($response[0]) OR is_scalar($response[0])))
        {
            return $response;
        }

        return array();
    }

    /**
     * Привести категорию к виду ID / NAME.
     *
     * @param   mixed  $row
     * @return  array|NULL
     */
    protected function _normalize_category($row)
    {
        if ( ! is_array($row))
        {
            if (is_numeric($row))
            {
                $id = (int) $row;

                return array(
                    'ID'   => $id,
                    'NAME' => 'Категория ' . $id,
                );
            }

            return NULL;
        }

        $id = NULL;

        foreach (array('id_accessname', 'ID_ACCESSNAME', 'Id_accessname', 'id', 'ID') as $key)
        {
            if (array_key_exists($key, $row))
            {
                $id = $row[$key];
                break;
            }
        }

        if ($id === NULL OR $id === '' OR ! is_numeric($id))
        {
            return NULL;
        }

        $id = (int) $id;

        $name = '';

        foreach (array('name', 'NAME', 'Name') as $key)
        {
            if (array_key_exists($key, $row))
            {
                $name = trim($this->_utf((string) $row[$key]));
                break;
            }
        }

        if ($name === '')
        {
            $name = 'Категория ' . $id;
        }

        return array(
            'ID'   => $id,
            'NAME' => $name,
        );
    }

    /**
     * Привести ответ accessnames к массиву целых ID.
     *
     * @param   mixed  $response
     * @return  array
     */
    protected function _normalize_ids($response)
    {
        if (is_array($response))
        {
            foreach (array('context', 'items', 'data') as $key)
            {
                if (isset($response[$key]) AND is_array($response[$key]))
                {
                    $response = $response[$key];
                    break;
                }
            }
        }
        else
        {
            $response = array();
        }

        $ids = array();

        foreach ($response as $row)
        {
            $id = NULL;

            if (is_array($row))
            {
                foreach (array('id_accessname', 'ID_ACCESSNAME', 'Id_accessname', 'id', 'ID') as $key)
                {
                    if (array_key_exists($key, $row))
                    {
                        $id = $row[$key];
                        break;
                    }
                }
            }
            elseif (is_numeric($row))
            {
                $id = $row;
            }

            if ($id === NULL OR $id === '' OR ! is_numeric($id))
            {
                continue;
            }

            $id = (int) $id;
            $ids[$id] = $id;
        }

        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Пустой справочник категорий.
     *
     * @param   string|NULL  $error
     * @return  array
     */
    protected function _empty_categories($error = NULL)
    {
        return array(
            'categories' => array(),
            'loaded'     => 0,
            'truncated'  => FALSE,
            'error'      => $error,
        );
    }

    /**
     * Привести закешированный справочник к ожидаемому виду.
     *
     * @param   mixed  $bundle
     * @return  array
     */
    protected function _normalize_categories_bundle($bundle)
    {
        if ( ! is_array($bundle) OR ! isset($bundle['categories']) OR ! is_array($bundle['categories']))
        {
            return $this->_empty_categories();
        }

        return array(
            'categories' => $bundle['categories'],
            'loaded'     => isset($bundle['loaded'])
                ? (int) $bundle['loaded']
                : count($bundle['categories']),
            'truncated'  => ! empty($bundle['truncated']),
            'error'      => NULL,
        );
    }

    /**
     * Пустая карта сотрудников.
     *
     * @param   string|NULL  $error
     * @return  array
     */
    protected function _empty_person_map($error = NULL)
    {
        return array(
            'by_tabnum_lower' => array(),
            'people'          => 0,
            'loaded'          => 0,
            'warnings'        => array(),
            'truncated'       => FALSE,
            'error'           => $error,
        );
    }

    /**
     * Привести закешированную карту к ожидаемому виду.
     *
     * @param   mixed  $bundle
     * @return  array
     */
    protected function _normalize_person_map_bundle($bundle)
    {
        if ( ! is_array($bundle) OR ! isset($bundle['by_tabnum_lower'])
            OR ! is_array($bundle['by_tabnum_lower']))
        {
            return $this->_empty_person_map();
        }

        $warnings = (isset($bundle['warnings']) AND is_array($bundle['warnings']))
            ? $bundle['warnings']
            : array();

        return array(
            'by_tabnum_lower' => $bundle['by_tabnum_lower'],
            'people'          => isset($bundle['people'])
                ? (int) $bundle['people']
                : count($bundle['by_tabnum_lower']),
            'loaded'          => isset($bundle['loaded']) ? (int) $bundle['loaded'] : 0,
            'warnings'        => $warnings,
            'truncated'       => ! empty($bundle['truncated']),
            'error'           => NULL,
        );
    }

    /**
     * Размер страницы из настройки с ограничением контракта API.
     *
     * @param   string  $key
     * @return  int
     */
    protected function _page_size($key)
    {
        $value = (int) $this->_setting($key, self::MAX_PAGE_SIZE);

        if ($value < 1 OR $value > self::MAX_PAGE_SIZE)
        {
            $value = self::MAX_PAGE_SIZE;
        }

        return $value;
    }

    /**
     * Предохранитель пагинации из настройки.
     *
     * @param   string  $key
     * @return  int
     */
    protected function _max_pages($key)
    {
        $value = (int) $this->_setting($key, 10);

        return ($value < 1) ? 1 : $value;
    }

    /**
     * Значение настройки access с учётом типа конфигурации.
     *
     * @param   string  $key
     * @param   mixed   $default
     * @return  mixed
     */
    protected function _setting($key, $default = NULL)
    {
        if ($this->_config instanceof Config_Group)
        {
            return $this->_config->get($key, $default);
        }

        return Arr::get((array) $this->_config, $key, $default);
    }

    /**
     * Строка из API/Firebird в UTF-8.
     *
     * @param   string  $value
     * @return  string
     */
    protected function _utf($value)
    {
        $value = (string) $value;

        if ($value === '' OR preg_match('//u', $value))
        {
            return $value;
        }

        $converted = @iconv('windows-1251', 'UTF-8', $value);

        return ($converted === FALSE) ? $value : $converted;
    }

    /**
     * Нижний регистр с учётом UTF-8.
     *
     * @param   string  $value
     * @return  string
     */
    protected function _lower($value)
    {
        $value = (string) $value;

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
