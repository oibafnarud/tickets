<?php
/**
 * Copyright (C) 2019-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Tickets\Lib\Tickets;

use FacturaScripts\Core\Base\ToolBox;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Model\Base\SalesDocumentLine;
use FacturaScripts\Dinamic\Model\Agente;
use FacturaScripts\Dinamic\Model\Base\ModelCore;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Tickets\Model\Ticket;
use FacturaScripts\Plugins\Tickets\Model\TicketPrinter;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class TicketBai
{
    /**
     * @param SalesDocument $doc
     * @param TicketPrinter $printer
     * @param User $user
     * @param Agente|null $agent
     * @return bool
     */
    public static function print($doc, TicketPrinter $printer, User $user, Agente $agent = null): bool
    {
        $i18n = ToolBox::i18n();

        $ticket = new Ticket();
        $ticket->idprinter = $printer->id;
        $ticket->nick = $user->nick;
        $ticket->title = $i18n->trans($doc->modelClassName() . '-min') . ' ' . $doc->codigo;
        $ticket->body = static::getBody($doc, $i18n, $printer, $ticket->title);
        $ticket->base64 = true;
        $ticket->appversion = 1;
        if ($agent) {
            $ticket->codagente = $agent->codagente;
        }

        return $ticket->save();
    }

    protected static function getBody($doc, $i18n, $printer, $title): string
    {
        // inicializamos la impresora virtual, para posteriormente obtener los comandos
        $connector = new DummyPrintConnector();
        $escpos = new Printer($connector);
        $escpos->initialize();

        if ($printer->print_stored_logo) {
            $escpos->setJustification(Printer::JUSTIFY_CENTER);
            // imprimimos el logotipo almacenado en la impresora
            $connector->write("\x1Cp\x01\x00\x00");
            $escpos->feed();
        }

        // imprimimos el nombre de la empresa
        $escpos->setTextSize(2, 2);
        $company = $doc->getCompany();
        $escpos->text(static::sanitize($company->nombre) . "\n");
        $escpos->setTextSize(1, 1);
        $escpos->setJustification();

        // imprimimos la dirección de la empresa
        $escpos->text(static::sanitize($company->direccion) . "\n");
        $escpos->text(static::sanitize("CP: " . $company->codpostal . ', ' . $company->ciudad) . "\n");
        $escpos->text(static::sanitize($company->tipoidfiscal . ': ' . $company->cifnif) . "\n\n");
        $escpos->text(static::sanitize($title) . "\n");
        $escpos->text(static::sanitize($i18n->trans('date') . ': ' . $doc->fecha . ' ' . $doc->hora) . "\n");
        $escpos->text(static::sanitize($i18n->trans('customer') . ': ' . $doc->nombrecliente) . "\n\n");

        // añadimos la cabecera
        if ($printer->head) {
            $escpos->setJustification(Printer::JUSTIFY_CENTER);
            $escpos->text(static::sanitize($printer->head) . "\n\n");
            $escpos->setJustification();
        }

        // añadimos las líneas
        $lines = $doc->getLines();
        static::printLines($printer, $escpos, $lines);

        foreach (self::getSubtotals($doc, $lines) as $item) {
            $text = sprintf("%" . ($printer->linelen - 11) . "s", $i18n->trans('tax-base') . ' ' . $item['taxp']) . " "
                . sprintf("%10s", ToolBox::numbers()::format($item['taxbase'])) . "\n"
                . sprintf("%" . ($printer->linelen - 11) . "s", $item['tax']) . " "
                . sprintf("%10s", ToolBox::numbers()::format($item['taxamount']));
            $escpos->text(static::sanitize($text) . "\n");

            if ($item['taxsurcharge']) {
                $text = sprintf("%" . ($printer->linelen - 11) . "s", "RE " . $item['taxsurchargep']) . " "
                    . sprintf("%10s", ToolBox::numbers()::format($item['taxsurcharge']));
                $escpos->text(static::sanitize($text) . "\n");
            }
        }
        $escpos->text($printer->getDashLine() . "\n");

        // añadimos los totales
        $text = sprintf("%" . ($printer->linelen - 11) . "s", $i18n->trans('total')) . " "
            . sprintf("%10s", ToolBox::numbers()::format($doc->total));
        $escpos->text(static::sanitize($text) . "\n");

        if ($printer->print_invoice_receipts && $doc->modelClassName() === 'FacturaCliente') {
            $escpos->text(static::sanitize(self::getReceipts($doc, $printer, $i18n)));
        }

        // añadimos el qr de ticketbai
        if (isset($doc->tbaicodbar)) {
            $escpos->setJustification(Printer::JUSTIFY_CENTER);
            $escpos->text("\n" . $doc->tbaicodbar . "\n");
            $escpos->qrCode($doc->tbaiurl, Printer::QR_ECLEVEL_L, 7);
            $escpos->setJustification();
        }

        // añadimos el pie de página
        if ($printer->footer) {
            $escpos->setJustification(Printer::JUSTIFY_CENTER);
            $escpos->text("\n" . static::sanitize($printer->footer) . "\n");
            $escpos->setJustification(Printer::JUSTIFY_LEFT);
        }

        // dejamos espacio, abrimos el cajón y cortamos el papel
        $escpos->feed();
        $escpos->feed();
        $escpos->feed();
        $escpos->feed();
        $escpos->pulse();
        $escpos->cut();

        // devolvemos los comandos de impresión
        $body = $escpos->getPrintConnector()->getData();
        $escpos->close();
        return base64_encode($body);
    }

    protected static function getReceipts($doc, $printer, $i18n): string
    {
        $paid = 0;
        $total = 0;
        $receipts = '';
        $widthTotal = $printer->linelen - 22;

        foreach ($doc->getReceipts() as $receipt) {
            if (false === empty($receipts)) {
                $receipts .= "\n";
            }

            $total += $receipt->importe;

            if (empty($receipt->fechapago)) {
                $datePaid = '';
            } else {
                $paid += $receipt->importe;
                $datePaid = date(ModelCore::DATE_STYLE, strtotime($receipt->fechapago));
            }

            $receipts .= sprintf("%10s", date(ModelCore::DATE_STYLE, strtotime($receipt->vencimiento))) . " "
                . sprintf("%10s", $datePaid)
                . sprintf("%" . $widthTotal . "s", ToolBox::numbers()::format($receipt->importe));
        }

        if (empty($receipts)) {
            return '';
        }

        return "\n\n"
            . sprintf("%" . $printer->linelen . "s", $i18n->trans('receipts')) . "\n"
            . sprintf("%10s", $i18n->trans('expiration-abb')) . " "
            . sprintf("%10s", $i18n->trans('paid')) . " "
            . sprintf("%" . $widthTotal . "s", $i18n->trans('total')) . "\n"
            . $printer->getDashLine() . "\n"
            . $receipts . "\n"
            . $printer->getDashLine() . "\n"
            . sprintf("%" . ($printer->linelen - $widthTotal - 1) . "s", $i18n->trans('total')) . " "
            . sprintf("%" . $widthTotal . "s", ToolBox::numbers()::format($total)) . "\n"
            . sprintf("%" . ($printer->linelen - $widthTotal - 1) . "s", $i18n->trans('paid')) . " "
            . sprintf("%" . $widthTotal . "s", ToolBox::numbers()::format($paid)) . "\n"
            . sprintf("%" . ($printer->linelen - $widthTotal - 1) . "s", $i18n->trans('pending')) . " "
            . sprintf("%" . $widthTotal . "s", ToolBox::numbers()::format($total - $paid)) . "\n\n";
    }

    /**
     * @param SalesDocument $doc
     * @param SalesDocumentLine[] $lines
     *
     * @return array
     */
    protected static function getSubtotals($doc, $lines): array
    {
        $subtotals = [];
        $eud = $doc->getEUDiscount();

        foreach ($lines as $line) {
            $key = $line->iva . '_' . $line->recargo;
            if (!isset($subtotals[$key])) {
                $subtotals[$key] = [
                    'tax' => $line->codimpuesto,
                    'taxp' => $line->iva . '%',
                    'taxbase' => 0,
                    'taxamount' => 0,
                    'taxsurcharge' => 0,
                    'taxsurchargep' => $line->recargo . '%',
                ];

                $impuesto = new Impuesto();
                if ($line->codimpuesto && $impuesto->loadFromCode($line->codimpuesto)) {
                    $subtotals[$key]['tax'] = $impuesto->descripcion;
                }
            }


            $subtotals[$key]['taxbase'] += $line->pvptotal * $eud;
            $subtotals[$key]['taxamount'] += $line->pvptotal * $eud * $line->iva / 100;
            $subtotals[$key]['taxsurcharge'] += $line->pvptotal * $eud * $line->recargo / 100;
        }

        return $subtotals;
    }

    protected static function sanitize(?string $txt): string
    {
        $changes = ['/à/' => 'a', '/á/' => 'a', '/â/' => 'a', '/ã/' => 'a', '/ä/' => 'a',
            '/å/' => 'a', '/æ/' => 'ae', '/ç/' => 'c', '/è/' => 'e', '/é/' => 'e', '/ê/' => 'e',
            '/ë/' => 'e', '/ì/' => 'i', '/í/' => 'i', '/î/' => 'i', '/ï/' => 'i', '/ð/' => 'd',
            '/ñ/' => 'n', '/ò/' => 'o', '/ó/' => 'o', '/ô/' => 'o', '/õ/' => 'o', '/ö/' => 'o',
            '/ő/' => 'o', '/ø/' => 'o', '/ù/' => 'u', '/ú/' => 'u', '/û/' => 'u', '/ü/' => 'u',
            '/ű/' => 'u', '/ý/' => 'y', '/þ/' => 'th', '/ÿ/' => 'y',
            '/&quot;/' => '-', '/´/' => '/\'/', '/€/' => 'EUR', '/º/' => '.',
            '/À/' => 'A', '/Á/' => 'A', '/Â/' => 'A', '/Ä/' => 'A',
            '/Ç/' => 'C', '/È/' => 'E', '/É/' => 'E', '/Ê/' => 'E',
            '/Ë/' => 'E', '/Ì/' => 'I', '/Í/' => 'I', '/Î/' => 'I', '/Ï/' => 'I',
            '/Ñ/' => 'N', '/Ò/' => 'O', '/Ó/' => 'O', '/Ô/' => 'O', '/Ö/' => 'O',
            '/Ù/' => 'U', '/Ú/' => 'U', '/Û/' => 'U', '/Ü/' => 'U',
            '/Ý/' => 'Y', '/Ÿ/' => 'Y'
        ];

        return preg_replace(array_keys($changes), $changes, $txt);
    }

    protected static function getTrazabilidad(SalesDocumentLine $line, int $width): string
    {
        $class = "\\FacturaScripts\\Dinamic\\Model\\ProductoLote";
        if (empty($line->referencia) || false === class_exists($class)) {
            return '';
        }

        // obtenemos los movimientos de trazabilidad de la línea
        $MovimientosTraza = $line->getMovimientosLinea();
        if (empty($MovimientosTraza)) {
            return '';
        }

        $numSeries = [];
        foreach ($MovimientosTraza as $movimientoTraza) {
            $numSeries[] = $movimientoTraza->numserie;
        }

        $result = '';
        $txtLine = '';
        foreach ($numSeries as $numserie) {
            // añadimos el numserie carácter por carácter
            // cuando llegamos al ancho máximo, añadimos un salto de línea
            // y continuamos con el mismo numserie hasta terminar con el
            // después continuamos con el siguiente numserie
            // separamos cada numserie con una coma
            $numserieLength = strlen($numserie);
            for ($i = 0; $i < $numserieLength; $i++) {
                if (strlen($txtLine) + 1 > $width) {
                    $result .= sprintf("%5s", '') . " "
                        . sprintf("%-" . $width . "s", $txtLine) . " "
                        . sprintf("%10s", '') . "\n";
                    $txtLine = '';
                }

                $txtLine .= $numserie[$i];
            }

            if (strlen($txtLine) + 2 > $width) {
                $result .= sprintf("%5s", '') . " "
                    . sprintf("%-" . $width . "s", $txtLine) . " "
                    . sprintf("%10s", '') . "\n";
                $txtLine = ', ';
                continue;
            }

            $txtLine .= ', ';
        }

        // comprobamos si los 2 últimos caracteres son una coma y un espacio
        // si es así, los eliminamos
        if (substr($txtLine, -2) === ', ') {
            $txtLine = substr($txtLine, 0, -2);
        }

        if (empty($txtLine)) {
            return '';
        }

        return $result . sprintf("%5s", '') . " "
            . sprintf("%-" . $width . "s", $txtLine) . " "
            . sprintf("%10s", '') . "\n";
    }

    protected static function printLines(TicketPrinter $printer, Printer $escpos, array $lines): void
    {
        $i18n = ToolBox::i18n();
        $width = $printer->linelen - 17;

        $text = sprintf("%5s", $i18n->trans('quantity-abb')) . " "
            . sprintf("%-" . $width . "s", $i18n->trans('description')) . " ";

        if ($printer->print_lines_net) {
            $text .= sprintf("%11s", $i18n->trans('net'));
        } else {
            $text .= sprintf("%11s", $i18n->trans('total'));
        }

        $escpos->text(static::sanitize($text) . "\n");
        $escpos->text($printer->getDashLine() . "\n");

        foreach ($lines as $line) {
            $description = mb_substr($line->descripcion, 0, $width);
            $text = sprintf("%5s", $line->cantidad) . " "
                . sprintf("%-" . $width . "s", $description) . " ";

            if ($printer->print_lines_price) {
                $price = $printer->print_lines_net ?
                    $line->pvpunitario :
                    $line->pvpunitario * (100 + $line->iva + $line->recargo) / 100;

                $text .= "\n" . sprintf("%5s", '') . " "
                    . sprintf("%-" . $width . "s", $i18n->trans('price') . ': ' . ToolBox::numbers()::format($price)) . " ";
            }

            if ($printer->print_lines_net) {
                $text .= sprintf("%10s", ToolBox::numbers()::format($line->pvptotal));
            } else {
                $total = $line->pvptotal * (100 + $line->iva + $line->recargo) / 100;
                $text .= sprintf("%10s", ToolBox::numbers()::format($total));
            }

            $escpos->text(static::sanitize($text) . "\n");
            $escpos->text(static::getTrazabilidad($line, $width));
        }
        $escpos->text($printer->getDashLine() . "\n");
    }
}