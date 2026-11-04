<?php

namespace GlpiPlugin\Backupinfo;

use CommonDBTM;
use Computer;
use Document_Item;
use Glpi\DBAL\QueryFunction;
use Dropdown;
use Html;
use Log;
use Session;
use User;

/**
 * A restore test of one Computer's backup (ISO 27001 A.8.13): entered by people, or imported from a
 * Vinchin verification (source = vinchin, read-only). Only a passed test restarts the interval clock.
 */
class RestoreTest extends CommonDBTM
{
    public static $rightname = RightDefinition::RESTORETEST;
    public $dohistory = true;

    public const TABLE          = 'glpi_plugin_backupinfo_restoretests';
    public const SOURCE_MANUAL  = 'manual';
    public const SOURCE_VINCHIN = 'vinchin';
    public const METHOD_VINCHIN = 'vinchin_verify';
    public const PASS           = 'pass';

    public static function getTypeName($nb = 0)
    {
        return _n('Restore test', 'Restore tests', $nb, 'backupinfo');
    }

    public static function getIcon()
    {
        return 'ti ti-restore';
    }

    /** Profile matrix columns: no trash and no notes, so read / create / update / purge. */
    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        unset($values[DELETE], $values[READNOTE], $values[UPDATENOTE]);
        return $values;
    }

    /** @return array<string, string> */
    public static function methods(): array
    {
        return [
            self::METHOD_VINCHIN => __('Vinchin verification', 'backupinfo'),
            'file_restore'       => __('File restore', 'backupinfo'),
            'vm_restore'         => __('Full VM restore', 'backupinfo'),
            'dr_drill'           => __('DR drill', 'backupinfo'),
        ];
    }

    /** Methods a person may record; Vinchin verifications are imported by the sync. */
    public static function manualMethods(): array
    {
        return array_diff_key(self::methods(), [self::METHOD_VINCHIN => true]);
    }

    /** @return array<string, string> */
    public static function results(): array
    {
        return [
            self::PASS => __('Pass', 'backupinfo'),
            'partial'  => __('Partial', 'backupinfo'),
            'fail'     => __('Fail', 'backupinfo'),
        ];
    }

    /**
     * Pure: checks and normalises a posted record. On add every field is required; on update only
     * the posted ones are checked, and the Computer, entity and source cannot change.
     *
     * @param array<string, mixed> $input
     * @return array{input: array<string, mixed>, errors: list<string>}
     */
    public static function validate(array $input, int $nowS, bool $isAdd): array
    {
        $errors = [];
        unset($input['source_ref'], $input['entities_id'], $input['is_recursive']);
        if ($isAdd) {
            $input['source'] = self::SOURCE_MANUAL;
        } else {
            unset($input['source'], $input['computers_id']);
        }

        if ($isAdd || array_key_exists('computers_id', $input)) {
            $input['computers_id'] = (int) ($input['computers_id'] ?? 0);
            if ($input['computers_id'] <= 0) {
                $errors[] = __('Choose the computer that was restore-tested.', 'backupinfo');
            }
        }
        if ($isAdd || array_key_exists('date', $input)) {
            $date = trim((string) ($input['date'] ?? ''));
            $d    = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($d === false || $d->format('Y-m-d') !== $date) {
                $errors[] = __('The test date is not a valid date.', 'backupinfo');
            } elseif ($date > date('Y-m-d', $nowS)) {
                $errors[] = __('The test date cannot be in the future.', 'backupinfo');
            }
            $input['date'] = $date;
        }
        if ($isAdd || array_key_exists('method', $input)) {
            if (!isset(self::manualMethods()[(string) ($input['method'] ?? '')])) {
                $errors[] = __('Choose how the restore was tested.', 'backupinfo');
            }
        }
        if ($isAdd || array_key_exists('result', $input)) {
            if (!isset(self::results()[(string) ($input['result'] ?? '')])) {
                $errors[] = __('Choose the result of the test.', 'backupinfo');
            }
        }
        if (array_key_exists('rto_minutes', $input)) {
            $rto = trim((string) $input['rto_minutes']);
            if ($rto === '') {
                $input['rto_minutes'] = null;
            } elseif (preg_match('/^\d{1,6}$/', $rto) === 1) {
                $input['rto_minutes'] = (int) $rto;
            } else {
                $errors[] = __('Restore time must be a whole number of minutes.', 'backupinfo');
            }
        }

        return ['input' => $input, 'errors' => $errors];
    }

    /** true only inside importVinchin(): a form can post any key, so the import path is not an input flag */
    private static bool $importing = false;

    /**
     * Stores one imported Vinchin verification (cron, no session). Read-only once stored; the
     * (source, source_ref) key makes a second import of the same row a no-op.
     *
     * @param array<string, mixed> $input from VerifyImport::toInput()
     */
    public static function importVinchin(array $input): bool
    {
        if (countElementsInTable(self::TABLE, ['source' => self::SOURCE_VINCHIN, 'source_ref' => $input['source_ref']]) > 0) {
            return false;
        }
        self::$importing = true;
        try {
            return (bool) (new self())->add($input);
        } finally {
            self::$importing = false;
        }
    }

    public function prepareInputForAdd($input)
    {
        if (self::$importing) {
            $computer = new Computer();
            if (!$computer->getFromDB((int) ($input['computers_id'] ?? 0))) {
                return false;
            }
            $input['entities_id']  = (int) $computer->getEntityID();
            $input['is_recursive'] = 0;
            $input['source']       = self::SOURCE_VINCHIN;
            return $input;
        }
        $v = self::validate($input, time(), true);
        if ($v['errors'] !== []) {
            return self::refuse($v['errors']);
        }
        $input    = $v['input'];
        $computer = new Computer();
        if (!$computer->can($input['computers_id'], READ)) {
            return self::refuse([__('That computer is not visible to you.', 'backupinfo')]);
        }
        $input['entities_id']  = (int) $computer->getEntityID();
        $input['is_recursive'] = 0;
        $input['users_id']     = (int) ($input['users_id'] ?? 0) ?: (int) Session::getLoginUserID();
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (($this->fields['source'] ?? '') === self::SOURCE_VINCHIN) {
            return false;
        }
        $v = self::validate($input, time(), false);
        return $v['errors'] !== [] ? self::refuse($v['errors']) : $v['input'];
    }

    /** @param list<string> $errors */
    private static function refuse(array $errors): bool
    {
        foreach ($errors as $e) {
            Session::addMessageAfterRedirect(htmlescape($e), false, ERROR);
        }
        return false;
    }

    public function canUpdateItem(): bool
    {
        if (($this->fields['source'] ?? '') === self::SOURCE_VINCHIN) {
            return false;
        }
        return parent::canUpdateItem();
    }

    public function canPurgeItem(): bool
    {
        if (($this->fields['source'] ?? '') === self::SOURCE_VINCHIN) {
            return false;
        }
        return parent::canPurgeItem();
    }

    public function getName($options = [])
    {
        if ($this->isNewItem()) {
            return self::getTypeName(1);
        }
        $computer = new Computer();
        $name     = $computer->getFromDB((int) $this->fields['computers_id']) ? $computer->getName() : '#' . (int) $this->fields['computers_id'];
        return sprintf('%s · %s', $name, (string) $this->fields['date']);
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Document_Item::class, $ong, $options);
        $this->addStandardTab(Log::class, $ong, $options);
        return $ong;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);
        $isNew    = $this->isNewItem();
        $imported = ($this->fields['source'] ?? '') === self::SOURCE_VINCHIN;

        echo "<tr class='tab_bg_1'><td>" . __s('Computer') . "</td><td>";
        if ($isNew) {
            Computer::dropdown(['name' => 'computers_id', 'value' => (int) ($options['computers_id'] ?? 0)]);
        } else {
            $computer = new Computer();
            echo $computer->getFromDB((int) $this->fields['computers_id']) ? $computer->getLink() : '-';
        }
        echo "</td><td>" . __s('Test date', 'backupinfo') . "</td><td>";
        Html::showDateField('date', ['value' => $this->fields['date'] ?? date('Y-m-d'), 'maybeempty' => false, 'canedit' => !$imported]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Method', 'backupinfo') . "</td><td>";
        Dropdown::showFromArray('method', $imported ? self::methods() : self::manualMethods(), ['value' => $this->fields['method'] ?? 'vm_restore', 'readonly' => $imported]);
        echo "</td><td>" . __s('Result', 'backupinfo') . "</td><td>";
        Dropdown::showFromArray('result', self::results(), ['value' => $this->fields['result'] ?? self::PASS, 'readonly' => $imported]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Restore time achieved (minutes)', 'backupinfo') . "</td><td>";
        echo Html::input('rto_minutes', ['value' => $this->fields['rto_minutes'] ?? '', 'type' => 'number', 'min' => 0]);
        echo "</td><td>" . __s('Tested by', 'backupinfo') . "</td><td>";
        User::dropdown(['name' => 'users_id', 'value' => (int) ($this->fields['users_id'] ?? 0) ?: (int) Session::getLoginUserID(), 'right' => 'all']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Source', 'backupinfo') . "</td><td colspan='3'>"
            . ($imported ? __s('Imported from Vinchin (read-only)', 'backupinfo') : __s('Entered by hand', 'backupinfo')) . "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Comments') . "</td><td colspan='3'>";
        echo "<textarea name='comment' cols='80' rows='4'>" . htmlescape($this->fields['comment'] ?? '') . "</textarea>";
        echo "<div class='form-hint'>" . __s('Attach the evidence (screenshots, signed form) in the Documents tab after saving.', 'backupinfo') . "</div>";
        echo "</td></tr>";

        $this->showFormButtons($options);
        return true;
    }

    public function rawSearchOptions()
    {
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 3, 'table' => 'glpi_computers', 'field' => 'name', 'linkfield' => 'computers_id', 'name' => __('Computer'), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 4, 'table' => self::getTable(), 'field' => 'date', 'name' => __('Test date', 'backupinfo'), 'datatype' => 'date'],
            ['id' => 5, 'table' => self::getTable(), 'field' => 'method', 'name' => __('Method', 'backupinfo'), 'datatype' => 'specific', 'searchtype' => 'equals', 'massiveaction' => false],
            ['id' => 6, 'table' => self::getTable(), 'field' => 'result', 'name' => __('Result', 'backupinfo'), 'datatype' => 'specific', 'searchtype' => 'equals', 'massiveaction' => false],
            ['id' => 7, 'table' => self::getTable(), 'field' => 'rto_minutes', 'name' => __('Restore time achieved (minutes)', 'backupinfo'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'name' => __('Tested by', 'backupinfo'), 'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false],
            ['id' => 9, 'table' => self::getTable(), 'field' => 'source', 'name' => __('Source', 'backupinfo'), 'datatype' => 'specific', 'searchtype' => 'equals', 'massiveaction' => false],
            ['id' => 16, 'table' => self::getTable(), 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text'],
            ['id' => 19, 'table' => self::getTable(), 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        $value = is_array($values) ? (string) ($values[$field] ?? '') : (string) $values;
        $labels = match ($field) {
            'method' => self::methods(),
            'result' => self::results(),
            'source' => [self::SOURCE_MANUAL => __('Entered by hand', 'backupinfo'), self::SOURCE_VINCHIN => __('Vinchin', 'backupinfo')],
            default  => null,
        };
        return $labels === null ? parent::getSpecificValueToDisplay($field, $values, $options) : htmlescape($labels[$value] ?? $value);
    }

    /** @return list<array<string, mixed>> newest first */
    public static function forComputer(int $computerId, int $limit): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['computers_id' => $computerId],
            'ORDER' => ['date DESC', 'id DESC'],
            'LIMIT' => $limit,
        ]) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Date of the last PASSED test per Computer: a failed test is shown but does not restart the clock.
     *
     * @param list<int> $computerIds
     * @return array<int, string> computers_id => Y-m-d
     */
    public static function lastPassDates(array $computerIds): array
    {
        global $DB;

        $out = [];
        foreach (array_chunk(array_values(array_unique($computerIds)), 500) as $chunk) {
            foreach ($DB->request([
                'SELECT'  => ['computers_id', QueryFunction::max('date', 'last')],
                'FROM'    => self::TABLE,
                'WHERE'   => ['computers_id' => $chunk, 'result' => self::PASS],
                'GROUPBY' => ['computers_id'],
            ]) as $row) {
                $out[(int) $row['computers_id']] = (string) $row['last'];
            }
        }
        return $out;
    }
}
