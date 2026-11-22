<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Задача restmancard:people:delete — удаление тестовых сотрудников.
 *
 * Читает всех сотрудников через GET /api/v2/Person/GetList, отбирает те,
 * чей tabnum начинается с заданного префикса (по умолчанию «test»), и удаляет
 * каждого через DELETE /api/v2/Person?tabnum=... Вместе с сотрудником API
 * удаляет его идентификаторы (карты).
 *
 * Способы запуска (Windows):
 *
 *   1. Удалить всех сотрудников с tabnum, начинающимся на «test»:
 *      c:\xampp\php\php.exe c:\xampp\htdocs\city\modules\minion\minion --task=restmancard:people:delete
 *
 *   2. Сначала посмотреть, кто будет удалён, ничего не меняя:
 *      ... --task=restmancard:people:delete --dry-run=1
 *
 *   3. Другой префикс tabnum:
 *      ... --task=restmancard:people:delete --prefix=qa
 *
 *   4. Кодировка вывода в консоль:
 *      ... --task=restmancard:people:delete --encoding=cp866
 *
 *   5. Справка Minion по задаче:
 *      ... --task=restmancard:people:delete --help
 *
 * Параметры:
 *   --prefix   префикс tabnum (по умолчанию test, латиница/цифры/._-);
 *   --dry-run  1 — только показать список, ничего не удалять (по умолчанию 0);
 *   --encoding auto|utf8|cp866|cp1251 (по умолчанию auto).
 *
 * Сравнение префикса регистронезависимое (test, Test и TEST удаляются
 * одинаково). Пустой префикс запрещён, чтобы случайно не удалить всех
 * сотрудников. Если список сотрудников обрезан настройкой people.max_pages,
 * задача завершается до удаления — иначе часть тестовых сотрудников осталась
 * бы в базе. Увеличение restmancard.people.max_pages снимает ограничение.
 *
 * Коды возврата: 0 — тестовые сотрудники удалены (или их не было);
 *                1 — ошибка входных данных, REST API или проверки.
 *
 * @package    restmancard
 * @subpackage Task
 */
class Task_Restmancard_People_Delete extends Restmancard_Task
{
    /** Префикс tabnum по умолчанию. */
    const DEFAULT_PREFIX = 'test';

    /** Максимальная длина --prefix. */
    const MAX_PREFIX_LENGTH = 50;

    /**
     * Опции задачи.
     *
     * @var array
     */
    protected $_options = array(
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
        'deleted'   => 0,
        'not_found' => 0,
        'errors'    => 0,
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

        $prefix  = trim((string) Arr::get($params, 'prefix', self::DEFAULT_PREFIX));
        $dry_run = ((int) Arr::get($params, 'dry-run', 0) === 1);

        $this->write('=== Удаление тестовых сотрудников (задача restmancard:people:delete) ===');

        if ($prefix === '')
        {
            $this->fail('параметр --prefix не должен быть пустым: иначе будут удалены все сотрудники.');
        }

        if (strlen($prefix) > self::MAX_PREFIX_LENGTH)
        {
            $this->fail('параметр --prefix не должен превышать ' . self::MAX_PREFIX_LENGTH . ' символов.');
        }

        if ( ! preg_match('/^[A-Za-z0-9._-]+$/', $prefix))
        {
            $this->fail('параметр --prefix может содержать только латиницу, цифры, «-», «_» и «.»');
        }

        $api          = Model::factory('Restmancard_Api');
        $people_model = Model::factory('Restmancard_People');

        $this->write('API:      ' . $api->url('/api/v2/Person'));
        $this->write('Префикс:  ' . $prefix . ' (сравнение без учёта регистра)');
        $this->write('Режим:    ' . ($dry_run
            ? 'DRY-RUN — данные не изменяются'
            : 'УДАЛЕНИЕ СОТРУДНИКОВ'));

        // Свежий список сотрудников.
        $api->flush();

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
            $this->fail('список сотрудников обрезан настройкой restmancard.people.max_pages ('
                . 'загружено ' . (int) $bundle['loaded'] . '). Удаление отменено, чтобы не оставить '
                . 'часть тестовых сотрудников. Увеличьте people.max_pages и повторите запуск.');
        }

        $matched = array();

        foreach ($bundle['people'] as $person)
        {
            $tabnum = trim((string) Arr::get($person, 'TABNUM', ''));

            if ($tabnum !== '' AND $this->starts_with($tabnum, $prefix))
            {
                $matched[] = $person;
            }
        }

        $this->write('Всего сотрудников: ' . count($bundle['people'])
            . ', подлежит удалению: ' . count($matched) . '.');
        $this->write('');

        if (empty($matched))
        {
            $this->write('Сотрудников с префиксом «' . $prefix . '» не найдено — удалять нечего.');

            return;
        }

        foreach ($matched as $person)
        {
            $tabnum  = trim((string) Arr::get($person, 'TABNUM', ''));
            $full    = trim((string) Arr::get($person, 'FULL_NAME', ''));

            if ($full === '')
            {
                $full = trim((string) Arr::get($person, 'SURNAME', '') . ' '
                    . (string) Arr::get($person, 'NAME', '') . ' '
                    . (string) Arr::get($person, 'PATRONYMIC', ''));
            }

            $label = $tabnum . ($full !== '' ? ' (' . $full . ')' : '');

            if ($dry_run)
            {
                $this->write('[DRY-RUN] удалить: ' . $label);
                continue;
            }

            try
            {
                $people_model->deletePerson($tabnum);
                $this->_stats['deleted']++;
                $this->write('[DELETE] ' . $label);
            }
            catch (Exception $e)
            {
                if ($this->api_status($api) === 404)
                {
                    $this->_stats['not_found']++;
                    $this->write('[SKIP] ' . $label . ' — уже удалён');
                    continue;
                }

                $this->_stats['errors']++;
                $this->write('[ERROR] ' . $label . ' — ' . $e->getMessage());
            }
        }

        if ($dry_run)
        {
            $this->write('');
            $this->write('DRY-RUN завершён: будет удалено ' . count($matched) . ' сотрудников.');
            $this->write('Для реального удаления выполните задачу с --dry-run=0.');

            return;
        }

        // Проверка по независимому чтению: сотрудников с префиксом быть не должно.
        $api->flush();

        try
        {
            $final_bundle = $people_model->getAll();
        }
        catch (Exception $e)
        {
            $this->fail('сотрудники удалены, но проверить результат не удалось: ' . $e->getMessage());
        }

        $remaining = array();

        foreach ($final_bundle['people'] as $person)
        {
            $tabnum = trim((string) Arr::get($person, 'TABNUM', ''));

            if ($tabnum !== '' AND $this->starts_with($tabnum, $prefix))
            {
                $remaining[] = $tabnum;
            }
        }

        $this->write('');
        $this->write('Итого: удалено ' . $this->_stats['deleted']
            . ', уже отсутствовало ' . $this->_stats['not_found']
            . ', ошибок ' . $this->_stats['errors'] . '.');

        if (empty($remaining))
        {
            $this->write('Проверка: сотрудников с префиксом «' . $prefix . '» не осталось — OK.');
        }
        else
        {
            $this->write('Проверка: осталось сотрудников с префиксом «' . $prefix . '» — '
                . count($remaining) . ':');

            foreach (array_slice($remaining, 0, 20) as $tabnum)
            {
                $this->write('  ' . $tabnum);
            }

            if (count($remaining) > 20)
            {
                $this->write('  ... и ещё ' . (count($remaining) - 20));
            }
        }

        if ( ! empty($remaining) OR $this->_stats['errors'] > 0)
        {
            exit(1);
        }
    }
}
