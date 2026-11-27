<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Model_Restmancard_Org — организации из REST API v2.
 *
 * Контракт:
 *   GET /api/v2/organizations?page=1&pageSize=100&sort=name
 *   GET /api/v2/organizations/{divcode}
 *   POST /api/v2/organizations
 *   PUT /api/v2/organizations/{divcode}                  — name, parent, flag
 *   PUT /api/v2/organizations/{divcode}/accessnames      — набор категорий
 *   DELETE /api/v2/organizations/{divcode}
 *
 * Список организаций отдаётся плоским (id, divcode, name, parent, flag,
 * createdAt, updatedAt), а parent — это divcode родителя. Дерево строится
 * локально по всем страницам списка, поэтому на одну страницу приходится
 * один-два HTTP-запроса, а не запрос на каждый узел.
 *
 * Сотрудники раскладываются по узлам дерева по ID_ORG: карту
 * TABNUM -> ID_ORG отдаёт Model_Restmancard_People (Firebird), потому что
 * REST API v2 не сообщает организацию сотрудника в ответах
 * GET /api/v2/Person/GetList. Сотрудники без привязки показываются у
 * организации restmancard.organizations.people_root_divcode.
 */
class Model_Restmancard_Org extends Model
{
    /** Максимальная длина названия организации (контракт API) */
    const NAME_MAX_LENGTH = 50;

    /** Максимальная длина divcode (контракт API) */
    const DIVCODE_MAX_LENGTH = 50;

    /** Префикс автоматически сформированного divcode по умолчанию */
    const DIVCODE_DEFAULT_PREFIX = 'dv_';

    /**
     * Транслитерация кириллицы для автоматически сформированного divcode.
     *
     * Карта содержит обе раскладки, поэтому генерация не зависит от mbstring:
     * после замены остаются только латинские буквы, которые приводит к
     * нижнему регистру обычный strtolower().
     *
     * @var array
     */
    protected static $_translit = array(
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
        'е' => 'e',  'ё' => 'e',  'ж' => 'zh', 'з' => 'z',  'и' => 'i',
        'й' => 'y',  'к' => 'k',  'л' => 'l',  'м' => 'm',  'н' => 'n',
        'о' => 'o',  'п' => 'p',  'р' => 'r',  'с' => 's',  'т' => 't',
        'у' => 'u',  'ф' => 'f',  'х' => 'h',  'ц' => 'c',  'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',  'ы' => 'y',  'ь' => '',
        'э' => 'e',  'ю' => 'yu', 'я' => 'ya',

        'і' => 'i',  'ї' => 'i',  'є' => 'e',  'ґ' => 'g',  'ў' => 'u',

        'А' => 'a',  'Б' => 'b',  'В' => 'v',  'Г' => 'g',  'Д' => 'd',
        'Е' => 'e',  'Ё' => 'e',  'Ж' => 'zh', 'З' => 'z',  'И' => 'i',
        'Й' => 'y',  'К' => 'k',  'Л' => 'l',  'М' => 'm',  'Н' => 'n',
        'О' => 'o',  'П' => 'p',  'Р' => 'r',  'С' => 's',  'Т' => 't',
        'У' => 'u',  'Ф' => 'f',  'Х' => 'h',  'Ц' => 'c',  'Ч' => 'ch',
        'Ш' => 'sh', 'Щ' => 'sch', 'Ъ' => '',  'Ы' => 'y',  'Ь' => '',
        'Э' => 'e',  'Ю' => 'yu', 'Я' => 'ya',

        'І' => 'i',  'Ї' => 'i',  'Є' => 'e',  'Ґ' => 'g',  'Ў' => 'u',
    );

    /** @var Model_Restmancard_Api */
    protected $_api;

    /** @var array|Config_Group */
    protected $_config;

    /** @var array */
    protected $_warnings = array();

    /** @var array */
    protected $_stats = array();

    /** @var int */
    protected $_node_counter = 0;

    /** @var bool  последний createOrganization() сформировал divcode сам */
    protected $_divcode_generated = FALSE;

    public function __construct()
    {
        $this->_api = Model::factory('Restmancard_Api');
        $this->_config = Kohana::$config->load('restmancard.organizations');
    }

    /**
     * @return  Model_Restmancard_People
     */
    protected function _people()
    {
        return Model::factory('Restmancard_People');
    }

    /**
     * Некритичные предупреждения, собранные при построении дерева.
     *
     * @return  array
     */
    public function getWarnings()
    {
        return $this->_warnings;
    }

    /**
     * Статистика загрузки.
     *
     * @return  array
     */
    public function getStats()
    {
        return $this->_stats;
    }

    /**
     * Префикс автоматически сформированного divcode (для подсказки в форме).
     *
     * @return  string
     */
    public function getDivcodePrefix()
    {
        return $this->_divcode_prefix();
    }

    /**
     * Организация является главной корневой (organizations.root_divcode)?
     *
     * Сравнение не зависит от загруженного списка: достаточно совпадения с
     * настройкой. Это важно для запрета удаления и смены родителя, даже если
     * список организаций временно недоступен.
     *
     * @param   string  $divcode
     * @return  bool
     */
    public function isRootDivcode($divcode)
    {
        $divcode = trim((string) $divcode);

        if ($divcode === '')
        {
            return FALSE;
        }

        $configured = trim((string) $this->_setting('root_divcode', 'dv_All'));

        if ($configured !== '' AND strcasecmp($divcode, $configured) === 0)
        {
            return TRUE;
        }

        $root = $this->rootDivcode();

        return ($root !== NULL AND strcasecmp($divcode, $root) === 0);
    }

    /**
     * Код главной корневой организации.
     *
     * Настройка organizations.root_divcode (по умолчанию dv_All) задаёт
     * организацию, которая всегда показывается единственной вершиной дерева.
     * Возвращается фактическое написание кода из загруженного списка REST
     * (сравнение без учёта регистра), чтобы parent в PUT совпадал с API.
     *
     * Если настройка пуста или организации с таким кодом нет в списке,
     * возвращается NULL: правило не применяется, дерево строится по данным
     * API как раньше.
     *
     * @return  string|NULL
     */
    public function rootDivcode()
    {
        $configured = trim((string) $this->_setting('root_divcode', 'dv_All'));

        if ($configured === '')
        {
            return NULL;
        }

        try
        {
            $organizations = $this->getAllOrganizations();
        }
        catch (Exception $e)
        {
            // Список недоступен — дерево и сохранение сами сообщат об ошибке
            return NULL;
        }

        foreach ($organizations as $org)
        {
            $code = trim((string) Arr::get($org, 'DIVCODE', ''));

            if ($code !== '' AND strcasecmp($code, $configured) === 0)
            {
                return $code;
            }
        }

        return NULL;
    }

