# Infraestrutura e Custos — API WhatsApp/NFS-e

Documento para alinhamento comercial (itens 3 e 4 do Ricardo).

## Situação atual
- A API roda hoje em `https://nfse.teresinasoft.com.br`, servidor do fornecedor
  (TeresinaSoft), com Apache + MariaDB + worker de fila e cron do scheduler.
- HTTPS, backup e operação são mantidos pelo fornecedor durante o desenvolvimento.

## Opções de infraestrutura
1. **Permanece no servidor do fornecedor** (curto prazo / homologação)
   - Custo mensal compartilhado (hospedagem + domínio + operação).
   - Sem SLA formal; melhor esforço. Indicado enquanto o piloto roda.
2. **Migra para infraestrutura do cliente** (recomendado para produção)
   - O cliente contrata o servidor (VPS) e o domínio (ex.: `nfse.suaempresa.com.br`).
   - Fazemos o deploy (código no repositório do cliente) e a configuração.
   - O cliente passa a controlar custo, dados e backup; opcional contrato de
     manutenção/SLA com o fornecedor.

## Requisitos de servidor (mínimo)
- 2 vCPU, 2–4 GB RAM, 20+ GB disco, PHP 8.2+, MariaDB/MySQL, Composer.
- Worker de fila supervisionado (systemd) + cron do scheduler.
- HTTPS (Let's Encrypt) e backup diário do banco.

## Custos estimados (referência, a confirmar)
- VPS 2 vCPU/4 GB: faixa típica de mercado (BRL/mês).
- Domínio: ~R$ 40/ano.
- Provedor fiscal Asaas: conforme plano/nota (ver `COMPARATIVO_PROVEDORES_FISCAIS.md`).
- Meta/WhatsApp: tarifas por conversa (Cloud API).
- Manutenção/SLA (opcional): a combinar.

## Migração
1. Cliente cria o repositório (ex.: `rffirmino/nfse-api`) e concede acesso.
2. Publicamos o código (já preparado como repositório próprio).
3. Deploy no servidor do cliente (README_DEPLOY), migração do banco e configuração
   do domínio/webhooks.
4. Janela de corte: subir o novo ambiente, apontar webhooks (Meta/Asaas) e DNS,
   manter o antigo em stand-by por alguns dias.

## Exportação e guarda legal (item 4)
- Endpoint: `GET /api/v1/fiscal/export?format=json|csv&from=&to=&establishment_external_id=`
  (notas + configurações; XML e URL do documento quando disponíveis).
- Comando no servidor: `php artisan fiscal:export --path=... --establishment=...`
  (gera um JSON completo para arquivamento).
- Recomendação: rotina diária/semanal de exportação + backup do banco e dos XMLs.
