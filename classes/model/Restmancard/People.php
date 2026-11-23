<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Model_Restmancard_People — сотрудники и идентификаторы из REST API v2.
 *
 * Контракт:
 *   GET    /api/v2/Person/GetList?pageIndex=0&pageSize=100
 *   GET    /api/v2/Identifier/GetList?pageIndex=0&pageSize=100
 *   GET    /api/v2/IdentifierType/GetList?pageIndex=0&pageSize=100
 *   POST   /api/v2/Person
 *   PATCH  /api/v2/Person
 *   DELETE /api/v2/Person?tabnum=...
 *
 * Особенность контракта v2: сотрудник описывается только полями
 * tabnum / surname / name / patronymic, привязка к организации в ответах
 * не передаётся (ID_ORG есть только во входных данных PATCH).
 *
 * POST /api/v2/Person принимает tabnum необязательным: если поле не передано,
 * сервис формирует номер сам как tn_<ID сотрудника>. Этим пользуется
 * добавление сотрудника с чекбоксом «Автоматически»: в теле запроса tabnum
 * отсутствует, а номер берётся из ответа API.
 *
 * Поэтому для показа сотрудников в ветках их организаций модуль читает
 * карту TABNUM -> ID_ORG из той же базы Firebird, с которой работает
 * REST-сервис (см. getOrganizationMap()). Ошибка Firebird не критична:
 * без карты все сотрудники показываются у организации
 * restmancard.organizations.people_root_divcode.
 */
class Model_Restmancard_People extends Model
{
    /** Максимальная длина tabnum (контракт API) */
    const TABNUM_MAX_LENGTH = 50;

    /** Максимальная длина surname / name / patronymic (контракт API) */
    const NAME_MAX_LENGTH = 50;

    /** @var Model_Restmancard_Api */
    protected $_api;

    /** @var Config_Group */
    protected $_config;

    /** @var Config_Group */
    protected $_identifiers_config;

    public function __construct()
    {
        $this->_api = Model::factory('Restmancard_Api');
        $this->_config = Kohana::$config->load('restmancard.people');
        $this->_identifiers_config = Kohana::$config->load('restmancard.identifiers');
    }

    /**
     * Все сотрудники (с постраничной загрузкой).
     *
     * @return  array  array('people' => array, 'truncated' => bool, 'loaded' => int)
     */
    public function getAll()
    {
        $lifetime = (int) $this->_config['cache_lifetime'];

        $bundle = $this->_api->remember(
            Model_Restmancard_Api::CACHE_PREFIX . 'people',
            $lifetime,
            array($this, 'fetchAll')
        );

        return $this->_normalize_bundle($bundle);
    }

    /**
     * Загрузить сотрудников из API (используется в remember()).
     *
     * @return  array
     */
    public function fetchAll()
    {
        $page_size = (int) $this->_config['page_size'];
        if ($page_size < 1 OR $page_size > 1000)
        {
            $page_size = 100;
        }

        $max_pages = (int) $this->_config['max_pages'];
        if ($max_pages < 1)
        {
            $max_pages = 1;
        }

        $people = array();
        $page = 0;
        $page_count = 1;
        $number = 0;
        $truncated = FALSE;

        do
        {
            $response = $this->_api->get('/api/v2/Person/GetList', array(
                'pageIndex' => $page,
                'pageSize'  => $page_size,
            ));

            if ( ! is_array($response))
            {
                break;
            }

            $rows = (isset($response['context']) AND is_array($response['context']))
                ? $response['context']
                : array();

            foreach ($rows as $row)
            {
                $number++;
                $people[] = $this->_normalize_person($row, $number);
            }

            $page_count = (int) Arr::get($response, 'pageCount', 0);
            $page++;

            if ($page_count > $max_pages)
            {
                $truncated = TRUE;
            }
        }
        while ($page < $page_count AND $page < $max_pages AND ! empty($rows));

        if ($page < $page_count)
        {
            $truncated = TRUE;
        }

        return array(
            'people'    => $people,
            'truncated' => $truncated,
            'loaded'    => count($people),
        );
    }

