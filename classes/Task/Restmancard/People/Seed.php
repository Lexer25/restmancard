<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Задача restmancard:people:seed — тестовые сотрудники в каждой организации.
 *
 * Для каждой организации из GET /api/v2/organizations задача создаёт
 * заданное число сотрудников (по умолчанию 3). Табельный номер формируется
 * как <prefix>_<ID_ORG>_<N> и начинается с «test»:
 *
 *   test_4_1, test_4_2, test_4_3   — для организации с ID_ORG = 4.
 *
 * Сотрудник создаётся через POST /api/v2/Person (tabnum, surname, name,
 * patronymic), затем привязывается к организации через PATCH /api/v2/Person
 * (tabnum, id_org): в теле POST организация не передаётся по контракту API.
 *
 * Способы запуска (Windows):
 *
 *   1. По 3 сотрудника в каждую организацию:
 *      c:\xampp\php\php.exe c:\xampp\htdocs\city\modules\minion\minion --task=restmancard:people:seed
 *
 *   2. Другое количество сотрудников на организацию:
 *      ... --task=restmancard:people:seed --count=5
 *
 *   3. Предварительный просмотр без изменения данных:
 *      ... --task=restmancard:people:seed --dry-run=1
 *
 *   4. Свой префикс tabnum (по умолчанию test):
 *      ... --task=restmancard:people:seed --prefix=qa
 *
 *   5. Кодировка вывода в консоль:
 *      ... --task=restmancard:people:seed --encoding=cp866
 *
 *   6. Справка Minion по задаче:
 *      ... --task=restmancard:people:seed --help
 *
 * Задача идемпотентна: если сотрудник с таким tabnum уже существует, он не
 * создаётся повторно, но задача всё равно отправляет PATCH id_org, чтобы
 * восстановить привязку к организации после прерванного запуска. Повторный
 * запуск не создаёт дубликатов.
 *
 * Параметры:
 *   --count    сотрудников на каждую организацию (1–50, по умолчанию 3);
 *   --prefix   префикс tabnum (по умолчанию test, латиница/цифры/._-);
 *   --dry-run  1 — только показать план (по умолчанию 0);
 *   --encoding auto|utf8|cp866|cp1251 (по умолчанию auto).
 *
 * Коды возврата: 0 — все сотрудники созданы и привязаны; 1 — ошибка входных
 * данных, REST API или проверки результата.
 *
 * @package    restmancard
 * @subpackage Task
 */
class Task_Restmancard_People_Seed extends Restmancard_Task
{
    /** Сотрудников на организацию по умолчанию. */
    const DEFAULT_COUNT = 3;

    /** Верхняя граница --count. */
    const MAX_COUNT = 50;

    /** Префикс tabnum по умолчанию. */
    const DEFAULT_PREFIX = 'test';

    /** Максимальная длина --prefix (остальное — ID организации и номер). */
    const MAX_PREFIX_LENGTH = 20;

    /**
     * Опции задачи.
     *
     * @var array
     */
    protected $_options = array(
        'count'    => self::DEFAULT_COUNT,
        'prefix'   => self::DEFAULT_PREFIX,
        'dry-run'  => 0,
        'encoding' => 'auto',
    );

    /**
     * Счётчики выполнения.
     *
     * @var array
     */
    protected $_stats = array(
        'created'  => 0,
        'assigned' => 0,
        'errors'   => 0,
    );

