<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Задача restmancard:seed — тестовые организации в Artonit REST API v2.
 *
 * Создаёт в корне дерева 5 организаций и по 3 дочерних подразделения
 * в каждой — то же, что делает кнопка «Добавить» на странице
 * restmancard/index, но из командной строки.
 *
 * Запуск:
 *   c:\xampp\php\php.exe c:\xampp\htdocs\city\modules\minion\minion --task=restmancard:seed
 *
 * Параметры:
 *   --count    Количество корневых организаций (по умолчанию 5).
 *   --children Количество дочерних организаций у каждой корневой (по умолчанию 3).
 *   --dry-run  1 — только показать план, ничего не создавать (по умолчанию 0).
 *   --encoding Кодировка вывода: auto|utf8|cp866|cp1251 (по умолчанию auto).
 *
 * Названия: «Тестовая организация N» и «Подразделение N.M». Код divcode
 * формируется автоматически моделью Model_Restmancard_Org — так же, как при
 * добавлении через интерфейс (префикс dv_, транслитерация названия).
 *
 * Задача идемпотентна: организации, которые уже есть (совпадение названия
 * и родителя), пропускаются, поэтому повторный запуск не создаёт дубликатов.
 * Кеш списка организаций сбрасывается перед чтением и перед проверкой.
 *
 * Коды возврата: 0 — все организации созданы или уже существовали;
 *                1 — ошибка входных данных, API или проверки результата.
 *
 * @package    restmancard
 * @subpackage Task
 */
class Task_Restmancard_Seed extends Minion_Task
{
    /** Корневых организаций по умолчанию. */
    const DEFAULT_ROOTS = 5;

    /** Дочерних организаций у каждой корневой по умолчанию. */
    const DEFAULT_CHILDREN = 3;

    /** Верхняя граница --count (защита от случайного запуска «на тысячу»). */
    const MAX_ROOTS = 100;

    /** Верхняя граница --children. */
    const MAX_CHILDREN = 100;

    /**
     * Опции задачи.
     *
     * @var array
     */
    protected $_options = array(
        'count'    => self::DEFAULT_ROOTS,
        'children' => self::DEFAULT_CHILDREN,
        'dry-run'  => 0,
        'encoding' => 'auto',
    );

    /**
     * Кодировка вывода в консоль (файл задачи — в UTF-8).
     *
     * @var string
     */
    protected $_encoding = 'UTF-8';

    /**
     * Счётчики выполнения.
     *
     * @var array
     */
    protected $_stats = array(
        'created' => 0,
        'skipped' => 0,
        'errors'  => 0,
    );

    /**
     * Точка входа Minion.
     *
     * @param   array  $params  опции командной строки
     * @return  void
     */
    protected function _execute(array $params)
    {
        $this->_encoding = $this->console_encoding(Arr::get($params, 'encoding', 'auto'));

        $roots    = (int) Arr::get($params, 'count', self::DEFAULT_ROOTS);
        $children = (int) Arr::get($params, 'children', self::DEFAULT_CHILDREN);
        $dry_run  = ((int) Arr::get($params, 'dry-run', 0) === 1);

        $this->write('=== Тестовые организации (задача restmancard:seed) ===');

        if ($roots < 1 OR $roots > self::MAX_ROOTS)
        {
            $this->fail('параметр --count должен быть от 1 до ' . self::MAX_ROOTS . '.');
        }

        if ($children < 0 OR $children > self::MAX_CHILDREN)
        {
            $this->fail('параметр --children должен быть от 0 до ' . self::MAX_CHILDREN . '.');
        }

        $org_model = Model::factory('Restmancard_Org');
        $api       = Model::factory('Restmancard_Api');

        $this->write('API:            ' . $api->url('/api/v2/organizations'));
        $this->write('План:           корней ' . $roots . ', дочерних у каждого ' . $children
            . ' (всего ' . ($roots + $roots * $children) . ')');
        $this->write('Режим:          ' . ($dry_run
            ? 'DRY-RUN — данные не изменяются'
            : 'СОЗДАНИЕ ОРГАНИЗАЦИЙ'));

        // Свежий список: кеш мог сохраниться от веб-страницы или прошлого запуска.
        $api->flush();

        try
        {
            $existing = $org_model->getAllOrganizations();
        }
        catch (Exception $e)
        {
            $this->fail('не удалось прочитать организации из REST API: ' . $e->getMessage());
        }

        $this->write('До запуска:     организаций ' . count($existing));
        $this->write('');

        $planned = array('roots' => 0, 'children' => 0);

        for ($n = 1; $n <= $roots; $n++)
        {
            $root_name = sprintf('Тестовая организация %d', $n);
            $root      = $this->find_org($existing, $root_name, NULL);

            if ($root !== NULL)
            {
                $root_code = $root['DIVCODE'];
                $this->skip($root_name, $root_code, 0);
            }
            elseif ($dry_run)
            {
                $this->write('[DRY-RUN] создать корень: ' . $root_name);
                $planned['roots']++;
                $root_code = NULL;
            }
            else
            {
                try
                {
                    $created   = $this->create_org($org_model, $root_name, NULL);
                    $root_code = $created['DIVCODE'];
                    $existing[] = $created;
                    $this->created($root_name, $created['DIVCODE'], 0, NULL);
                }
                catch (Exception $e)
                {
                    $this->error($root_name, 0, $e);
                    $root_code = NULL;

                    // Без корня дочерние создать нельзя — переходим к следующему.
                    continue;
                }
            }

            for ($c = 1; $c <= $children; $c++)
            {
                $child_name = sprintf('Подразделение %d.%d', $n, $c);

                if ($root_code === NULL)
                {
                    // dry-run: код корня ещё не сформирован
                    $this->write('[DRY-RUN]   создать дочернюю: ' . $child_name
                        . ' (родитель: ' . $root_name . ')');
                    $planned['children']++;
                    continue;
                }

                $child = $this->find_org($existing, $child_name, $root_code);

                if ($child !== NULL)
                {
                    $this->skip($child_name, $child['DIVCODE'], 1);
                    continue;
                }

                try
                {
                    $created = $this->create_org($org_model, $child_name, $root_code);
                    $existing[] = $created;
                    $this->created($child_name, $created['DIVCODE'], 1, $root_code);
                }
                catch (Exception $e)
                {
                    $this->error($child_name, 1, $e);
                }
            }
        }

        $this->write('');

        if ($dry_run)
        {
            $this->write('DRY-RUN завершён: будет создано '
                . ($planned['roots'] + $planned['children'])
                . ' организаций (корней ' . $planned['roots']
                . ', дочерних ' . $planned['children'] . ').');
            $this->write('Для реального создания запустите задачу с --dry-run=0.');

            return;
        }

        // Проверяем результат по независимому чтению из API.
        $api->flush();

        try
        {
            $final = $org_model->getAllOrganizations();
        }
        catch (Exception $e)
        {
            $this->fail('организации созданы, но проверить их не удалось: ' . $e->getMessage());
        }

        $verified = $this->verify($final, $roots, $children);

        $this->write('');
        $this->write('Итого: создано ' . $this->_stats['created']
            . ', пропущено ' . $this->_stats['skipped']
            . ', ошибок ' . $this->_stats['errors'] . '.');
        $this->write('Проверка: ' . ($verified
            ? 'все ' . $roots . ' корней и ' . ($roots * $children) . ' дочерних найдены — OK'
            : 'структура не совпадает с планом — см. сообщения выше.'));

        if ( ! $verified OR $this->_stats['errors'] > 0)
        {
            exit(1);
        }
    }