    /**
     * Сотрудники вместе с идентификаторами.
     *
     * Ошибка загрузки идентификаторов не критична: сотрудники
     * всё равно возвращаются, а текст ошибки попадает в 'identifiers_error'.
     *
     * @return  array
     */
    public function getAllWithIdentifiers()
    {
        $bundle = $this->getAll();
        $people = $bundle['people'];

        $identifiers = array();
        $identifiers_error = NULL;
        $identifiers_loaded = 0;
        $identifiers_truncated = FALSE;

        if ((bool) $this->_identifiers_config['enabled'])
        {
            try
            {
                $identifiers = $this->getIdentifiers();
                $identifiers_loaded = (int) $identifiers['loaded'];
                $identifiers_truncated = (bool) $identifiers['truncated'];
            }
            catch (Exception $e)
            {
                $identifiers_error = $e->getMessage();
                $identifiers = array('by_tabnum' => array(), 'by_tabnum_lower' => array());
            }
        }

        $by_tabnum = isset($identifiers['by_tabnum']) ? $identifiers['by_tabnum'] : array();
        $by_tabnum_lower = isset($identifiers['by_tabnum_lower']) ? $identifiers['by_tabnum_lower'] : array();

        foreach ($people as $index => $person)
        {
            $tabnum = $person['TABNUM'];
            $cards = array();

            if ($tabnum !== '' AND isset($by_tabnum[$tabnum]))
            {
                $cards = $by_tabnum[$tabnum];
            }
            elseif ($tabnum !== '')
            {
                $lower = $this->_lower($tabnum);
                if (isset($by_tabnum_lower[$lower]))
                {
                    $cards = $by_tabnum_lower[$lower];
                }
            }

            $people[$index]['IDENTIFIERS'] = $cards;
        }

        return array(
            'people'                => $people,
            'people_truncated'      => $bundle['truncated'],
            'people_loaded'         => $bundle['loaded'],
            'identifiers_loaded'    => $identifiers_loaded,
            'identifiers_truncated' => $identifiers_truncated,
            'identifiers_error'     => $identifiers_error,
        );
    }

    /**
     * Карта «табельный номер -> ID_ORG» для сотрудников.
     *
     * REST API v2 не возвращает организацию сотрудника в
     * GET /api/v2/Person/GetList, поэтому привязка читается из Firebird —
     * из той же базы, с которой работает REST-сервис. Для показа дерева
     * достаточно двух полей: PEOPLE.TABNUM и PEOPLE.ID_ORG.
     *
     * Ошибка БД не прерывает работу страницы: возвращается пустая карта и
     * текст ошибки в 'error'; сотрудники будут показаны у организации
     * people_root_divcode.
     *
     * @return  array  array(
     *                     'by_tabnum'       => array(tabnum => ID_ORG),
     *                     'by_tabnum_lower' => array(tabnum_lower => ID_ORG),
     *                     'loaded'          => int,
     *                     'enabled'         => bool,
     *                     'error'           => string|NULL,
     *                 )
     */
    public function getOrganizationMap()
    {
        if ( ! $this->_org_map_enabled())
        {
            return $this->_empty_org_map();
        }

        $lifetime = (int) $this->_setting('org_map_cache_lifetime', 60);

        try
        {
            $bundle = $this->_api->remember(
                Model_Restmancard_Api::CACHE_PREFIX . 'people_org',
                $lifetime,
                array($this, 'fetchOrganizationMap')
            );
        }
        catch (Exception $e)
        {
            return $this->_empty_org_map($e->getMessage());
        }

        $result = $this->_normalize_org_map_bundle($bundle);
        $result['enabled'] = TRUE;
        $result['error'] = NULL;

        return $result;
    }

    /**
     * Привязка сотрудников к организациям включена?
     *
     * @return  bool
     */
    public function orgMapEnabled()
    {
        return $this->_org_map_enabled();
    }

    /**
     * Прочитать карту TABNUM -> ID_ORG из Firebird (для remember()).
     *
     * @return  array
     * @throws  Kohana_Exception  если база недоступна
     */
    public function fetchOrganizationMap()
    {
        if ( ! class_exists('DB') OR ! class_exists('Database'))
        {
            throw new Kohana_Exception('Модуль database не подключён: карта TABNUM -> ID_ORG недоступна');
        }

        $db_name = trim((string) $this->_setting('org_map_db', 'fb'));
        if ($db_name === '')
        {
            $db_name = 'fb';
        }

        $sql = 'SELECT p.TABNUM, p.ID_ORG FROM PEOPLE p WHERE p.ID_ORG > 0';

        $id_db = (int) $this->_setting('org_map_id_db', 1);
        if ($id_db > 0)
        {
            $sql .= ' AND p.ID_DB = ' . $id_db;
        }

        if ((bool) $this->_setting('org_map_active_only', FALSE))
        {
            $sql .= ' AND p."ACTIVE" = 1';
        }

        $sql .= ' ORDER BY p.ID_ORG';

        $rows = DB::query(Database::SELECT, $sql)
            ->execute(Database::instance($db_name))
            ->as_array();

        $by_tabnum = array();
        $by_tabnum_lower = array();

        foreach ($rows as $row)
        {
            $tabnum = trim($this->_utf((string) Arr::get($row, 'TABNUM', Arr::get($row, 'tabnum', ''))));
            $id_org = (int) Arr::get($row, 'ID_ORG', Arr::get($row, 'id_org', 0));

            if ($tabnum === '' OR $id_org <= 0)
            {
                continue;
            }

            $by_tabnum[$tabnum] = $id_org;
            $by_tabnum_lower[$this->_lower($tabnum)] = $id_org;
        }

        return array(
            'by_tabnum'       => $by_tabnum,
            'by_tabnum_lower' => $by_tabnum_lower,
            'loaded'          => count($by_tabnum),
        );
    }

