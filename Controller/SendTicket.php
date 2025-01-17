<?php
/**
 * Copyright (C) 2019-2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Tickets\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Model\ReciboCliente;
use FacturaScripts\Plugins\Servicios\Model\ServicioAT;
use FacturaScripts\Dinamic\Lib\Tickets\Gift;
use FacturaScripts\Dinamic\Lib\Tickets\Normal;
use FacturaScripts\Dinamic\Lib\Tickets\PaymentReceipt;
use FacturaScripts\Dinamic\Lib\Tickets\Service;
use FacturaScripts\Dinamic\Lib\Tickets\TicketBai;
use FacturaScripts\Plugins\Tickets\Model\TicketPrinter;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class SendTicket extends Controller
{
    /** @var array */
    public $formats = [];

    /** @var string */
    public $modelClassName = '';

    /** @var string */
    public $modelCode = '';

    /** @var TicketPrinter[] */
    public $printers = [];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'print-ticket';
        $data['icon'] = 'fas fa-receipt';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->modelClassName = $this->request->get('modelClassName');
        $this->modelCode = $this->request->get('modelCode');
        $this->loadPrinters();
        $this->loadFormats();

        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $this->modelClassName;
        if (empty($this->modelClassName) || empty($this->modelCode) || false === class_exists($modelClass)) {
            $this->setTemplate('Error/SendTicket');
            return;
        }

        $model = new $modelClass();
        if (false === $model->loadFromCode($this->modelCode)) {
            $this->setTemplate('Error/SendTicket');
            return;
        }

        $format = $this->request->request->get('format');
        $printer = $this->getPrinter((int)$this->request->request->get('printer'));
        switch ($format) {
            case 'gift':
                $this->printGift($model, $printer);
                break;

            case 'normal':
                $this->printNormal($model, $printer);
                break;

            case 'receipt':
                $this->printPaymentReceipt($model, $printer);
                break;

            case 'service':
                $this->printService($model, $printer);
                break;

            case 'ticketbai':
                $this->printTicketBai($model, $printer);
                break;
        }
    }

    protected function getPrinter(int $id): TicketPrinter
    {
        foreach ($this->printers as $printer) {
            if ($printer->id === $id) {
                return $printer;
            }
        }

        return new TicketPrinter();
    }

    protected function loadFormats()
    {
        switch ($this->modelClassName) {
            case 'AlbaranCliente':
            case 'FacturaCliente':
            case 'PedidoCliente':
            case 'PresupuestoCliente':
                $this->formats[] = 'normal';
                $this->formats[] = 'gift';
                $this->formats[] = 'ticketbai';
                break;

            case 'ReciboCliente':
                $this->formats[] = 'receipt';
                break;

            case 'ServicioAT':
                $this->formats[] = 'service';
                break;
        }
    }

    protected function loadPrinters()
    {
        $printerModel = new TicketPrinter();
        $this->printers = $printerModel->all([], ['creationdate' => 'DESC'], 0, 0);
    }

    protected function printGift(SalesDocument $model, TicketPrinter $printer)
    {
        if (Gift::print($model, $printer, $this->user)) {
            $this->toolBox()->i18nLog()->notice('sending-to-printer');
            $this->redirect($model->url(), 1);
        }
    }

    protected function printNormal(SalesDocument $model, TicketPrinter $printer)
    {
        if (Normal::print($model, $printer, $this->user)) {
            $this->toolBox()->i18nLog()->notice('sending-to-printer');
            $this->redirect($model->url(), 1);
        }
    }

    protected function printPaymentReceipt(ReciboCliente $model, TicketPrinter $printer)
    {
        if (PaymentReceipt::print($model, $printer, $this->user)) {
            $this->toolBox()->i18nLog()->notice('sending-to-printer');
            $this->redirect($model->url(), 1);
        }
    }

    protected function printService(ServicioAT $model, TicketPrinter $printer)
    {
        if (Service::print($model, $printer, $this->user)) {
            $this->toolBox()->i18nLog()->notice('sending-to-printer');
            $this->redirect($model->url(), 1);
        }
    }

    protected function printTicketBai(SalesDocument $model, TicketPrinter $printer)
    {
        if (TicketBai::print($model, $printer, $this->user)) {
            $this->toolBox()->i18nLog()->notice('sending-to-printer');
            $this->redirect($model->url(), 1);
        }
    }
}