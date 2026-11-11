<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Services\ClientStatementPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Staff download of a client statement (P8.4): the in-window PDF of everything a client was
 * invoiced and paid in a period. Invoices permission — it is money data.
 */
final class ClientStatementController
{
    public function __invoke(Request $request, Contact $contact, ClientStatementPdf $pdf): Response
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        return response($pdf->render($contact, $from !== '' ? $from : null, $to !== '' ? $to : null), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.ClientStatementPdf::filename($contact).'"',
        ]);
    }
}
