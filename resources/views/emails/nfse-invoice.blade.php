Olá{{ $invoice->customer_name ? ' ' . $invoice->customer_name : '' }}!

Sua NFS-e nº {{ $invoice->invoice_number ?? '-' }} foi emitida no valor de R$ {{ number_format((float) $invoice->amount, 2, ',', '.') }}.

@if($documentUrl)
Você pode acessar a nota por este endereço:
{{ $documentUrl }}
@endif

@if($xml)
O XML da nota segue em anexo.
@endif

Este é um e-mail automático. Não é necessário responder.

{{ config('mail.from.name', config('app.name')) }}