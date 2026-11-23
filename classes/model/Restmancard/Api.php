<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Низкоуровневый клиент Artonit REST API v2.
 *
 * Контракт: http://localhost:5056/swagger/v2/swagger.json
 * Авторизация: GET /login?username=...&pasword=... -> JWT (Bearer).
 *
 * Клиент умеет:
 *  - получать и кешировать JWT (файловый кеш Kohana);
 *  - выполнять GET-запросы с query-параметрами;
 *  - выполнять POST, PUT, PATCH и DELETE с JSON-телом;
 *  - повторять запрос один раз после 401 (перелогин);
 *  - кешировать произвольные результаты через remember().
 */
class Model_Restmancard_Api extends Model
{
    /** Префикс ключей кеша модуля */
    const CACHE_PREFIX = 'restmancard_';

    /** @var Config_Group */
    protected $_config;

    /** @var Cache|NULL|FALSE */
    protected static $_cache_instance = NULL;

    /** @var array|NULL  последняя ошибка API: status, data, url */
    protected static $_last_error = NULL;

    public function __construct()
    {
        $this->_config = Kohana::$config->load('restmancard.api');
    }

    /**
     * Адрес метода API с query-параметрами.
     *
     * @param   string  $path
     * @param   array   $query
     * @return  string
     */
    public function url($path, array $query = array())
    {
        $url = rtrim((string) $this->_config['base_url'], '/') . '/' . ltrim((string) $path, '/');

        if ( ! empty($query))
        {
            $url .= '?' . http_build_query($query, '', '&');
        }

        return $url;
    }

    /**
     * GET-запрос к API.
     *
     * @param   string  $path
     * @param   array   $query
     * @return  mixed   декодированный JSON (array) или NULL
     * @throws  Kohana_Exception
     */
    public function get($path, array $query = array())
    {
        return $this->_request('GET', $path, $query);
    }

    /**
     * POST-запрос к API.
     *
     * @param   string  $path
     * @param   array   $body
     * @param   array   $query
     * @return  mixed   декодированный JSON (array) или NULL
     * @throws  Kohana_Exception
     */
    public function post($path, array $body, array $query = array())
    {
        return $this->_request('POST', $path, $query, $body);
    }

    /**
     * PUT-запрос к API.
     *
     * Используется методами полной замены изменяемых полей:
     *   PUT /api/v2/organizations/{divcode}              — name, parent, flag;
     *   PUT /api/v2/organizations/{divcode}/accessnames  — accessnames.
     *
     * @param   string  $path
     * @param   array   $body
     * @param   array   $query
     * @return  mixed   декодированный JSON (array|int) или NULL
     * @throws  Kohana_Exception
     */
    public function put($path, array $body, array $query = array())
    {
        return $this->_request('PUT', $path, $query, $body);
    }

    /**
     * PATCH-запрос к API.
     *
     * @param   string  $path
     * @param   array   $body
     * @param   array   $query
     * @return  mixed   декодированный JSON (array|int) или NULL
     * @throws  Kohana_Exception
     */
    public function patch($path, array $body, array $query = array())
    {
        return $this->_request('PATCH', $path, $query, $body);
    }

    /**
     * DELETE-запрос к API.
     *
     * @param   string  $path
     * @param   array   $query
     * @return  mixed   декодированный JSON (array) или NULL
     * @throws  Kohana_Exception
     */
    public function delete($path, array $query = array())
    {
        return $this->_request('DELETE', $path, $query);
    }

    /**
     * Сведения о последней ошибке API.
     *
     * Нужны, чтобы контроллер мог разобрать структурированный ответ (например,
     * 409 OrganizationDeleteConflict при удалении организации), а не только
     * текст исключения.
     *
     * @return  array|NULL  array('status' => int, 'data' => array|NULL, 'url' => string)
     */
    public function lastError()
    {
        return self::$_last_error;
    }

    /**
     * Удалить значение из кеша по ключу.
     *
     * @param   string  $key
     */
    public function forget($key)
    {
        $this->_cache_delete($key);
    }