    /**
     * Точка входа Minion.
     *
     * @param   array  $params  опции командной строки
     * @return  void
     */
    protected function _execute(array $params)
    {
        $this->setup_encoding($params);

        $count    = (int) Arr::get($params, 'count', self::DEFAULT_COUNT);
        $prefix   = trim((string) Arr::get($params, 'prefix', self::DEFAULT_PREFIX));
        $dry_run  = ((int) Arr::get($params, 'dry-run', 0) === 1);

        $this->write('=== Тестовые сотрудники (задача restmancard:people:seed) ===');

        if ($count < 1 OR $count > self::MAX_COUNT)
        {
            $this->fail('параметр --count должен быть от 1 до ' . self::MAX_COUNT . '.');
        }

        if ($prefix === '')
        {
            $this->fail('параметр --prefix не должен быть пустым.');
        }

        if (strlen($prefix) > self::MAX_PREFIX_LENGTH)
        {
            $this->fail('параметр --prefix не должен превышать ' . self::MAX_PREFIX_LENGTH . ' символов.');
        }

        if ( ! preg_match('/^[A-Za-z0-9._-]+$/', $prefix))
        {
            $this->fail('параметр --prefix может содержать только латиницу, цифры, «-», «_» и «.»');
        }

        // Убираем разделители по краям, чтобы не получить test__4_1
        $base_prefix = rtrim($prefix, '._-');

        if ($base_prefix === '')
        {
            $this->fail('параметр --prefix не должен состоять только из разделителей.');
        }

        $api          = Model::factory('Restmancard_Api');
        $org_model    = Model::factory('Restmancard_Org');
        $people_model = Model::factory('Restmancard_People');

        $this->write('API:      ' . $api->url('/api/v2/Person'));
        $this->write('План:     по ' . $count . ' сотрудников в каждой организации');
        $this->write('tabnum:   ' . $base_prefix . '_<ID_ORG>_<N>, начинается с «' . $prefix . '»');
        $this->write('Режим:    ' . ($dry_run
            ? 'DRY-RUN — данные не изменяются'
            : 'СОЗДАНИЕ И ПРИВЯЗКА СОТРУДНИКОВ'));

        // Свежий список организаций и сотрудников.
        $api->flush();

        try
        {
            $orgs = $org_model->getAllOrganizations();
        }
        catch (Exception $e)
        {
            $this->fail('не удалось прочитать организации из REST API: ' . $e->getMessage());
        }

        try
        {
            $bundle = $people_model->getAll();
        }
        catch (Exception $e)
        {
            $this->fail('не удалось прочитать сотрудников из REST API: ' . $e->getMessage());
        }

        if ( ! empty($bundle['truncated']))
        {
            $this->write('[WARN] список сотрудников обрезан настройкой people.max_pages —');
            $this->write('       существующие tabnum проверены не полностью, возможны ответы 409.');
        }

        $existing = array();
        foreach ($bundle['people'] as $person)
        {
            $tabnum = trim((string) Arr::get($person, 'TABNUM', ''));

            if ($tabnum !== '')
            {
                $existing[strtolower($tabnum)] = TRUE;
            }
        }

        $this->write('До запуска: организаций ' . count($orgs)
            . ', сотрудников ' . count($bundle['people']) . '.');
        $this->write('');

        $planned = array('create' => 0, 'assign' => 0);

        foreach ($orgs as $org)
        {
            $org_id   = (int) Arr::get($org, 'ID', 0);
            $org_name = trim((string) Arr::get($org, 'NAME', ''));
            $org_code = trim((string) Arr::get($org, 'DIVCODE', ''));
            $label    = ($org_name !== '' ? $org_name : '(без названия)')
                . ' [' . $org_code . ', ID ' . $org_id . ']';

            if ($org_id <= 0)
            {
                $this->error('организация без ID: ' . $label);
                continue;
            }

            for ($n = 1; $n <= $count; $n++)
            {
                $tabnum = $base_prefix . '_' . $org_id . '_' . $n;
                $key    = strtolower($tabnum);

                // Сотрудник уже есть — восстанавливаем/подтверждаем привязку.
                if (isset($existing[$key]))
                {
                    if ($dry_run)
                    {
                        $this->write('[DRY-RUN] привязать существующего: ' . $tabnum . ' -> ' . $label);
                        $planned['assign']++;
                        continue;
                    }

                    try
                    {
                        $people_model->assignToOrganization($tabnum, $org_id);
                        $this->_stats['assigned']++;
                        $this->write('[ASSIGN] ' . $tabnum . ' -> ' . $label);
                    }
                    catch (Exception $e)
                    {
                        $this->error('не удалось привязать ' . $tabnum . ' к ' . $label, $e);
                    }

                    continue;
                }

                if ($dry_run)
                {
                    $this->write('[DRY-RUN] создать: ' . $tabnum . ' -> ' . $label);
                    $planned['create']++;
                    continue;
                }

                try
                {
                    $created = $people_model->createPerson(array(
                        'tabnum'     => $tabnum,
                        'surname'    => 'Тестов',
                        'name'       => 'Сотрудник',
                        'patronymic' => 'Организация ' . $org_id,
                    ));
                }
                catch (Exception $e)
                {
                    // 409 — сотрудник появился между чтением списка и POST.
                    if ($this->api_status($api) === 409)
                    {
                        $existing[$key] = TRUE;

                        try
                        {
                            $people_model->assignToOrganization($tabnum, $org_id);
                            $this->_stats['assigned']++;
                            $this->write('[ASSIGN] ' . $tabnum . ' -> ' . $label);
                        }
                        catch (Exception $assign_error)
                        {
                            $this->error('не удалось привязать ' . $tabnum . ' к ' . $label, $assign_error);
                        }

                        continue;
                    }

                    $this->error('не удалось создать ' . $tabnum, $e);
                    continue;
                }

                // Привязка к организации; при ошибке откатываем создание,
                // чтобы повторный запуск не пропустил непривязанного сотрудника.
                try
                {
                    $people_model->assignToOrganization($created['TABNUM'], $org_id);
                    $this->_stats['created']++;
                    $existing[$key] = TRUE;
                    $this->write('[CREATE] ' . $created['TABNUM'] . ' -> ' . $label);
                }
                catch (Exception $e)
                {
                    $this->write('[WARN] ' . $created['TABNUM']
                        . ': создан, но не привязан к организации — ' . $e->getMessage());

                    try
                    {
                        $people_model->deletePerson($created['TABNUM']);
                        $this->write('[ROLLBACK] ' . $created['TABNUM'] . ' удалён');
                    }
                    catch (Exception $rollback_error)
                    {
                        $this->write('[WARN] не удалось откатить ' . $created['TABNUM']
                            . ': ' . $rollback_error->getMessage());
                    }

                    $this->_stats['errors']++;
                }
            }
        }

        if ($dry_run)
        {
            $this->write('');
            $this->write('DRY-RUN завершён: будет создано ' . $planned['create']
                . ', привязано существующих ' . $planned['assign']
                . ' (всего ' . ($planned['create'] + $planned['assign']) . ').');
            $this->write('Для реального запуска выполните задачу с --dry-run=0.');

            return;
        }

        // Проверка по независимому чтению: все ожидаемые tabnum должны быть
        // в списке сотрудников. Организацию в GET /api/v2/Person/GetList
        // API не возвращает, поэтому привязка подтверждается ответом PATCH.
        $api->flush();

        try
        {
            $final_bundle = $people_model->getAll();
        }
        catch (Exception $e)
        {
            $this->fail('сотрудники созданы, но проверить их не удалось: ' . $e->getMessage());
        }

        $final = array();
        foreach ($final_bundle['people'] as $person)
        {
            $tabnum = trim((string) Arr::get($person, 'TABNUM', ''));

            if ($tabnum !== '')
            {
                $final[strtolower($tabnum)] = TRUE;
            }
        }

        $missing = array();

        foreach ($orgs as $org)
        {
            $org_id = (int) Arr::get($org, 'ID', 0);

            if ($org_id <= 0)
            {
                continue;
            }

            for ($n = 1; $n <= $count; $n++)
            {
                $tabnum = $base_prefix . '_' . $org_id . '_' . $n;

                if ( ! isset($final[strtolower($tabnum)]))
                {
                    $missing[] = $tabnum;
                }
            }
        }

        $this->write('');
        $this->write('Итого: создано ' . $this->_stats['created']
            . ', привязано существующих ' . $this->_stats['assigned']
            . ', ошибок ' . $this->_stats['errors'] . '.');

        if ( ! empty($final_bundle['truncated']))
        {
            $this->write('[WARN] итоговый список сотрудников обрезан — проверка неполная.');
        }

        if (empty($missing))
        {
            $this->write('Проверка: все ожидаемые tabnum найдены — OK.');
        }
        else
        {
            $this->write('Проверка: не найдено сотрудников — ' . count($missing) . ':');

            foreach (array_slice($missing, 0, 20) as $tabnum)
            {
                $this->write('  ' . $tabnum);
            }

            if (count($missing) > 20)
            {
                $this->write('  ... и ещё ' . (count($missing) - 20));
            }
        }

        if ( ! empty($missing) OR $this->_stats['errors'] > 0)
        {
            exit(1);
        }
    }

    /**
     * Сообщение об ошибке.
     *
     * @param   string         $message
     * @param   Exception|NULL $e
     * @return  void
     */
    protected function error($message, Exception $e = NULL)
    {
        $this->_stats['errors']++;

        $this->write('[ERROR] ' . $message . ($e !== NULL ? ' — ' . $e->getMessage() : ''));
    }
}