    /**
     * Идентификаторы, сгруппированные по tabnum.
     *
     * @return  array
     */
    public function getIdentifiers()
    {
        $lifetime = (int) $this->_identifiers_config['cache_lifetime'];

        $bundle = $this->_api->remember(
            Model_Restmancard_Api::CACHE_PREFIX . 'identifiers',
            $lifetime,
            array($this, 'fetchIdentifiers')
        );

        return $this->_normalize_identifiers_bundle($bundle);
    }

    /**
     * Загрузить идентификаторы из API (используется в remember()).
     *
     * @return  array
     */
    public function fetchIdentifiers()
    {
        $page_size = (int) $this->_identifiers_config['page_size'];
        if ($page_size < 1 OR $page_size > 1000)
        {
            $page_size = 100;
        }

        $max_pages = (int) $this->_identifiers_config['max_pages'];
        if ($max_pages < 1)
        {
            $max_pages = 1;
        }

        $types = $this->getIdentifierTypes();

        $by_tabnum = array();
        $by_tabnum_lower = array();
        $loaded = 0;
        $page = 0;
        $page_count = 1;
        $truncated = FALSE;

        do
        {
            $response = $this->_api->get('/api/v2/Identifier/GetList', array(
                'pageIndex' => $page,
                'pageSize'  => $page_size,
            ));

            if ( ! is_array($response))
            {
                break;
            }

            $rows = (isset($response['context']) AND is_array($response['context']))
                ? $response['context']
                : array();

            foreach ($rows as $row)
            {
                $tabnum = trim((string) Arr::get($row, 'tabnum', Arr::get($row, 'TabNum', '')));
                $card = trim((string) Arr::get($row, 'id_card', Arr::get($row, 'Id_card', '')));

                if ($card === '')
                {
                    continue;
                }

                $type_id = (int) Arr::get($row, 'id_cardtype', Arr::get($row, 'Id_cardtype', 0));
                $type_name = isset($types[$type_id]) ? $types[$type_id] : ($type_id > 0 ? 'Тип ' . $type_id : 'Неизвестный тип');

                $card_data = array(
                    'ID_CARD'    => $card,
                    'TYPE_ID'    => $type_id,
                    'TYPE_NAME'  => $type_name,
                    'TYPE_SHORT' => $this->_card_short($type_id, $type_name),
                    'CARD_CLASS' => $this->_card_class($type_id),
                );

                $loaded++;

                if ($tabnum === '')
                {
                    continue;
                }

                $by_tabnum[$tabnum][] = $card_data;
                $by_tabnum_lower[$this->_lower($tabnum)][] = $card_data;
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
            'by_tabnum'       => $by_tabnum,
            'by_tabnum_lower' => $by_tabnum_lower,
            'loaded'          => $loaded,
            'truncated'       => $truncated,
        );
    }

    /**
     * Справочник типов идентификаторов: ID => название.
     *
     * @return  array
     */
    public function getIdentifierTypes()
    {
        $lifetime = (int) $this->_identifiers_config['cache_lifetime'];

        $types = $this->_api->remember(
            Model_Restmancard_Api::CACHE_PREFIX . 'idtypes',
            $lifetime,
            array($this, 'fetchIdentifierTypes')
        );

        return is_array($types) ? $types : array();
    }

    /**
     * Загрузить типы идентификаторов из API.
     *
     * @return  array
     */
    public function fetchIdentifierTypes()
    {
        $types = array();

        $response = $this->_api->get('/api/v2/IdentifierType/GetList', array(
            'pageIndex' => 0,
            'pageSize'  => 100,
        ));

        if (is_array($response) AND isset($response['context']) AND is_array($response['context']))
        {
            foreach ($response['context'] as $row)
            {
                $id = (int) Arr::get($row, 'id', 0);
                if ($id > 0)
                {
                    $types[$id] = (string) Arr::get($row, 'name', 'Тип ' . $id);
                }
            }
        }

        return $types;
    }

    /**
     * Пользователь попросил сформировать tabnum автоматически (чекбокс)?
     *
     * @param   array  $data
     * @return  bool
     */
    public function isTabnumAutoRequested(array $data)
    {
        $flag = Arr::get($data, 'tabnum_auto', 0);

        if (is_string($flag))
        {
            $flag = strtolower(trim($flag));

            return in_array($flag, array('1', 'true', 'on', 'yes', 'y'), TRUE);
        }

        return (bool) $flag;
    }

    /**
     * Проверить данные нового сотрудника по контракту API.
     *
     * Обязательна только фамилия. Табельный номер можно не указывать:
     * если включён чекбокс «Автоматически» (tabnum_auto) или поле пустое,
     * номер сформирует createPerson()/API.
     *
     * @param   array  $data
     * @return  array  ошибки в виде field => message; пустой массив — данные корректны
     */
    public function validatePerson(array $data)
    {
        $errors = array();

        $surname = trim((string) Arr::get($data, 'surname', ''));
        $name = trim((string) Arr::get($data, 'name', ''));
        $patronymic = trim((string) Arr::get($data, 'patronymic', ''));
        $tabnum = trim((string) Arr::get($data, 'tabnum', ''));
        $auto = $this->isTabnumAutoRequested($data);

        if ($surname === '')
        {
            $errors['surname'] = 'Введите фамилию сотрудника';
        }
        elseif ($this->_length($surname) > self::NAME_MAX_LENGTH)
        {
            $errors['surname'] = 'Фамилия не должна превышать ' . self::NAME_MAX_LENGTH . ' символов';
        }

        foreach (array('name' => 'Имя', 'patronymic' => 'Отчество') as $field => $label)
        {
            $value = ($field === 'name') ? $name : $patronymic;

            if ($this->_length($value) > self::NAME_MAX_LENGTH)
            {
                $errors[$field] = $label . ' не должно превышать ' . self::NAME_MAX_LENGTH . ' символов';
            }
        }

        // Ручной номер проверяем только когда автогенерация не запрошена
        if ( ! $auto AND $tabnum !== '')
        {
            if ($this->_length($tabnum) > self::TABNUM_MAX_LENGTH)
            {
                $errors['tabnum'] = 'Табельный номер не должен превышать ' . self::TABNUM_MAX_LENGTH . ' символов';
            }
            elseif (preg_match('/[\x00-\x1F\x7F]/', $tabnum))
            {
                $errors['tabnum'] = 'Табельный номер не должен содержать управляющих символов';
            }
            elseif ($this->_tabnum_exists($tabnum))
            {
                $errors['tabnum'] = 'Сотрудник с таким табельным номером уже существует';
            }
        }

        return $errors;
    }

    /**
     * Создать сотрудника через POST /api/v2/Person.
     *
     * Тело запроса PersonV2PostSee содержит только tabnum, surname, name и
     * patronymic. Организация в этом запросе не передаётся — привязка
     * выполняется отдельным PATCH (см. assignToOrganization()).
     *
     * Если запрошена автогенерация номера (tabnum_auto или пустой tabnum),
     * поле tabnum в тело не попадает: сервис формирует его сам как
     * tn_<ID сотрудника> и возвращает в ответе.
     *
     * @param   array  $data  tabnum, tabnum_auto, surname, name, patronymic
     * @return  array  созданный сотрудник во внутреннем виде
     * @throws  Kohana_Exception
     */
    public function createPerson(array $data)
    {
        $tabnum = trim((string) Arr::get($data, 'tabnum', ''));

        // Пустой номер — тоже повод сформировать его автоматически
        $auto = ($this->isTabnumAutoRequested($data) OR $tabnum === '');

        $surname = trim((string) Arr::get($data, 'surname', ''));
        $name = trim((string) Arr::get($data, 'name', ''));
        $patronymic = trim((string) Arr::get($data, 'patronymic', ''));

        if ( ! $auto)
        {
            if ($this->_length($tabnum) > self::TABNUM_MAX_LENGTH)
            {
                throw new Kohana_Exception('Табельный номер не должен превышать :max символов', array(
                    ':max' => self::TABNUM_MAX_LENGTH,
                ));
            }
        }

        if ($surname === '')
        {
            throw new Kohana_Exception('Не указана фамилия сотрудника (surname)');
        }

        foreach (array('surname' => $surname, 'name' => $name, 'patronymic' => $patronymic) as $field => $value)
        {
            if ($this->_length($value) > self::NAME_MAX_LENGTH)
            {
                throw new Kohana_Exception('Поле :field не должно превышать :max символов', array(
                    ':field' => $field,
                    ':max'   => self::NAME_MAX_LENGTH,
                ));
            }
        }

        $payload = array(
            'surname'    => $surname,
            'name'       => $name,
            'patronymic' => $patronymic,
        );

        if ( ! $auto)
        {
            $payload['tabnum'] = $tabnum;
        }

        $response = $this->_post_person($payload, $auto);

        $this->_forget_people_cache();

        // API возвращает {"tabnum":"..."}; на всякий случай поддерживаем
        // и пустой ответ — тогда используем отправленный номер.
        $created_tabnum = (is_array($response) AND isset($response['tabnum']))
            ? trim((string) $response['tabnum'])
            : '';

        if ($created_tabnum === '')
        {
            if ($auto)
            {
                throw new Kohana_Exception(
                    'REST API создал сотрудника (:surname), но не вернул табельный номер (tabnum). Проверьте сотрудника в API и удалите лишнюю запись.',
                    array(':surname' => $surname)
                );
            }

            $created_tabnum = $tabnum;
        }

        return array(
            'TABNUM'           => $created_tabnum,
            'SURNAME'          => $surname,
            'NAME'             => $name,
            'PATRONYMIC'       => $patronymic,
            'FULL_NAME'        => trim($surname . ' ' . $name . ' ' . $patronymic),
            'TABNUM_GENERATED' => $auto,
        );
    }

    /**
     * Создать сотрудника и привязать его к организации.
     *
     * Основной сценарий веб-формы «Добавить сотрудника»: POST создаёт запись
     * (в API она попадает в ID_ORG = 1), затем PATCH переставляет её в
     * выбранную организацию. Если привязка не удалась, созданный сотрудник
     * удаляется, чтобы в API не осталось «висящей» записи.
     *
     * @param   array  $data  surname, name, patronymic, tabnum, tabnum_auto, id_org
     * @return  array  созданный сотрудник + ID_ORG
     * @throws  Kohana_Exception
     */
    public function createPersonWithOrganization(array $data)
    {
        $id_org = (int) Arr::get($data, 'id_org', 0);

        if ($id_org <= 0)
        {
            throw new Kohana_Exception('Не выбрана организация для нового сотрудника (id_org)');
        }

        $person = $this->createPerson($data);

        try
        {
            $updated = $this->assignToOrganization($person['TABNUM'], $id_org);

            if ($updated === 0)
            {
                throw new Kohana_Exception('REST API не подтвердил привязку сотрудника к организации (id_org = :id)', array(
                    ':id' => $id_org,
                ));
            }
        }
        catch (Exception $e)
        {
            // Откатываем создание: сотрудник не должен остаться без организации
            $rolled_back = FALSE;

            try
            {
                $this->deletePerson($person['TABNUM']);
                $rolled_back = TRUE;
            }
            catch (Exception $rollback_error)
            {
                // Ниже сообщим, что откатить не удалось
            }

            throw new Kohana_Exception(
                'Не удалось привязать сотрудника :tabnum к организации: :message '
                . ($rolled_back ? 'Созданный сотрудник удалён.' : 'Удалить созданного сотрудника не удалось — проверьте его в API.'),
                array(
                    ':tabnum'  => $person['TABNUM'],
                    ':message' => $e->getMessage(),
                )
            );
        }

        $person['ID_ORG'] = $id_org;

        return $person;
    }

    /**
     * Привязать сотрудника к организации через PATCH /api/v2/Person.
     *
     * @param   string  $tabnum
     * @param   int     $id_org
     * @return  int|NULL  число изменённых записей; NULL — API не вернул число
     * @throws  Kohana_Exception
     */
    public function assignToOrganization($tabnum, $id_org)
    {
        $tabnum = trim((string) $tabnum);
        $id_org = (int) $id_org;

        if ($tabnum === '')
        {
            throw new Kohana_Exception('Не указан табельный номер сотрудника (tabnum)');
        }

        if ($id_org <= 0)
        {
            throw new Kohana_Exception('Не указан идентификатор организации (id_org)');
        }

        $response = $this->_api->patch('/api/v2/Person', array(
            'tabnum' => $tabnum,
            'id_org' => $id_org,
        ));

        $this->_forget_people_cache();

        return is_numeric($response) ? (int) $response : NULL;
    }

    /**
     * Проверить данные изменения ФИО сотрудника.
     *
     * Проверяются только поля формы: сам сотрудник определяется по tabnum,
     * а его существование окончательно подтверждает API (404).
     *
     * @param   array  $data  tabnum, surname, name, patronymic
     * @return  array  ошибки в виде field => message; пустой массив — данные корректны
     */
    public function validatePersonUpdate(array $data)
    {
        $errors = array();

        $tabnum = trim((string) Arr::get($data, 'tabnum', ''));

        if ($tabnum === '')
        {
            $errors['tabnum'] = 'Не выбран сотрудник для изменения';
        }
        elseif ($this->_length($tabnum) > self::TABNUM_MAX_LENGTH)
        {
            $errors['tabnum'] = 'Табельный номер не должен превышать ' . self::TABNUM_MAX_LENGTH . ' символов';
        }

        $surname = trim((string) Arr::get($data, 'surname', ''));

        if ($surname === '')
        {
            $errors['surname'] = 'Введите фамилию сотрудника';
        }
        elseif ($this->_length($surname) > self::NAME_MAX_LENGTH)
        {
            $errors['surname'] = 'Фамилия не должна превышать ' . self::NAME_MAX_LENGTH . ' символов';
        }

        foreach (array('name' => 'Имя', 'patronymic' => 'Отчество') as $field => $label)
        {
            $value = trim((string) Arr::get($data, $field, ''));

            if ($this->_length($value) > self::NAME_MAX_LENGTH)
            {
                $errors[$field] = $label . ' не должно превышать ' . self::NAME_MAX_LENGTH . ' символов';
            }
        }

        return $errors;
    }

    /**
     * Изменить ФИО сотрудника через PUT /api/v2/Person.
     *
     * Контракт (сервис REST API v2 версии 1.6.0):
     *   PUT /api/v2/Person
     *   <- {tabnum, surname, name, patronymic}
     *   -> {tabnum, surname, name, patronymic}
     *
     * Тело заменяет ФИО целиком: фамилия обязательна, имя и отчество могут
     * быть пустыми. Табельный номер (tabnum) неизменяем — он служит ключом.
     *
     * @param   string  $tabnum
     * @param   array   $data  surname, name, patronymic
     * @return  array   сохранённое ФИО во внутреннем виде
     * @throws  Kohana_Exception
     */
    public function updatePerson($tabnum, array $data)
    {
        $tabnum = trim((string) $tabnum);

        if ($tabnum === '')
        {
            throw new Kohana_Exception('Не указан табельный номер сотрудника (tabnum)');
        }

        if ($this->_length($tabnum) > self::TABNUM_MAX_LENGTH)
        {
            throw new Kohana_Exception('Табельный номер не должен превышать :max символов', array(
                ':max' => self::TABNUM_MAX_LENGTH,
            ));
        }

        $surname = trim((string) Arr::get($data, 'surname', ''));
        $name = trim((string) Arr::get($data, 'name', ''));
        $patronymic = trim((string) Arr::get($data, 'patronymic', ''));

        if ($surname === '')
        {
            throw new Kohana_Exception('Не указана фамилия сотрудника (surname)');
        }

        foreach (array('surname' => $surname, 'name' => $name, 'patronymic' => $patronymic) as $field => $value)
        {
            if ($this->_length($value) > self::NAME_MAX_LENGTH)
            {
                throw new Kohana_Exception('Поле :field не должно превышать :max символов', array(
                    ':field' => $field,
                    ':max'   => self::NAME_MAX_LENGTH,
                ));
            }
        }

        $response = $this->_api->put('/api/v2/Person', array(
            'tabnum'     => $tabnum,
            'surname'    => $surname,
            'name'       => $name,
            'patronymic' => $patronymic,
        ));

        $this->_forget_people_cache();

        // Ответ может отсутствовать или быть пустым: тогда считаем, что
        // сохранены отправленные значения.
        $row = is_array($response) ? $response : array();

        $saved_tabnum = trim((string) Arr::get($row, 'tabnum', $tabnum));
        $saved_surname = trim((string) Arr::get($row, 'surname', $surname));
        $saved_name = trim((string) Arr::get($row, 'name', $name));
        $saved_patronymic = trim((string) Arr::get($row, 'patronymic', $patronymic));

        return array(
            'TABNUM'     => ($saved_tabnum !== '') ? $saved_tabnum : $tabnum,
            'SURNAME'    => $saved_surname,
            'NAME'       => $saved_name,
            'PATRONYMIC' => $saved_patronymic,
            'FULL_NAME'  => trim($saved_surname . ' ' . $saved_name . ' ' . $saved_patronymic),
        );
    }

    /**
     * Удалить сотрудника через DELETE /api/v2/Person?tabnum=...
     *
     * API удаляет сотрудника вместе с его идентификаторами.
     *
     * @param   string  $tabnum
     * @return  int     число удалённых записей (ответ API)
     * @throws  Kohana_Exception
     */
    public function deletePerson($tabnum)
    {
        $tabnum = trim((string) $tabnum);

        if ($tabnum === '')
        {
            throw new Kohana_Exception('Не указан табельный номер сотрудника (tabnum)');
        }

        $response = $this->_api->delete('/api/v2/Person', array(
            'tabnum' => $tabnum,
        ));

        $this->_forget_people_cache();

        return is_numeric($response) ? (int) $response : 0;
    }

    /**
     * Сбросить кеш сотрудников и идентификаторов после изменения данных.
     *
     * @return  void
     */
    protected function _forget_people_cache()
    {
        $this->_api->forget(Model_Restmancard_Api::CACHE_PREFIX . 'people');
        $this->_api->forget(Model_Restmancard_Api::CACHE_PREFIX . 'identifiers');
        $this->_api->forget(Model_Restmancard_Api::CACHE_PREFIX . 'people_org');
    }

    /**
     * Сотрудник с таким tabnum уже есть в загруженном списке?
     *
     * Сравнение без учёта регистра: Firebird хранит TABNUM в windows-1251,
     * а REST API возвращает его в UTF-8. Список может быть обрезан настройкой
     * people.max_pages — окончательную проверку всё равно делает API (409).
     *
     * @param   string  $tabnum
     * @return  bool
     */
    protected function _tabnum_exists($tabnum)
    {
        $needle = $this->_lower(trim((string) $tabnum));

        if ($needle === '')
        {
            return FALSE;
        }

        try
        {
            $bundle = $this->getAll();
        }
        catch (Exception $e)
        {
            // Список недоступен — уникальность проверит API при POST
            return FALSE;
        }

        foreach ($bundle['people'] as $person)
        {
            $value = trim((string) Arr::get($person, 'TABNUM', ''));

            if ($value !== '' AND $this->_lower($value) === $needle)
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * POST /api/v2/Person с повтором при 409 в режиме автогенерации номера.
     *
     * При автогенерации сервис сам подбирает свободный tn_<ID>; ответ 409
     * возможен только при гонке, поэтому запрос повторяется — API сформирует
     * следующий номер. Для ручного номера 409 сразу возвращается вызывающему.
     *
     * @param   array  $payload
     * @param   bool   $auto
     * @return  mixed
     * @throws  Kohana_Exception
     */
    protected function _post_person(array $payload, $auto)
    {
        $retry_limit = (int) $this->_setting('tabnum_retry_on_conflict', 3);

        if ($retry_limit < 1)
        {
            $retry_limit = 1;
        }

        for ($attempt = 1; ; $attempt++)
        {
            try
            {
                return $this->_api->post('/api/v2/Person', $payload);
            }
            catch (Kohana_Exception $e)
            {
                if ( ! $auto OR $attempt >= $retry_limit OR ! $this->_is_conflict_error($e))
                {
                    throw $e;
                }

                // Номер заняли между формированием и вставкой — просим новый
            }
        }
    }

    /**
     * Ответ API означает конфликт (409)?
     *
     * @param   Exception  $e
     * @return  bool
     */
    protected function _is_conflict_error(Exception $e)
    {
        return (strpos($e->getMessage(), 'HTTP 409') !== FALSE);
    }

    /**
     * Привести сотрудника к внутреннему виду.
     *
     * @param   array  $row
     * @param   int    $number
     * @return  array
     */
    protected function _normalize_person(array $row, $number)
    {
        $tabnum = trim((string) Arr::get($row, 'tabnum', Arr::get($row, 'TabNum', '')));
        $surname = trim((string) Arr::get($row, 'surname', ''));
        $name = trim((string) Arr::get($row, 'name', ''));
        $patronymic = trim((string) Arr::get($row, 'patronymic', ''));

        $full_name = trim($surname . ' ' . $name . ' ' . $patronymic);
        if ($full_name === '')
        {
            $full_name = $tabnum !== '' ? $tabnum : '(без имени)';
        }

        return array(
            'UID'          => 'p' . $number,
            'TABNUM'       => $tabnum,
            'SURNAME'      => $surname,
            'NAME'         => $name,
            'PATRONYMIC'   => $patronymic,
            'FULL_NAME'    => $full_name,
            'SORT_KEY'     => $this->_lower($full_name),
            'IDENTIFIERS'  => array(),
        );
    }

    /**
     * Привести сотрудников к ожидаемому виду (на случай старого кеша).
     */
    protected function _normalize_bundle($bundle)
    {
        if ( ! is_array($bundle) OR ! isset($bundle['people']) OR ! is_array($bundle['people']))
        {
            return array('people' => array(), 'truncated' => FALSE, 'loaded' => 0);
        }

        return array(
            'people'    => $bundle['people'],
            'truncated' => ! empty($bundle['truncated']),
            'loaded'    => isset($bundle['loaded']) ? (int) $bundle['loaded'] : count($bundle['people']),
        );
    }

    /**
     * Привести пакет идентификаторов к ожидаемому виду.
     */
    protected function _normalize_identifiers_bundle($bundle)
    {
        if ( ! is_array($bundle) OR ! isset($bundle['by_tabnum']) OR ! is_array($bundle['by_tabnum']))
        {
            return array(
                'by_tabnum'       => array(),
                'by_tabnum_lower' => array(),
                'loaded'          => 0,
                'truncated'       => FALSE,
            );
        }

        return array(
            'by_tabnum'       => $bundle['by_tabnum'],
            'by_tabnum_lower' => isset($bundle['by_tabnum_lower']) ? $bundle['by_tabnum_lower'] : array(),
            'loaded'          => isset($bundle['loaded']) ? (int) $bundle['loaded'] : 0,
            'truncated'       => ! empty($bundle['truncated']),
        );
    }

    /**
     * Привязка сотрудников к организациям включена настройкой?
     *
     * @return  bool
     */
    protected function _org_map_enabled()
    {
        $value = $this->_setting('org_map', TRUE);

        if (is_string($value))
        {
            $value = strtolower(trim($value));

            return ! in_array($value, array('', '0', 'false', 'off', 'no', 'none'), TRUE);
        }

        return (bool) $value;
    }

    /**
     * Пустая карта сотрудников (нет данных или БД недоступна).
     *
     * @param   string  $error
     * @return  array
     */
    protected function _empty_org_map($error = NULL)
    {
        return array(
            'by_tabnum'       => array(),
            'by_tabnum_lower' => array(),
            'loaded'          => 0,
            'enabled'         => $this->_org_map_enabled(),
            'error'           => $error,
        );
    }

    /**
     * Привести карту сотрудников к ожидаемому виду (на случай старого кеша).
     *
     * @param   mixed  $bundle
     * @return  array
     */
    protected function _normalize_org_map_bundle($bundle)
    {
        if ( ! is_array($bundle) OR ! isset($bundle['by_tabnum']) OR ! is_array($bundle['by_tabnum']))
        {
            return $this->_empty_org_map();
        }

        return array(
            'by_tabnum'       => $bundle['by_tabnum'],
            'by_tabnum_lower' => isset($bundle['by_tabnum_lower']) ? $bundle['by_tabnum_lower'] : array(),
            'loaded'          => isset($bundle['loaded']) ? (int) $bundle['loaded'] : count($bundle['by_tabnum']),
            'enabled'         => $this->_org_map_enabled(),
            'error'           => NULL,
        );
    }

    /**
     * Значение настройки people с учётом типа конфигурации.
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
     * Строка из Firebird (windows-1251) в UTF-8.
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
     * CSS-класс бейджа идентификатора (как в mancard.css).
     *
     * @param   int     $type_id
     * @return  string
     */
    protected function _card_class($type_id)    {
        $map = array(
            1 => 'card-badge-rfid',
            2 => 'card-badge-fp',
            3 => 'card-badge-barcode',
            4 => 'card-badge-grz',
            5 => 'card-badge-faceid',
        );

        return isset($map[$type_id]) ? $map[$type_id] : 'card-badge-default';
    }

    /**
     * Короткая подпись типа идентификатора для дерева.
     *
     * @param   int     $type_id
     * @param   string  $type_name
     * @return  string
     */
    protected function _card_short($type_id, $type_name)
    {
        $map = array(
            1 => 'RFID',
            2 => 'FP',
            3 => 'ШК',
            4 => 'ГРЗ',
            5 => 'FaceID',
        );

        if (isset($map[$type_id]))
        {
            return $map[$type_id];
        }

        $type_name = trim((string) $type_name);

        return $type_name !== '' ? $type_name : 'ID';
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

    /**
     * Длина строки с учётом UTF-8.
     *
     * @param   string  $value
     * @return  int
     */
    protected function _length($value)
    {
        $value = (string) $value;

        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }
}