    /**
     * Признак того, что последний createOrganization() сформировал код сам.
     *
     * @return  bool
     */
    public function divcodeWasGenerated()
    {
        return (bool) $this->_divcode_generated;
    }

    /**
     * Пользователь попросил сформировать divcode автоматически (чекбокс)?
     *
     * @param   array  $data
     * @return  bool
     */
    public function isDivcodeAutoRequested(array $data)
    {
        $flag = Arr::get($data, 'divcode_auto', 0);

        if (is_string($flag))
        {
            $flag = strtolower(trim($flag));

            return in_array($flag, array('1', 'true', 'on', 'yes', 'y'), TRUE);
        }

        return (bool) $flag;
    }

    /**
     * Сформировать уникальный divcode по названию организации.
     *
     * Код получается транслитерацией названия с префиксом из настройки
     * organizations.divcode_prefix (по умолчанию dv_): «Отдел продаж» ->
     * dv_otdel_prodazh. Если код уже занят, добавляется суффикс _2, _3, ...
     * Занятость проверяется по всем загруженным организациям и по списку
     * $taken (коды, отклонённые API при создании).
     *
     * @param   string  $name   название организации
     * @param   array   $taken  дополнительно занятые коды
     * @return  string
     * @throws  Kohana_Exception  если свободный код подобрать не удалось
     */
    public function generateDivcode($name, array $taken = array())
    {
        $prefix = $this->_divcode_prefix();
        $slug = $this->_divcode_slug($name);

        if ($slug === '')
        {
            $slug = 'org';
        }

        $used = array();
        foreach ($taken as $code)
        {
            $used[strtolower(trim((string) $code))] = TRUE;
        }
        foreach ($this->_existing_divcodes() as $code)
        {
            $used[strtolower($code)] = TRUE;
        }

        $attempts = (int) $this->_setting('divcode_max_attempts', 100);
        if ($attempts < 1)
        {
            $attempts = 1;
        }

        for ($i = 0; $i < $attempts; $i++)
        {
            $suffix = ($i === 0) ? '' : '_' . ($i + 1);
            $candidate = $this->_fit_divcode($prefix, $slug, $suffix);

            if ( ! isset($used[strtolower($candidate)]))
            {
                return $candidate;
            }
        }

        // Номерные суффиксы исчерпаны — добавляем короткий случайный хвост
        for ($i = 0; $i < 20; $i++)
        {
            $suffix = '_' . substr(md5(uniqid('', TRUE)), 0, 8);
            $candidate = $this->_fit_divcode($prefix, $slug, $suffix);

            if ( ! isset($used[strtolower($candidate)]))
            {
                return $candidate;
            }
        }

        throw new Kohana_Exception('Не удалось сформировать уникальный код организации (divcode)');
    }

    /**
     * Значение настройки organizations с учётом типа конфигурации.
     *
     * Kohana::$config->load('restmancard.organizations') возвращает массив
     * (путь через точку), но Config_Group тоже поддерживается.
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
     * Префикс автоматического divcode из настройки.
     *
     * @return  string
     */
    protected function _divcode_prefix()
    {
        $prefix = trim((string) $this->_setting('divcode_prefix', self::DIVCODE_DEFAULT_PREFIX));

        if ($prefix === '' OR ! preg_match('/^[A-Za-z0-9._-]+$/', $prefix))
        {
            $prefix = self::DIVCODE_DEFAULT_PREFIX;
        }

        return $prefix;
    }

    /**
     * Транслитерация названия в часть divcode.
     *
     * @param   string  $name
     * @return  string
     */
    protected function _divcode_slug($name)
    {
        $value = strtr((string) $name, self::$_translit);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9._-]+/', '_', $value);

        if ($value === NULL)
        {
            return '';
        }

        $value = preg_replace('/_{2,}/', '_', $value);

        if ($value === NULL)
        {
            return '';
        }

