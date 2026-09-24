<?php

namespace App\Http\Controllers;

use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use OpenApi\Attributes as OA;

/**
 * Exportação dos dados fiscais para guarda legal (notas, documentos e configurações).
 */
class FiscalExportController extends Controller
{
    #[OA\Get(
        path: '/api/v1/fiscal/export',
        tags: ['Fiscal'],
        summary: 'Exporta notas fiscais e configurações (guarda legal)',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv'], example: 'json')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'establishment_external_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Dados exportados'), new OA\Response(response: 401, description: 'Assinatura inválida')],
    )]
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $invoices = $this->invoicesQuery($request)
            ->orderBy('created_at')
            ->get([
                'id', 'establishment_external_id', 'appointment_external_id', 'customer_external_id',
                'provider', 'external_id', 'status', 'invoice_number', 'series', 'access_key',
                'protocol', 'amount', 'tax_amount', 'document_url', 'xml', 'error_message',
                'issued_at', 'cancelled_at', 'created_at',
            ]);

        $configurations = FiscalConfiguration::query()
            ->when($request->query('establishment_external_id'), fn ($q, $v) => $q->where('establishment_external_id', $v))
            ->get([
                'establishment_external_id', 'municipality_code', 'municipality_name', 'provider',
                'environment', 'provider_registration', 'tax_regime', 'service_code', 'cnae',
                'nbs', 'iss_rate', 'active',
            ]);

        if ($request->query('format') === 'csv') {
            $header = ['id', 'establishment_external_id', 'status', 'invoice_number', 'access_key', 'protocol', 'amount', 'issued_at', 'cancelled_at', 'document_url'];
            $lines = [implode(';', $header)];
            foreach ($invoices as $invoice) {
                $lines[] = implode(';', array_map(fn ($f) => '"' . str_replace('"', '""', (string) $invoice->{$f}) . '"', $header));
            }

            return response(implode("\n", $lines), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="nfse-export.csv"',
            ]);
        }

        return response()->json([
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'invoices' => $invoices,
            'configurations' => $configurations,
        ]);
    }

    private function invoicesQuery(Request $request)
    {
        return NfseInvoice::query()
            ->when($request->query('establishment_external_id'), fn ($q, $v) => $q->where('establishment_external_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'));
    }
}