    /**
     * Вернуть значение из кеша или вычислить и закешировать его.
     *
     * @param   string    $key
     * @param   int       $lifetime  секунды; 0 — без кеша
     * @param   callable  $callback
     * @return  mixed
     */
    public function remember($key, $lifetime, $callback)
    {
        $lifetime = (int) $lifetime;
        $cache = $this->_cache();

        if ($cache AND $lifetime > 0)
        {
            $value = $this->_cache_get($key);
            if ($value !== NULL)
            {
                return $value;
            }
        }

        $value = call_user_func($callback);

        if ($cache AND $lifetime > 0)
        {
            $this->_cache_set($key, $value, $lifetime);
        }

        return $value;
    }

    /**
     * Сбросить кеш данных и токен.
     */
    public function flush()
    {
        $keys = array(
            self::CACHE_PREFIX . 'orgs_v2',
            self::CACHE_PREFIX . 'people',
            self::CACHE_PREFIX . 'people_org',
            self::CACHE_PREFIX . 'identifiers',
            self::CACHE_PREFIX . 'idtypes',
            self::CACHE_PREFIX . 'access_categories',
            self::CACHE_PREFIX . 'access_people_map',
            self::CACHE_PREFIX . 'token',
        );

        $cache = $this->_cache();
        if ( ! $cache)
        {
            return;
        }

        foreach ($keys as $key)
        {
            try
            {
                $cache->delete($key);
            }
            catch (Exception $e)
            {
                // Кеш недоступен — данные будут получены заново
            }
        }
    }

    /**
     * Получить действующий JWT. При необходимости выполняется логин.
     *
     * @return  string
     * @throws  Kohana_Exception
     */
    public function token()
    {
        $cached = $this->_cache_get(self::CACHE_PREFIX . 'token');

        if (is_array($cached)
            AND ! empty($cached['token'])
            AND (int) Arr::get($cached, 'expires', 0) > time())
        {
            return (string) $cached['token'];
        }

        return $this->_login();
    }

    /**
     * Логин в REST API v2 и сохранение токена в кеше.
     *
     * @return  string
     * @throws  Kohana_Exception
     */
    protected function _login()
    {
        $password_field = (string) $this->_config['login_password_field'];

        if ($password_field === '')
        {
            $password_field = 'pasword';
        }

        $query = array(
            'username'         => (string) $this->_config['login_username'],
            $password_field    => (string) $this->_config['login_password'],
        );

        // Логин выполняется без Authorization и без повторов
        $response = $this->_request('GET', $this->_config['login_path'], $query, NULL, FALSE, FALSE);
        $token = $this->_extract_token($response);

        if ($token === '')
        {
            throw new Kohana_Exception(
                'REST API не вернул токен авторизации: :url',
                array(':url' => $this->url($this->_config['login_path']))
            );
        }

        $ttl = (int) $this->_config['token_ttl'];
        if ($ttl < 60)
        {
            $ttl = 60;
        }

        $this->_cache_set(
            self::CACHE_PREFIX . 'token',
            array('token' => $token, 'expires' => time() + $ttl),
            $ttl
        );

        return $token;
    }

    /**
     * Извлечь токен из ответа логина.
     *
     * Сервис возвращает {"status":true,"data":{"token":"..."}},
     * но на всякий случай поддерживаются и другие варианты.
     *
     * @param   mixed   $response
     * @return  string
     */
    protected function _extract_token($response)
    {
        if ( ! is_array($response))
        {
            return '';
        }

        $paths = array(
            array('data', 'token'),
            array('Data', 'Token'),
            array('token'),
            array('Token'),
            array('access_token'),
        );

        foreach ($paths as $path)
        {
            $value = $response;
            foreach ($path as $key)
            {
                if ( ! is_array($value) OR ! array_key_exists($key, $value))
                {
                    $value = NULL;
                    break;
                }
                $value = $value[$key];
            }

            if (is_string($value) AND $value !== '')
            {
                return $value;
            }
        }

        return '';
    }

