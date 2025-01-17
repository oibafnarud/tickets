<?php
/**
 * Copyright (C) 2019-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Tickets;

require_once __DIR__ . '/vendor/autoload.php';

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\InitClass;
use FacturaScripts\Core\Base\ToolBox;
use FacturaScripts\Dinamic\Lib\ExportManager;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Init extends InitClass
{
    public function init()
    {
        ExportManager::addOption('Ticket', 'ticket', 'fas fa-receipt');
    }

    public function update()
    {
        // activamos la API
        $this->setAPI();

        // renombramos la tabla de tickets de antiguas versiones
        $this->renameTicketsTable('tickets', 'tickets_docs');
    }

    private function renameTicketsTable(string $oldTable, string $newTable)
    {
        $dataBase = new DataBase();
        if (false === $dataBase->tableExists($oldTable)) {
            return;
        }

        // comprobamos las columnas de la tabla antigua
        $columns = $dataBase->getColumns($oldTable);
        if (isset($columns['id']) && isset($columns['idprinter'])) {
            $dataBase->exec("RENAME TABLE " . $oldTable . " TO " . $newTable . ";");
        }
    }

    private function setAPI()
    {
        // si hay clave de API en el config, no hacemos nada
        if (defined('FS_API_KEY')) {
            return;
        }

        // activamos la API
        $appSettings = ToolBox::appSettings();
        $appSettings->set('default', 'enable_api', true);
        $appSettings->save();
    }
}