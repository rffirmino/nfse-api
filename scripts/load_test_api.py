"""Teste de carga seguro da API (sem envios reais).

Usa POST /api/v1/invoices com emission_requested=false (registra not_requested,
sem chamar o provedor fiscal) para exercitar HMAC, banco e idempotência.

Config (env):
  API_BASE           https://nfse.teresinasoft.com.br
  HMAC_SECRET_FILE   arquivo com o segredo HMAC (client agendamentos)
  ESTABLISHMENT_ID   estabelecimento de teste (ex.: estabelecimento-teste)
  MUNICIPALITY_CODE  ex.: 2211001
  SERVICE_CODE       ex.: SERVICO_CONFIGURADO
  TOTAL              nº de requisições (default 100)
  CONCURRENCY        threads (default 5)
"""
import hashlib
import hmac
import json
import os
import statistics
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests

API = os.getenv("API_BASE", "https://nfse.teresinasoft.com.br")
SECRET = open(os.getenv("HMAC_SECRET_FILE", ""), encoding="ascii").read().strip()
CLIENT = "agendamentos"
ESTAB = os.getenv("ESTABLISHMENT_ID", "estabelecimento-teste")
MUNICIPALITY = os.getenv("MUNICIPALITY_CODE", "2211001")
SERVICE = os.getenv("SERVICE_CODE", "SERVICO_CONFIGURADO")
TOTAL = int(os.getenv("TOTAL", "100"))
CONCURRENCY = int(os.getenv("CONCURRENCY", "5"))


def post_invoice(i):
    payload = {
        "establishment_external_id": ESTAB,
        "municipality_code": MUNICIPALITY,
        "service_code": SERVICE,
        "appointment_external_id": f"load-{i}-{uuid.uuid4().hex[:8]}",
        "customer_external_id": f"cli-{i}",
        "amount": 10.00,
        "emission_requested": False,
    }
    body = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
    ts = str(int(time.time()))
    nonce = uuid.uuid4().hex
    sig = "sha256=" + hmac.new(SECRET.encode(), f"{ts}\n{nonce}\n{body}".encode(),
                               hashlib.sha256).hexdigest()
    start = time.time()
    try:
        r = requests.post(f"{API}/api/v1/invoices", data=body.encode(), headers={
            "Content-Type": "application/json", "Accept": "application/json",
            "X-Client-Id": CLIENT, "X-Timestamp": ts, "X-Nonce": nonce,
            "X-Signature": sig, "Idempotency-Key": f"load:{uuid.uuid4().hex}",
        }, timeout=30)
        return r.status_code, time.time() - start
    except Exception as exc:
        return type(exc).__name__, time.time() - start


def main():
    latencies, statuses = [], {}
    t0 = time.time()
    with ThreadPoolExecutor(max_workers=CONCURRENCY) as ex:
        futures = [ex.submit(post_invoice, i) for i in range(TOTAL)]
        for fut in as_completed(futures):
            status, dt = fut.result()
            statuses[status] = statuses.get(status, 0) + 1
            latencies.append(dt)
    total_time = time.time() - t0

    latencies.sort()
    p50 = latencies[len(latencies) // 2]
    p95 = latencies[int(len(latencies) * 0.95) - 1]
    print(f"total={TOTAL} concorrencia={CONCURRENCY} tempo={total_time:.1f}s "
          f"rps={TOTAL/total_time:.1f}")
    print(f"latencia p50={p50*1000:.0f}ms p95={p95*1000:.0f}ms")
    print("status:", statuses)


if __name__ == "__main__":
    main()