    /**
     * Выполнить HTTP-запрос.
     *
     * @param   string  $method
     * @param   string  $path
     * @param   array   $query
     * @param   mixed   $body          тело для POST/PATCH/PUT
     * @param   bool    $allow_retry   повторить после 401
     * @param   bool    $with_auth     добавлять Authorization
     * @return  mixed
     * @throws  Kohana_Exception
     */
    protected function _request($method, $path, array $query = array(),
        $body = NULL, $allow_retry = TRUE, $with_auth = TRUE)
    {
        $url = $this->url($path, $query);

        self::$_last_error = NULL;

        $headers = array(
            'Accept: application/json',
            'User-Agent: ArtonitCity-Restmancard/' . (defined('RESTMANCARD_VERSION') ? RESTMANCARD_VERSION : '1.0.0'),
        );

        if ($with_auth)
        {
            $token = $this->token();
            if ($token !== '')
            {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }

        if ($body !== NULL)
        {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $this->_config['connect_timeout']);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $this->_config['timeout']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool) $this->_config['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->_config['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'GET')
        {
            curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
        }
        else
        {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== NULL)
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno)
        {
            self::$_last_error = array(
                'status' => 0,
                'data'   => NULL,
                'url'    => $url,
            );

            throw new Kohana_Exception(
                'REST API недоступен: :url (:error)',
                array(':url' => $url, ':error' => $error)
            );
        }

        if ($status === 401 AND $allow_retry AND $with_auth)
        {
            // Токен мог истечь — сбрасываем и пробуем ещё раз
            $cache = $this->_cache();
            if ($cache)
            {
                $this->_cache_delete(self::CACHE_PREFIX . 'token');
            }

            return $this->_request($method, $path, $query, $body, FALSE, $with_auth);
        }

        $data = NULL;
        if ($raw !== '' AND $raw !== NULL)
        {
            $data = json_decode($raw, TRUE);
        }

        if ($status < 200 OR $status >= 300)
        {
            self::$_last_error = array(
                'status' => $status,
                'data'   => $data,
                'url'    => $url,
            );

            throw new Kohana_Exception(
                'REST API: :message',
                array(':message' => $this->_format_error($status, $raw, $data))
            );
        }

        return $data;
    }

    /**
     * Человекочитаемое описание ошибки API.
     *
     * @param   int     $status
     * @param   string  $raw
     * @param   mixed   $data
     * @return  string
     */
    protected function _format_error($status, $raw, $data)
    {
        $message = '';

        if (is_array($data))
        {
            if ( ! empty($data['message']))
            {
                $message = (string) $data['message'];
            }
            elseif ( ! empty($data['error']))
            {
                $message = (string) $data['error'];
            }
        }

        if ($message === '')
        {
            $message = trim(strip_tags((string) $raw));
            $normalized = preg_replace('/\s+/u', ' ', $message);

            if ($normalized !== NULL)
            {
                $message = $normalized;
            }

            if (function_exists('mb_substr'))
            {
                $message = mb_substr($message, 0, 500, 'UTF-8');
            }
            else
            {
                $message = substr($message, 0, 500);
            }
        }

        return 'HTTP ' . (int) $status . ($message !== '' ? ': ' . $message : '');
    }

    /**
     * @return  Cache|NULL
     */
    protected function _cache()
    {
        if (self::$_cache_instance === NULL)
        {
            self::$_cache_instance = FALSE;

            if (class_exists('Cache'))
            {
                try
                {
                    self::$_cache_instance = Cache::instance('file');
                }
                catch (Exception $e)
                {
                    self::$_cache_instance = FALSE;
                }
            }
        }

        return self::$_cache_instance ? self::$_cache_instance : NULL;
    }

    protected function _cache_get($key)
    {
        $cache = $this->_cache();
        if ( ! $cache)
        {
            return NULL;
        }

        try
        {
            return $cache->get($key);
        }
        catch (Exception $e)
        {
            return NULL;
        }
    }

    protected function _cache_set($key, $value, $lifetime)
    {
        $cache = $this->_cache();
        if ( ! $cache)
        {
            return;
        }

        try
        {
            $cache->set($key, $value, (int) $lifetime);
        }
        catch (Exception $e)
        {
            // Кеш не критичен для работы модуля
        }
    }

    protected function _cache_delete($key)
    {
        $cache = $this->_cache();
        if ( ! $cache)
        {
            return;
        }

        try
        {
            $cache->delete($key);
        }
        catch (Exception $e)
        {
            // ignore
        }
    }
}
