<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Базовый класс Minion-задач модуля restmancard.
 *
 * Содержит общее для задач: кодировку вывода в консоль Windows, методы
 * write()/fail(), проверку префикса и разбор последней ошибки REST API.
 * Наследники реализуют _execute() и объявляют $_options.
 *
 * Класс намеренно лежит вне classes/task, чтобы Minion не показывал его
 * в списке задач.
 *
 * @package    restmancard
 * @subpackage Task
 */
abstract class Restmancard_Task extends Minion_Task
{
    /**
     * Кодировка вывода в консоль.
     *
     * @var string
     */
    protected $_encoding = 'UTF-8';

    /**
     * Определить кодировку консоли из опции --encoding.
     *
     * @param   array  $params
     * @return  void
     */
    protected function setup_encoding(array $params)
    {
        $this->_encoding = $this->console_encoding(Arr::get($params, 'encoding', 'auto'));
    }

    /**
     * Кодировка вывода в консоль из опции --encoding.
     *
     * @param   string $option
     * @return  string
     */
    protected function console_encoding($option)
    {
        switch (strtolower(trim((string) $option)))
        {
            case '':
            case 'auto':
                return $this->detect_console_encoding();
            case 'utf8':
            case 'utf-8':
            case '65001':
                return 'UTF-8';
            case 'cp866':
            case '866':
                return 'CP866';
            case 'cp1251':
            case '1251':
                return 'CP1251';
            default:
                $this->fail('неизвестная кодировка --encoding=' . $option
                    . '. Допустимо: auto, utf8, cp866, cp1251.');
        }

        return 'UTF-8';
    }

    /**
     * Определить кодовую страницу консоли Windows.
     *
     * @return  string
     */
    protected function detect_console_encoding()
    {
        if (DIRECTORY_SEPARATOR !== '\\')
        {
            return 'UTF-8';
        }

        if (function_exists('shell_exec'))
        {
            // Цифры в ответе chcp — ASCII, разбор не зависит от кодировки.
            $output = @shell_exec('chcp 2>NUL');

            if (preg_match('/(\d{3,5})/', (string) $output, $matches))
            {
                switch ((int) $matches[1])
                {
                    case 65001: return 'UTF-8';
                    case 866:   return 'CP866';
                    case 1251:  return 'CP1251';
                }
            }
        }

        return 'CP866';
    }

    /**
     * Вывести строку в консоль с перекодировкой.
     *
     * @param   string $text
     * @return  void
     */
    protected function write($text = '')
    {
        Minion_CLI::write($this->to_console($text));
    }

    /**
     * Перекодировать строку UTF-8 в кодировку консоли.
     *
     * @param   string $text
     * @return  string
     */
    protected function to_console($text)
    {
        if ($this->_encoding === 'UTF-8' OR $this->_encoding === '')
        {
            return $text;
        }

        $converted = @iconv('UTF-8', $this->_encoding . '//TRANSLIT', $text);

        if ($converted === FALSE)
        {
            $converted = @iconv('UTF-8', $this->_encoding . '//IGNORE', $text);
        }

        return ($converted === FALSE) ? $text : $converted;
    }

    /**
     * Вывод ошибки и завершение с кодом 1.
     *
     * @param   string $message
     * @return  void
     */
    protected function fail($message)
    {
        $this->write('ОШИБКА: ' . $message);
        exit(1);
    }

    /**
     * Строка начинается с префикса (без учёта регистра ASCII)?
     *
     * @param   string $value
     * @param   string $prefix
     * @return  bool
     */
    protected function starts_with($value, $prefix)
    {
        $value = (string) $value;
        $prefix = (string) $prefix;

        if ($prefix === '')
        {
            return FALSE;
        }

        return strncasecmp($value, $prefix, strlen($prefix)) === 0;
    }

    /**
     * HTTP-статус последней ошибки REST API (0 — ошибки не было).
     *
     * @param   Model_Restmancard_Api $api
     * @return  int
     */
    protected function api_status(Model_Restmancard_Api $api)
    {
        $error = $api->lastError();

        return (is_array($error) AND isset($error['status']))
            ? (int) $error['status']
            : 0;
    }
}