        return trim($value, '._-');
    }

    /**
     * Собрать код из префикса, части названия и суффикса с учётом лимита.
     *
     * @param   string  $prefix
     * @param   string  $slug
     * @param   string  $suffix
     * @return  string
     */
    protected function _fit_divcode($prefix, $slug, $suffix = '')
    {
        $available = self::DIVCODE_MAX_LENGTH - strlen($prefix) - strlen($suffix);

        if ($available < 1)
        {
            $available = 1;
        }

        $code = $prefix . substr($slug, 0, $available) . $suffix;

        return substr($code, 0, self::DIVCODE_MAX_LENGTH);
    }

    /**
     * Коды всех загруженных организаций (для проверки уникальности).
     *
     * @return  array
     */
    protected function _existing_divcodes()
    {
        try
        {
            $items = $this->getAllOrganizations();
        }
        catch (Exception $e)
        {
            // Список недоступен — уникальность окончательно проверит API (409)
            return array();
        }

        $codes = array();
        foreach ($items as $org)
        {
            $code = trim((string) Arr::get($org, 'DIVCODE', ''));
            if ($code !== '')
            {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Код уже занят другой организацией?
     *
     * @param   string  $divcode
     * @return  bool
     */
    protected function _divcode_exists($divcode)
    {
        $needle = strtolower(trim((string) $divcode));

        foreach ($this->_existing_divcodes() as $code)
        {
            if (strtolower($code) === $needle)
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Ответ API означает конфликт (409 — код уже существует)?
     *
     * @param   Exception  $e
     * @return  bool
     */
    protected function _is_conflict_error(Exception $e)
    {
        return (strpos($e->getMessage(), 'HTTP 409') !== FALSE);
    }

    /**
     * Проверить данные новой организации по контракту API.
     *
     * divcode можно не указывать: если включён чекбокс «Автоматически» или
     * поле пустое, код сформирует createOrganization().
     *
     * @param   array  $data
     * @return  array  ошибки в виде field => message; пустой массив — данные корректны
     */
    public function validateOrganization(array $data)
    {
        $errors = array();

        $name = trim((string) Arr::get($data, 'name', ''));
        $divcode = trim((string) Arr::get($data, 'divcode', ''));
        $parent = trim((string) Arr::get($data, 'parent', ''));
        $flag = (string) Arr::get($data, 'flag', '0');
        $auto = $this->isDivcodeAutoRequested($data);

        if ($name === '')
        {
            $errors['name'] = 'Введите название организации';
        }
        elseif ($this->_length($name) > self::NAME_MAX_LENGTH)
        {
            $errors['name'] = 'Название организации не должно превышать ' . self::NAME_MAX_LENGTH . ' символов';
        }

        // Ручной код проверяем только когда автогенерация не запрошена
        if ( ! $auto AND $divcode !== '')
        {
            if ($this->_length($divcode) > self::DIVCODE_MAX_LENGTH)
            {
                $errors['divcode'] = 'Код организации не должен превышать ' . self::DIVCODE_MAX_LENGTH . ' символов';
            }
            elseif ( ! preg_match('/^[A-Za-z0-9._-]+$/', $divcode))
            {
                $errors['divcode'] = 'Код организации может содержать только латиницу, цифры, «-», «_» и «.»';
            }
            elseif ($this->_divcode_exists($divcode))
            {
                $errors['divcode'] = 'Организация с таким кодом уже существует';
            }
        }

        if ($parent !== '')
        {
            if ($this->_length($parent) > self::DIVCODE_MAX_LENGTH
                OR ! preg_match('/^[A-Za-z0-9._-]+$/', $parent))
            {
                $errors['parent'] = 'Некорректный код родительской организации';
            }
            elseif ( ! $auto AND $divcode !== '' AND $parent === $divcode)
            {
                $errors['parent'] = 'Организация не может быть родителем самой себе';
            }
        }

        if ($flag !== '0' AND $flag !== '1')
        {
            $errors['flag'] = 'Неверный флаг организации';
        }

        return $errors;
    }

    /**
     * Создать организацию через POST /api/v2/organizations.
     *
     * Если divcode не указан или запрошена автогенерация (divcode_auto),
     * код формируется из названия организации. При ответе API 409 (код уже
     * существует, например кеш списка устарел) код формируется заново —
     * до organizations.divcode_retry_on_conflict раз.
     *
     * Без родителя организация получает organizations.root_divcode (dv_All).
     * POST сервиса 1.6.0 parent игнорирует, поэтому привязка выполняется
     * дополнительным PUT /api/v2/organizations/{divcode}; при его ошибке
     * созданная организация удаляется, чтобы не осталась отдельным корнем.
     *
     * @param   array  $data  name, divcode, divcode_auto, parent, flag
     * @return  array  созданная организация во внутреннем виде
     * @throws  Kohana_Exception
     */
    public function createOrganization(array $data)
    {
        $name = trim((string) Arr::get($data, 'name', ''));
        $parent = trim((string) Arr::get($data, 'parent', ''));
        $flag = ((int) Arr::get($data, 'flag', 0) === 1) ? 1 : 0;
        $requested = trim((string) Arr::get($data, 'divcode', ''));

        // Пустой код — тоже повод сформировать его автоматически
        $auto = ($this->isDivcodeAutoRequested($data) OR $requested === '');
        $this->_divcode_generated = $auto;

        $divcode = $auto ? $this->generateDivcode($name) : $requested;

        // Новая организация без родителя растёт из главной корневой
        // организации (organizations.root_divcode), а не становится ещё
        // одним корнем. Саму главную организацию это не затрагивает.
        $root = $this->rootDivcode();

        if ($parent === '' AND $root !== NULL AND strcasecmp($divcode, $root) !== 0)
        {
            $parent = $root;
        }

        $payload = array(
            'divcode' => $divcode,
            'name'    => $name,
            'flag'    => $flag,
        );

        // null и отсутствие поля для API равнозначны — корневая организация
        if ($parent !== '')
        {
            $payload['parent'] = $parent;
        }

        $retry_limit = (int) $this->_setting('divcode_retry_on_conflict', 5);
        if ($retry_limit < 1)
        {
            $retry_limit = 1;
        }

        $taken = array();
        $response = NULL;

        for ($attempt = 1; ; $attempt++)
        {
            $payload['divcode'] = $divcode;

            try
            {
                $response = $this->_api->post('/api/v2/organizations', $payload);
                break;
            }
            catch (Kohana_Exception $e)
            {
                if ( ! $auto OR $attempt > $retry_limit OR ! $this->_is_conflict_error($e))
                {
                    throw $e;
                }

                // Код занят в базе — просим следующий свободный
                $taken[] = $divcode;
                $divcode = $this->generateDivcode($name, $taken);
            }
        }

        // POST /api/v2/organizations в сервисе 1.6.0 принимает parent в схеме,
        // но фактически игнорирует его: созданная организация всегда
        // корневая. Привязываем её к родителю отдельным PUT, иначе новая
        // организация не попадёт под главную «Все».
        if ($parent !== '')
        {
            try
            {
                $updated = $this->_api->put(
                    '/api/v2/organizations/' . rawurlencode($divcode),
                    array(
                        'name'   => $name,
                        'parent' => $parent,
                        'flag'   => $flag,
                    )
                );

                if (is_array($updated) AND ! empty($updated))
                {
                    $response = $updated;
                }
            }
            catch (Exception $e)
            {
                // Не оставляем созданную организацию отдельным корнем.
                $rolled_back = FALSE;

                try
                {
                    $this->_api->delete('/api/v2/organizations/' . rawurlencode($divcode));
                    $rolled_back = TRUE;
                }
                catch (Exception $rollback_error)
                {
                    // Ниже сообщим, что откатить не удалось
                }

                // Список организаций мог закешировать созданную запись.
                $this->_api->forget(Model_Restmancard_Api::CACHE_PREFIX . 'orgs_v2');
                $this->_stats = array();

                throw new Kohana_Exception(
                    'Организация :divcode создана, но привязать её к родителю :parent не удалось: :message'
                    . ($rolled_back
                        ? ' Созданная организация удалена.'
                        : ' Удалить созданную организацию не удалось — проверьте её в API.'),
                    array(
                        ':divcode' => $divcode,
                        ':parent'  => $parent,
                        ':message' => $e->getMessage(),
                    )
                );
            }
        }

        // Список организаций закеширован — после создания перечитываем его.
        // Ключ версионирован: старый кеш содержал поле DEVCODE и несовместим
        // с новым именем DIVCODE.
        $this->_api->forget(Model_Restmancard_Api::CACHE_PREFIX . 'orgs_v2');
        $this->_stats = array();

        $organization = $this->_normalize_organization(is_array($response) ? $response : array());

        // Ответ PUT может не содержать parent — возвращаем фактическую связь.
        if ($organization['PARENT'] === NULL AND $parent !== '')
        {
            $organization['PARENT'] = $parent;
        }

        return $organization;
    }

    /**
     * Проверить изменения существующей организации по контракту API.
     *
     * Меняются только name, parent и flag. Код divcode — неизменяемый: API
     * отвечает 400 DIVCODE_IMMUTABLE, если он передан и не совпадает с путём,
     * поэтому в теле запроса он не отправляется.
     *
     * @param   array  $data
     * @return  array  ошибки в виде field => message; пустой массив — данные корректны
     */
    public function validateOrganizationUpdate(array $data)
    {
        $errors = array();

        $divcode = trim((string) Arr::get($data, 'divcode', ''));
        $name = trim((string) Arr::get($data, 'name', ''));
        $parent = trim((string) Arr::get($data, 'parent', ''));
        $flag = trim((string) Arr::get($data, 'flag', '0'));

        if ($divcode === '')
        {
            $errors['divcode'] = 'Не указан код изменяемой организации';
        }
        elseif ($this->_length($divcode) > self::DIVCODE_MAX_LENGTH
            OR ! preg_match('/^[A-Za-z0-9._-]+$/', $divcode))
        {
            $errors['divcode'] = 'Некорректный код организации';
        }

        if ($name === '')
        {
            $errors['name'] = 'Введите название организации';
        }
        elseif ($this->_length($name) > self::NAME_MAX_LENGTH)
        {
            $errors['name'] = 'Название организации не должно превышать ' . self::NAME_MAX_LENGTH . ' символов';
        }

        if ($flag !== '' AND $flag !== '0' AND $flag !== '1')
        {
            $errors['flag'] = 'Неверный флаг организации';
        }

        if ($parent !== '')
        {
            if ($divcode !== '' AND $this->isRootDivcode($divcode))
            {
                $errors['parent'] = 'Организация «' . $divcode . '» всегда корневая: родитель не выбирается';
            }
            elseif ($this->_length($parent) > self::DIVCODE_MAX_LENGTH
                OR ! preg_match('/^[A-Za-z0-9._-]+$/', $parent))
            {
                $errors['parent'] = 'Некорректный код родительской организации';
            }
            elseif ($divcode !== '' AND strcasecmp($parent, $divcode) === 0)
            {
                $errors['parent'] = 'Организация не может быть родителем самой себе';
            }
            elseif ($divcode !== '' AND $this->isDescendant($divcode, $parent))
            {
                $errors['parent'] = 'Нельзя выбрать потомка: в дереве получится кольцо';
            }
            elseif ( ! $this->_organizations_list_truncated()
                AND $this->getOrganizationByDivcode($parent) === NULL)
            {
                // При обрезке списка (organizations.max_pages) «нет в списке»
                // не значит «не существует»: вердикт оставляет API
                // (422 PARENT_NOT_FOUND).
                $errors['parent'] = 'Родительская организация не найдена';
            }
        }

        return $errors;
    }

    /**
     * Изменить свойства организации через PUT /api/v2/organizations/{divcode}.
     *
     * Контракт PUT — полная замена изменяемых полей:
     *   * name обязателен (1–50 символов после обрезки пробелов);
     *   * отсутствие parent (или null) делает организацию корневой;
     *   * отсутствие flag означает 0.
     * Поэтому parent и flag отправляются всегда, divcode — никогда.
     *
     * Поверх контракта действует правило organizations.root_divcode:
     * главная организация (dv_All) всегда остаётся корневой, а организация
     * без выбранного родителя получает в parent главную, чтобы в дереве не
     * появлялось новых корней.
     *
     * @param   string  $divcode
     * @param   array   $data  name, parent, flag
     * @return  array   организация во внутреннем виде
     * @throws  Kohana_Exception
     */
    public function updateOrganization($divcode, array $data)
    {
        $divcode = trim((string) $divcode);

        if ($divcode === '')
        {
            throw new Kohana_Exception('Не указан код организации для изменения');
        }

        $name = trim((string) Arr::get($data, 'name', ''));
        $parent = trim((string) Arr::get($data, 'parent', ''));
        $flag = ((int) Arr::get($data, 'flag', 0) === 1) ? 1 : 0;

        // Главная организация всегда корневая; остальные без родителя
        // растут из неё, а не становятся отдельными корнями.
        $root = $this->rootDivcode();

        if ($this->isRootDivcode($divcode))
        {
            $parent = '';
        }
        elseif ($parent === '' AND $root !== NULL)
        {
            $parent = $root;
        }

        $payload = array(
            'name' => $name,
            'flag' => $flag,
        );

        // null и отсутствие поля для API равнозначны — корневая организация
        if ($parent !== '')
        {
            $payload['parent'] = $parent;
        }

        $response = $this->_api->put(
            '/api/v2/organizations/' . rawurlencode($divcode),
            $payload
        );

        // Список организаций закеширован — после изменения перечитываем его,
        // иначе дерево покажет старые название, родителя и флаг.
        $this->_api->forget(Model_Restmancard_Api::CACHE_PREFIX . 'orgs_v2');
        $this->_stats = array();

        $organization = $this->_normalize_organization(is_array($response) ? $response : array());

        // Ответ без тела не должен «терять» код изменённой организации
        if ($organization['DIVCODE'] === '')
        {
            $organization['DIVCODE'] = $divcode;
        }

        return $organization;
    }

    /**
     * Удалить организацию через DELETE /api/v2/organizations/{divcode}.
     *
     * REST API v2 удаляет только пустую организацию: если у неё есть дочерние
     * организации или активные сотрудники, возвращается 409
     * (OrganizationDeleteConflict). Проверки выполняет API, поэтому локальные
     * счётчики дерева не используются как окончательный вердикт.
     *
     * Главную корневую организацию (organizations.root_divcode) удалить
     * нельзя: вместе с ней исчезнет вершина дерева, из которой «растут» все
     * остальные организации.
     *
     * @param   string  $divcode
     * @return  bool
     * @throws  Kohana_Exception
     */
    public function deleteOrganization($divcode)
    {
        $divcode = trim((string) $divcode);

        if ($divcode === '')
        {
            throw new Kohana_Exception('Не указан код организации для удаления');
        }

        if ($this->isRootDivcode($divcode))
        {
            throw new Kohana_Exception(
                'Организация :divcode всегда корневая — удалить её нельзя',
                array(':divcode' => $divcode)
            );
        }

        $this->_api->delete('/api/v2/organizations/' . rawurlencode($divcode));

        // Список организаций закеширован — после удаления перечитываем его.
        $this->_api->forget(Model_Restmancard_Api::CACHE_PREFIX . 'orgs_v2');
        $this->_stats = array();

        return TRUE;
    }

    /**
     * Плоский список всех организаций.
     *
     * @return  array
     */
    public function getAllOrganizations()
    {
        $lifetime = (int) $this->_config['cache_lifetime'];

        $items = $this->_api->remember(
            Model_Restmancard_Api::CACHE_PREFIX . 'orgs_v2',
            $lifetime,
            array($this, 'fetchAllOrganizations')
        );

        return is_array($items) ? $items : array();
    }

    /**
     * Организация по ID_ORG (поле id из REST API).
     *
     * Используется формой добавления сотрудника: организация выбирается в
     * списке, а привязка выполняется через PATCH id_org, поэтому нужен
     * проверенный идентификатор из загруженного списка.
     *
     * @param   int  $id
     * @return  array|NULL
     */
    public function getOrganizationById($id)
    {
        $id = (int) $id;

        if ($id <= 0)
        {
            return NULL;
        }

        foreach ($this->getAllOrganizations() as $org)
        {
            if ((int) Arr::get($org, 'ID', 0) === $id)
            {
                return $org;
            }
        }

        return NULL;
    }

    /**
     * Организация по коду divcode (сравнение без учёта регистра).
     *
     * @param   string  $divcode
     * @return  array|NULL
     */
    public function getOrganizationByDivcode($divcode)
    {
        $needle = $this->_lower(trim((string) $divcode));

        if ($needle === '')
        {
            return NULL;
        }

        foreach ($this->getAllOrganizations() as $org)
        {
            if ($this->_lower($org['DIVCODE']) === $needle)
            {
                return $org;
            }
        }

        return NULL;
    }

    /**
     * Коды всех потомков организации (без учёта регистра divcode).
     *
     * Нужны форме редактирования: родителем нельзя сделать саму организацию
     * или её потомка — иначе в дереве получится кольцо. REST API v2 такую
     * попытку отклоняет (409 CYCLE), но проверка до запроса даёт понятную
     * ошибку у поля «Родительская организация».
     *
     * @param   string  $divcode
     * @return  array   коды потомков в исходном написании
     */
    public function descendantDivcodes($divcode)
    {
        $start = $this->_lower(trim((string) $divcode));

        if ($start === '')
        {
            return array();
        }

        $organizations = $this->getAllOrganizations();

        // Главная корневая организация: по правилу дерева все остальные
        // организации являются её потомками, даже если в API у них parent
        // пустой. Это важно для каскадного изменения категорий доступа и для
        // запрета выбрать потомка родителем главной организации.
        $root = $this->rootDivcode();

        if ($root !== NULL AND $this->_lower($root) === $start)
        {
            $all = array();

            foreach ($organizations as $org)
            {
                $code = trim((string) Arr::get($org, 'DIVCODE', ''));

                if ($code === '' OR $this->_lower($code) === $start)
                {
                    continue;
                }

                $all[$this->_lower($code)] = $code;
            }

            return array_values($all);
        }

        // Родитель -> дети; ключи нормализованы, значения — исходные коды
        $children = array();

        foreach ($organizations as $org)
        {
            $code = trim((string) $org['DIVCODE']);
            $parent = $org['PARENT'];

            if ($code === '' OR $parent === NULL)
            {
                continue;
            }

            $parent = trim((string) $parent);

            if ($parent === '')
            {
                continue;
            }

            $children[$this->_lower($parent)][] = $code;
        }

        $found = array();
        $queue = isset($children[$start]) ? $children[$start] : array();

        while ( ! empty($queue))
        {
            $code = array_shift($queue);
            $key = $this->_lower($code);

            if (isset($found[$key]))
            {
                continue;
            }

            $found[$key] = $code;

            if (isset($children[$key]))
            {
                foreach ($children[$key] as $child)
                {
                    $queue[] = $child;
                }
            }
        }

        return array_values($found);
    }

    /**
     * Код является потомком организации?
     *
     * @param   string  $divcode
     * @param   string  $candidate
     * @return  bool
     */
    public function isDescendant($divcode, $candidate)
    {
        $needle = $this->_lower(trim((string) $candidate));

        if ($needle === '')
        {
            return FALSE;
        }

        foreach ($this->descendantDivcodes($divcode) as $code)
        {
            if ($this->_lower($code) === $needle)
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Загрузить все страницы списка организаций.
     *
     * @return  array
     */
    public function fetchAllOrganizations()
    {
        $page_size = (int) $this->_config['page_size'];
        if ($page_size < 1 OR $page_size > 100)
        {
            $page_size = 100;
        }

        $max_pages = (int) $this->_config['max_pages'];
        if ($max_pages < 1)
        {
            $max_pages = 1;
        }

        $items = array();
        $page = 1;
        $total = NULL;

        do
        {
            $response = $this->_api->get('/api/v2/organizations', array(
                'page'     => $page,
                'pageSize' => $page_size,
                'sort'     => 'name',
            ));

            if ( ! is_array($response))
            {
                break;
            }

            $rows = (isset($response['items']) AND is_array($response['items']))
                ? $response['items']
                : array();

            foreach ($rows as $row)
            {
                $items[] = $this->_normalize_organization($row);
            }

            $total = isset($response['total']) ? (int) $response['total'] : NULL;
            $page++;
        }
        while (count($rows) === $page_size
            AND ($total === NULL OR count($items) < $total)
            AND $page <= $max_pages);

        return $items;
    }

    /**
     * Дерево организаций с сотрудниками и счётчиками.
     *
     * @param   string  $focus_code       divcode организации, которую нужно раскрыть
     * @param   boolean $with_identifiers  читать идентификаторы (карты) сотрудников
     * @return  array
     */
    public function getTree($focus_code = NULL, $with_identifiers = TRUE)
    {
        $this->_warnings = array();
        $this->_node_counter = 0;

        $flat = $this->getAllOrganizations();

        $people_bundle = $this->_load_people($with_identifiers);
        $people = (isset($people_bundle['people']) AND is_array($people_bundle['people']))
            ? $people_bundle['people']
            : array();

        $nodes = array();
        foreach ($flat as $org)
        {
            $code = trim((string) $org['DIVCODE']);
            if ($code === '')
            {
                $code = '__id' . $org['ID'];
            }

            $org['DIVCODE'] = $code;
            $org['PEOPLE'] = array();

            if ($org['NAME'] === '')
            {
                $org['NAME'] = 'Без названия (' . $code . ')';
            }

            $nodes[$code] = $org;
        }

        // Плоский список пуст — показывать нечего
        if (empty($nodes))
        {
            $this->_stats = array(
                'organizations'         => 0,
                'people'                => 0,
                'people_truncated'      => $people_bundle['people_truncated'],
                'identifiers_loaded'    => $people_bundle['identifiers_loaded'],
                'identifiers_truncated' => $people_bundle['identifiers_truncated'],
                'identifiers_error'     => $people_bundle['identifiers_error'],
                'people_root'           => '',
                'people_mapped'         => 0,
                'people_unmapped'       => 0,
                'people_map_enabled'    => $this->_people()->orgMapEnabled(),
                'people_map_error'      => NULL,
                'people_map_loaded'     => 0,
                'roots'                 => 0,
            );

            return array();
        }

        // Связи «родитель -> дети» и корневые узлы
        $children_map = array();
        $root_codes = array();

        foreach ($nodes as $code => $org)
        {
            $parent = $org['PARENT'];

            if ($parent !== NULL AND $parent !== '' AND $parent !== $code AND isset($nodes[$parent]))
            {
                $children_map[$parent][] = $code;
            }
            else
            {
                $root_codes[] = $code;
            }
        }

        // Правило главной корневой организации: «Все» всегда наверху,
        // остальные корневые организации становятся её дочерними.
        $this->_apply_main_root($children_map, $root_codes, $nodes);

        $this->_sort_codes($root_codes, $nodes);
        foreach (array_keys($children_map) as $parent_code)
        {
            $this->_sort_codes($children_map[$parent_code], $nodes);
        }

        // Организация, к которой относится список сотрудников: сюда попадают
        // сотрудники, для которых не удалось определить ID_ORG.
        $people_root_code = $this->_resolve_people_root($nodes, $root_codes);

        // ID_ORG (поле id из REST) -> divcode узла дерева
        $id_to_code = array();
        foreach ($nodes as $code => $node)
        {
            $id_to_code[(int) $node['ID']] = $code;
        }

        // Карта TABNUM -> ID_ORG из Firebird: REST API v2 не отдаёт
        // организацию сотрудника в GET /api/v2/Person/GetList.
        $org_map = $this->_load_people_org_map();

        $people_mapped = 0;
        $people_unmapped = 0;

        foreach ($people as $person)
        {
            $code = $this->_person_org_code($person, $org_map, $id_to_code);

            if ($code === NULL)
            {
                // Нет привязки или организация не найдена в списке REST
                $code = $people_root_code;
                $people_unmapped++;
            }
            else
            {
                $people_mapped++;
            }

            $nodes[$code]['PEOPLE'][] = $person;
        }

        $max_depth = (int) $this->_config['max_depth'];
        if ($max_depth < 1)
        {
            $max_depth = 25;
        }

        $visited = array();
        $tree = array();

        // Сотрудники без организации показываются в people_root_code; признак
        // «это организация-приёмник» ставим только если такие сотрудники есть.
        $people_fallback_code = ($people_unmapped > 0) ? $people_root_code : NULL;

        foreach ($root_codes as $code)
        {
            $branch = $this->_build_node($code, $nodes, $children_map, $visited, 0, $max_depth, '', $people_fallback_code);
            if ($branch !== NULL)
            {
                $tree[] = $branch;
            }
        }

        // Раскрываем ветку до организации со списком сотрудников
        $this->_mark_expanded($tree, $people_root_code);

        // Раскрываем ветку до только что добавленной организации
        $focus_code = trim((string) $focus_code);
        if ($focus_code !== '')
        {
            $this->_mark_expanded($tree, $focus_code);
        }

        // Единственный корень удобно раскрыть сразу
        if (count($tree) === 1)
        {
            $tree[0]['EXPANDED'] = TRUE;
        }

        $this->_stats = array(
            'organizations'         => count($nodes),
            'people'                => count($people),
            'people_truncated'      => $people_bundle['people_truncated'],
            'identifiers_loaded'    => $people_bundle['identifiers_loaded'],
            'identifiers_truncated' => $people_bundle['identifiers_truncated'],
            'identifiers_error'     => $people_bundle['identifiers_error'],
            'people_root'           => $people_root_code,
            'people_mapped'         => $people_mapped,
            'people_unmapped'       => $people_unmapped,
            'people_map_enabled'    => ! empty($org_map['enabled']),
            'people_map_error'      => isset($org_map['error']) ? $org_map['error'] : NULL,
            'people_map_loaded'     => isset($org_map['loaded']) ? (int) $org_map['loaded'] : 0,
            'roots'                 => count($tree),
            'truncated_orgs'        => $this->_organizations_truncated($nodes),
        );

        return $tree;
    }

    /**
     * Дерево организаций с сотрудниками, но без идентификаторов (карт).
     *
     * Отдельная форма `restmancard/people` работает только с сотрудниками,
     * поэтому GET /api/v2/Identifier/GetList не запрашивается: дерево строится
     * теми же узлами, что и getTree(), но карточки сотрудников остаются пустыми.
     *
     * @param   string  $focus_code  divcode организации, ветку которой нужно раскрыть
     * @return  array
     */
    public function getPeopleTree($focus_code = NULL)
    {
        return $this->getTree($focus_code, FALSE);
    }

    /**
     * Дерево организаций без сотрудников.
     *
     * Отдельная форма `restmancard/organizations` работает только с
     * организациями, поэтому сотрудники не читаются вовсе: ни
     * GET /api/v2/Person/GetList, ни GET /api/v2/Identifier/GetList, ни карта
     * TABNUM -> ID_ORG из Firebird. Узлы дерева содержат только реквизиты
     * организации и счётчики подразделений.
     *
     * @param   string  $focus_code  divcode организации, ветку которой нужно раскрыть
     * @return  array
     */
    public function getOrganizationTree($focus_code = NULL)
    {
        $this->_warnings = array();
        $this->_node_counter = 0;

        $flat = $this->getAllOrganizations();

        $nodes = array();
        foreach ($flat as $org)
        {
            $code = trim((string) $org['DIVCODE']);
            if ($code === '')
            {
                $code = '__id' . $org['ID'];
            }

            $org['DIVCODE'] = $code;

            if ($org['NAME'] === '')
            {
                $org['NAME'] = 'Без названия (' . $code . ')';
            }

            $nodes[$code] = $org;
        }

        if (empty($nodes))
        {
            $this->_stats = array(
                'organizations'  => 0,
                'roots'          => 0,
                'truncated_orgs' => FALSE,
                'people'         => 0,
            );

            return array();
        }

        // Связи «родитель -> дети» и корневые узлы
        $children_map = array();
        $root_codes = array();

        foreach ($nodes as $code => $org)
        {
            $parent = $org['PARENT'];

            if ($parent !== NULL AND $parent !== '' AND $parent !== $code AND isset($nodes[$parent]))
            {
                $children_map[$parent][] = $code;
            }
            else
            {
                $root_codes[] = $code;
            }
        }

        // Правило главной корневой организации: «Все» всегда наверху,
        // остальные корневые организации становятся её дочерними.
        $this->_apply_main_root($children_map, $root_codes, $nodes);

        $this->_sort_codes($root_codes, $nodes);
        foreach (array_keys($children_map) as $parent_code)
        {
            $this->_sort_codes($children_map[$parent_code], $nodes);
        }

        $max_depth = (int) $this->_config['max_depth'];
        if ($max_depth < 1)
        {
            $max_depth = 25;
        }

        $visited = array();
        $tree = array();

        foreach ($root_codes as $code)
        {
            $branch = $this->_build_org_node($code, $nodes, $children_map, $visited, 0, $max_depth, '');
            if ($branch !== NULL)
            {
                $tree[] = $branch;
            }
        }

        // Раскрываем ветку до только что изменённой организации
        $focus_code = trim((string) $focus_code);
        if ($focus_code !== '')
        {
            $this->_mark_expanded($tree, $focus_code);
        }

        // Единственный корень удобно раскрыть сразу
        if (count($tree) === 1)
        {
            $tree[0]['EXPANDED'] = TRUE;
        }

        $this->_stats = array(
            'organizations'  => count($nodes),
            'roots'          => count($tree),
            'truncated_orgs' => $this->_organizations_truncated($nodes),
            'people'         => 0,
        );

        return $tree;
    }

    /**
     * Построить узел дерева организаций (без сотрудников) рекурсивно.
     *
     * @param   string  $code
     * @param   array   $nodes
     * @param   array   $children_map
     * @param   array   $visited
     * @param   int     $level
     * @param   int     $max_depth
     * @param   string  $parent_path
     * @return  array|NULL
     */
    protected function _build_org_node($code, array $nodes, array $children_map,
        array & $visited, $level, $max_depth, $parent_path)
    {
        if (isset($visited[$code]) OR $level > $max_depth OR ! isset($nodes[$code]))
        {
            return NULL;
        }

        $visited[$code] = TRUE;

        $node = $nodes[$code];

        $node['LEVEL'] = $level;
        $node['UID'] = 'o' . (++$this->_node_counter);
        $node['PATH'] = $parent_path === '' ? $node['NAME'] : $parent_path . ' / ' . $node['NAME'];
        $node['EXPANDED'] = FALSE;

        $children = array();
        $total_children = 0;

        if (isset($children_map[$code]))
        {
            foreach ($children_map[$code] as $child_code)
            {
                $child = $this->_build_org_node(
                    $child_code,
                    $nodes,
                    $children_map,
                    $visited,
                    $level + 1,
                    $max_depth,
                    $node['PATH']
                );

                if ($child !== NULL)
                {
                    $children[] = $child;
                    $total_children += 1 + $child['TOTAL_CHILDREN'];
                }
            }
        }

        $node['CHILDREN'] = $children;
        $node['CHILDREN_COUNT'] = count($children);
        $node['TOTAL_CHILDREN'] = $total_children;

        return $node;
    }

    /**
     * Загрузить сотрудников, не прерывая построение дерева при ошибке.
     *
     * @param   boolean  $with_identifiers  читать ли идентификаторы (карты)
     * @return  array
     */
    protected function _load_people($with_identifiers = TRUE)
    {
        try
        {
            $bundle = $with_identifiers
                ? $this->_people()->getAllWithIdentifiers()
                : $this->_load_people_plain();
        }
        catch (Exception $e)
        {
            $this->_warnings[] = 'Не удалось загрузить сотрудников: ' . $e->getMessage();

            return array(
                'people'                => array(),
                'people_truncated'      => FALSE,
                'identifiers_loaded'    => 0,
                'identifiers_truncated' => FALSE,
                'identifiers_error'     => NULL,
            );
        }

        if ( ! empty($bundle['identifiers_error']))
        {
            $this->_warnings[] = 'Не удалось загрузить идентификаторы: ' . $bundle['identifiers_error'];
        }

        return $bundle;
    }

    /**
     * Сотрудники без идентификаторов: для формы «Сотрудники».
     *
     * @return  array
     * @throws  Kohana_Exception
     */
    protected function _load_people_plain()
    {
        $bundle = $this->_people()->getAll();

        return array(
            'people'                => $bundle['people'],
            'people_truncated'      => ! empty($bundle['truncated']),
            'identifiers_loaded'    => 0,
            'identifiers_truncated' => FALSE,
            'identifiers_error'     => NULL,
        );
    }

    /**
     * Карта TABNUM -> ID_ORG для сотрудников (Firebird), без прерывания дерева.
     *
     * @return  array
     */
    protected function _load_people_org_map()
    {
        try
        {
            $bundle = $this->_people()->getOrganizationMap();
        }
        catch (Exception $e)
        {
            $bundle = array(
                'by_tabnum'       => array(),
                'by_tabnum_lower' => array(),
                'loaded'          => 0,
                'enabled'         => $this->_people()->orgMapEnabled(),
                'error'           => $e->getMessage(),
            );
        }

        if ( ! empty($bundle['error']))
        {
            $this->_warnings[] = 'Не удалось определить организации сотрудников: '
                . $bundle['error']
                . ' Сотрудники показаны у организации из настройки organizations.people_root_divcode.';
        }

        return $bundle;
    }

    /**
     * divcode организации сотрудника по карте Firebird.
     *
     * @param   array  $person
     * @param   array  $org_map
     * @param   array  $id_to_code  ID_ORG => divcode
     * @return  string|NULL
     */
    protected function _person_org_code(array $person, array $org_map, array $id_to_code)
    {
        $id_org = $this->_person_org_id($person, $org_map);

        if ($id_org > 0 AND isset($id_to_code[$id_org]))
        {
            return $id_to_code[$id_org];
        }

        return NULL;
    }

    /**
     * ID_ORG сотрудника по карте TABNUM -> ID_ORG.
     *
     * Сначала точное совпадение, затем без учёта регистра: Firebird хранит
     * TABNUM в windows-1251, а REST API возвращает его в UTF-8.
     *
     * @param   array  $person
     * @param   array  $org_map
     * @return  int
     */
    protected function _person_org_id(array $person, array $org_map)
    {
        $tabnum = trim((string) Arr::get($person, 'TABNUM', ''));

        if ($tabnum === '')
        {
            return 0;
        }

        if (isset($org_map['by_tabnum'][$tabnum]))
        {
            return (int) $org_map['by_tabnum'][$tabnum];
        }

        $lower = $this->_lower($tabnum);

        if (isset($org_map['by_tabnum_lower'][$lower]))
        {
            return (int) $org_map['by_tabnum_lower'][$lower];
        }

        return 0;
    }

    /**
     * Построить узел дерева рекурсивно.
     *
     * @param   string  $code
     * @param   array   $nodes
     * @param   array   $children_map
     * @param   array   $visited
     * @param   int     $level
     * @param   int     $max_depth
     * @param   string  $parent_path
     * @param   string|NULL  $people_fallback_code
     * @return  array|NULL
     */
    protected function _build_node($code, array $nodes, array $children_map,
        array & $visited, $level, $max_depth, $parent_path, $people_fallback_code)
    {
        if (isset($visited[$code]) OR $level > $max_depth OR ! isset($nodes[$code]))
        {
            return NULL;
        }

        $visited[$code] = TRUE;

        $node = $nodes[$code];

        usort($node['PEOPLE'], array($this, '_compare_people'));

        $node['LEVEL'] = $level;
        $node['UID'] = 'o' . (++$this->_node_counter);
        $node['PATH'] = $parent_path === '' ? $node['NAME'] : $parent_path . ' / ' . $node['NAME'];
        $node['PEOPLE_COUNT'] = count($node['PEOPLE']);
        // Признак нужен панели «Свойства»: организация показывает
        // сотрудников, для которых не удалось определить ID_ORG.
        $node['IS_PEOPLE_ROOT'] = ($people_fallback_code !== NULL
            AND $code === $people_fallback_code
            AND $node['PEOPLE_COUNT'] > 0);
        $node['EXPANDED'] = FALSE;

        $children = array();
        $total_children = 0;
        $total_people = $node['PEOPLE_COUNT'];

        if (isset($children_map[$code]))
        {
            foreach ($children_map[$code] as $child_code)
            {
                $child = $this->_build_node(
                    $child_code,
                    $nodes,
                    $children_map,
                    $visited,
                    $level + 1,
                    $max_depth,
                    $node['PATH'],
                    $people_fallback_code
                );

                if ($child !== NULL)
                {
                    $children[] = $child;
                    $total_children += 1 + $child['TOTAL_CHILDREN'];
                    $total_people += $child['TOTAL_PEOPLE'];
                }
            }
        }

        $node['CHILDREN'] = $children;
        $node['CHILDREN_COUNT'] = count($children);
        $node['TOTAL_CHILDREN'] = $total_children;
        $node['TOTAL_PEOPLE'] = $total_people;

        return $node;
    }

    /**
     * Раскрыть все узлы на пути к организации со списком сотрудников.
     *
     * @param   array   $branch
     * @param   string  $target_code
     * @return  bool
     */
    protected function _mark_expanded(array & $branch, $target_code)
    {
        $contains = FALSE;

        foreach ($branch as & $node)
        {
            $is_target = ($node['DIVCODE'] === $target_code);
            $child_contains = $this->_mark_expanded($node['CHILDREN'], $target_code);

            // Несколько целей могут раскрываться подряд — сохраняем обе ветки
            $node['EXPANDED'] = ( ! empty($node['EXPANDED']) OR $is_target OR $child_contains);

            if ($node['EXPANDED'])
            {
                $contains = TRUE;
            }
        }

        unset($node);

        return $contains;
    }

    /**
     * Применить правило главной корневой организации к карте дерева.
     *
     * Организация organizations.root_divcode (по умолчанию dv_All) всегда
     * становится единственным корнем: если в API у неё задан parent, связь
     * отбрасывается; все прочие корневые узлы (parent = null или ссылка на
     * отсутствующую организацию) добавляются ей в дети. Так «Все» всегда
     * находится в самом верху, а остальные организации «растут» из неё.
     *
     * Если настройка пуста или главной организации нет в списке, карта не
     * меняется и дерево строится по данным API.
     *
     * @param   array  $children_map
     * @param   array  $root_codes
     * @param   array  $nodes
     * @return  string|NULL  код главной организации или NULL
     */
    protected function _apply_main_root(array & $children_map, array & $root_codes, array $nodes)
    {
        $root = $this->rootDivcode();

        if ($root === NULL OR ! isset($nodes[$root]))
        {
            return NULL;
        }

        // Главная организация не может быть потомком другой организации.
        foreach (array_keys($children_map) as $parent_code)
        {
            $children = array();

            foreach ($children_map[$parent_code] as $child_code)
            {
                if ($child_code !== $root)
                {
                    $children[] = $child_code;
                }
            }

            if (empty($children))
            {
                unset($children_map[$parent_code]);
            }
            else
            {
                $children_map[$parent_code] = $children;
            }
        }

        // Все прочие корневые организации становятся детьми главной.
        if ( ! isset($children_map[$root]))
        {
            $children_map[$root] = array();
        }

        foreach ($root_codes as $code)
        {
            if ($code !== $root)
            {
                $children_map[$root][] = $code;
            }
        }

        $children_map[$root] = array_values(array_unique($children_map[$root]));
        $root_codes = array($root);

        return $root;
    }

    /**
     * Выбрать организацию, к которой относится список сотрудников.
     *
     * @param   array  $nodes
     * @param   array  $root_codes
     * @return  string
     */
    protected function _resolve_people_root(array $nodes, array $root_codes)
    {
        $preferred = trim((string) $this->_config['people_root_divcode']);

        if ($preferred !== '')
        {
            if (isset($nodes[$preferred]))
            {
                return $preferred;
            }

            foreach (array_keys($nodes) as $code)
            {
                if (strcasecmp($code, $preferred) === 0)
                {
                    return $code;
                }
            }
        }

        $root = $this->rootDivcode();

        if ($root !== NULL AND isset($nodes[$root]))
        {
            return $root;
        }

        if ( ! empty($root_codes))
        {
            return $root_codes[0];
        }

        reset($nodes);
        return key($nodes);
    }

    /**
     * Отсортировать коды узлов по названию организации.
     *
     * @param   array  $codes
     * @param   array  $nodes
     */
    protected function _sort_codes(array & $codes, array $nodes)
    {
        usort($codes, function ($a, $b) use ($nodes)
        {
            return $this->_compare_names($nodes[$a]['NAME'], $nodes[$b]['NAME']);
        });
    }

    /**
     * Сравнение сотрудников для usort().
     */
    public function _compare_people($a, $b)
    {
        $compare = strcmp($a['SORT_KEY'], $b['SORT_KEY']);

        if ($compare !== 0)
        {
            return $compare;
        }

        return strcmp($a['TABNUM'], $b['TABNUM']);
    }

    /**
     * Сравнение названий (без учёта регистра, с поддержкой UTF-8).
     */
    protected function _compare_names($a, $b)
    {
        return strcmp($this->_lower($a), $this->_lower($b));
    }

    /**
     * Привести организацию к внутреннему виду.
     *
     * @param   array  $row
     * @return  array
     */
    protected function _normalize_organization(array $row)
    {
        $parent = Arr::get($row, 'parent', NULL);
        if ($parent !== NULL)
        {
            $parent = trim((string) $parent);
            if ($parent === '')
            {
                $parent = NULL;
            }
        }

        return array(
            'ID'         => (int) Arr::get($row, 'id', 0),
            'DIVCODE'    => trim((string) Arr::get($row, 'divcode', '')),
            'NAME'       => trim((string) Arr::get($row, 'name', '')),
            'PARENT'     => $parent,
            'FLAG'       => (int) Arr::get($row, 'flag', 0),
            'CREATED_AT' => Arr::get($row, 'createdAt', NULL),
            'UPDATED_AT' => Arr::get($row, 'updatedAt', NULL),
        );
    }

    /**
     * Признак обрезки списка организаций по max_pages.
     *
     * @param   array  $nodes
     * @return  bool
     */
    protected function _organizations_truncated(array $nodes)
    {
        $max_pages = (int) $this->_config['max_pages'];
        $page_size = (int) $this->_config['page_size'];

        if ($max_pages < 1 OR $page_size < 1)
        {
            return FALSE;
        }

        return count($nodes) >= ($max_pages * $page_size);
    }

    /**
     * Список организаций мог быть обрезан настройкой max_pages?
     *
     * Нужен проверке родителя: при обрезке «нет в списке» не значит
     * «не существует», поэтому вердикт остаётся за API.
     *
     * @return  bool
     */
    protected function _organizations_list_truncated()
    {
        $max_pages = (int) $this->_config['max_pages'];
        $page_size = (int) $this->_config['page_size'];

        if ($max_pages < 1 OR $page_size < 1)
        {
            return FALSE;
        }

        return count($this->getAllOrganizations()) >= ($max_pages * $page_size);
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
