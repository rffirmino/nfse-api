<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Gerencia credenciais de consumidores da API (client_id + segredo).
 * Uso pelo próprio operador, sem depender de terceiros:
 *   php artisan client:credentials list
 *   php artisan client:credentials create agendamentos --label="GTR Agendamentos"
 *   php artisan client:credentials rotate agendamentos
 */
class ClientCredentials extends Command
{
    protected $signature = 'client:credentials {action : create|rotate|list} {client_id?} {--label=}';

    protected $description = 'Cria, rotaciona ou lista credenciais (client_id/segredo) de consumidores da API';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listar(),
            'create' => $this->criar(),
            'rotate' => $this->rotacionar(),
            default => $this->erro('Ação inválida. Use create, rotate ou list.'),
        };
    }

    private function listar(): int
    {
        $clients = ApiClient::orderBy('client_id')->get(['client_id', 'label', 'active', 'created_at']);
        $this->table(['client_id', 'label', 'active', 'created_at'], $clients->toArray());

        return self::SUCCESS;
    }

    private function criar(): int
    {
        $clientId = (string) $this->argument('client_id');
        if ($clientId === '') {
            return $this->erro('Informe o client_id.');
        }
        if (ApiClient::where('client_id', $clientId)->exists()) {
            return $this->erro('client_id já existe. Use rotate para trocar o segredo.');
        }

        $secret = Str::random(64);
        ApiClient::create([
            'client_id' => $clientId,
            'secret_enc' => $secret,
            'label' => $this->option('label') ?: null,
            'active' => true,
        ]);

        $this->info('Credencial criada. Guarde o segredo agora (não será exibido novamente):');
        $this->line('client_id: ' . $clientId);
        $this->line('secret:    ' . $secret);

        return self::SUCCESS;
    }

    private function rotacionar(): int
    {
        $clientId = (string) $this->argument('client_id');
        $client = ApiClient::where('client_id', $clientId)->first();
        if ($client === null) {
            return $this->erro('client_id não encontrado.');
        }

        $secret = Str::random(64);
        $client->secret_enc = $secret;
        $client->save();

        $this->info('Segredo rotacionado. Guarde agora (não será exibido novamente):');
        $this->line('client_id: ' . $clientId);
        $this->line('secret:    ' . $secret);

        return self::SUCCESS;
    }

    private function erro(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