    /**
     * Найти организацию по названию и родителю.
     *
     * @param   array       $orgs    список из Model_Restmancard_Org::getAllOrganizations()
     * @param   string      $name    название
     * @param   string|NULL $parent  divcode родителя; NULL — корневая
     * @return  array|NULL
     */
    protected function find_org(array $orgs, $name, $parent)
    {
        foreach ($orgs as $org)
        {
            $org_parent = Arr::get($org, 'PARENT');

            if ($org_parent === '')
            {
                $org_parent = NULL;
            }

            if ($org['NAME'] === $name AND $org_parent === $parent)
            {
                return $org;
            }
        }

        return NULL;
    }

    /**
     * Создать организацию через модель модуля.
     *
     * @param   Model_Restmancard_Org  $org_model
     * @param   string                 $name
     * @param   string|NULL            $parent
     * @return  array  созданная организация
     * @throws  Exception
     */
    protected function create_org(Model_Restmancard_Org $org_model, $name, $parent)
    {
        return $org_model->createOrganization(array(
            'name'         => $name,
            'divcode'      => '',
            'divcode_auto' => 1,
            'parent'       => ($parent === NULL) ? '' : $parent,
            'flag'         => 0,
        ));
    }

    /**
     * Сообщение о созданной организации.
     *
     * @param   string      $name
     * @param   string      $divcode
     * @param   int         $level   0 — корень, 1 — дочерняя
     * @param   string|NULL $parent
     * @return  void
     */
    protected function created($name, $divcode, $level, $parent)
    {
        $this->_stats['created']++;

        $line = ($level === 0 ? '[CREATE] ' : '[CREATE]   ') . $name;

        if ($level === 0)
        {
            $line .= ' (корень)';
        }
        else
        {
            $line .= ' (родитель: ' . $parent . ')';
        }

        $this->write($line . ', divcode: ' . $divcode);
    }

    /**
     * Сообщение о пропущенной (уже существующей) организации.
     *
     * @param   string $name
     * @param   string $divcode
     * @param   int    $level
     * @return  void
     */
    protected function skip($name, $divcode, $level)
    {
        $this->_stats['skipped']++;

        $this->write(($level === 0 ? '[SKIP] ' : '[SKIP]   ') . $name
            . ' — уже существует, divcode: ' . $divcode);
    }

    /**
     * Сообщение об ошибке создания.
     *
     * @param   string    $name
     * @param   int       $level
     * @param   Exception $e
     * @return  void
     */
    protected function error($name, $level, Exception $e)
    {
        $this->_stats['errors']++;

        $this->write(($level === 0 ? '[ERROR] ' : '[ERROR]   ') . $name
            . ' — ' . $e->getMessage());
    }

    /**
     * Проверить, что в API есть все организации из плана.
     *
     * @param   array $orgs
     * @param   int   $roots
     * @param   int   $children
     * @return  boolean
     */
    protected function verify(array $orgs, $roots, $children)
    {
        $ok = TRUE;

        for ($n = 1; $n <= $roots; $n++)
        {
            $root_name = sprintf('Тестовая организация %d', $n);
            $root      = $this->find_org($orgs, $root_name, NULL);

            if ($root === NULL)
            {
                $this->write('[CHECK] корень не найден: ' . $root_name);
                $ok = FALSE;
                continue;
            }

            for ($c = 1; $c <= $children; $c++)
            {
                $child_name = sprintf('Подразделение %d.%d', $n, $c);
                $child      = $this->find_org($orgs, $child_name, $root['DIVCODE']);

                if ($child === NULL)
                {
                    $this->write('[CHECK] дочерняя не найдена: ' . $child_name
                        . ' (родитель: ' . $root['DIVCODE'] . ')');
                    $ok = FALSE;
                }
            }
        }

        return $ok;
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
}
